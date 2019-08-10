<?php namespace DbSync;

use Database\Connection;
use Database\Query\Builder;
use Database\Query\Expression;
use Database\Query\Grammars\MySqlGrammar;

class Table {

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var string
     */
    private $database;

    /**
     * @var string
     */
    private $table;

    /**
     * @var Definition
     */
    private $definition;

    /**
     * @var WhereClause
     */
    private $where;

    /**
     * @var string
     */
    private $cacheWhereEnd;

    /**
     * @var string
     */
    private $cacheWhereStart;

    /**
     * @var string
     */
    private $cacheWhereNot;

    /**
     * @param Connection $connection
     * @param $database
     * @param $table
     */
    public function __construct(Connection $connection, $database, $table)
    {
        $this->connection = $connection;

        $this->database = $database;

        $this->table = $table;

        $this->configure();
    }

    /**
     * @param \PDO $pdo
     * @param $database
     * @param $table
     * @return static
     */
    public static function makeFromPdo(\PDO $pdo, $database, $table)
    {
        return new static(new Connection($pdo, new MySqlGrammar()), $database, $table);
    }

    /**
     * @param WhereClause $where
     */
    public function setWhereClause(WhereClause $where)
    {
        $this->where = $where;
    }

    /**
     *
     */
    private function configure()
    {
        $columns = $this->connection
            ->table('information_schema.columns')
            ->where([
                'table_schema' => $this->database,
                'table_name' => $this->table,
            ])->lists('column_name');


        $name = $this->connection->getQueryGrammar()->wrap($this->getQualifiedName());

        $rows = $this->connection->fetchAll("SHOW INDEX FROM $name WHERE `key_name` = 'PRIMARY'");

        $index = array_column($rows, 'Column_name', 'Seq_in_index');

        ksort($index);

        $this->definition = new Definition($columns, array_values($index));
    }

    /**
     * @param $position
     * @param array|null $lastKey
     * @return mixed|static
     */
    public function getKeyAtPosition(array $lastKey, $position)
    {
        if(!$primaryKey = $this->getPrimaryKey())
        {
            return null;
        }
        
        $query = $this->query()
            ->select($primaryKey)
            ->offset($position)
            ->limit(1);

        foreach($this->getPrimaryKey() as $keyCol)
        {
            $query->orderBy($keyCol);
        }

        $this->applyPrimaryKeyWhere($query, $lastKey);

        return $query->first();
    }

    /**
     * @param array $columns
     * @param $hash
     * @param array $startIndex
     * @param array $endIndex
     * @return mixed
     */
    public function getHashForKey(array $columns, $hash, array $startIndex, array $endIndex)
    {
        $subQuery = $this->query()->select($columns);

        $this->applyPrimaryKeyWhere($subQuery, $startIndex, $endIndex);

        $query = $subQuery->newQuery()->from(new Expression("({$subQuery->toSql()}) t"));

        $query->mergeBindings($subQuery);

        $hash = $query->pluck(new Expression($hash));

        return $hash;
    }

    /**
     * @param array $columns
     * @param array $startIndex
     * @param array $endIndex
     * @return array
     */
    public function getRowsForKey(array $columns, array $startIndex, array $endIndex)
    {
        $query = $this->query()->select($columns);

        $this->applyPrimaryKeyWhere($query, $startIndex, $endIndex);

        return $query->get();
    }

    /**
     * @param array $startIndex
     * @param array $endIndex
     * @param array $rows
     * @return int
     */
    public function delete(array $startIndex, array $endIndex, array $rows)
    {
        $query = $this->query();

        $this->applyPrimaryKeyWhere($query, $startIndex, $endIndex);

        $pk = array_flip($this->getPrimaryKey());

        foreach($rows as $row)
        {
            // Need to make sure the columns are in the same order as the primary key,
            // so we restrict the row to only contain columns that exist in the key,
            // then merge them back in to the pk array which ensures the correct order.
            $cols = array_merge($pk, array_intersect_key($row, $pk));

            $query->whereRaw($this->getWhereNot(), $cols);
        }

        return $query->delete()->rowCount();
    }

    /**
     * @param array $rows
     * @param array $columns
     * @return int
     */
    public function insert(array $rows, array $columns)
    {
        $update = [];

        foreach($columns as $column)
        {
            $update[$column] = new Expression("VALUES(`$column`)");
        }

        return $this->query()->insertOnDuplicateKeyUpdate($rows, $update)->rowCount();
    }

    /**
     * Queries the database to find the columns in the given table(s)
     *
     * @return array  An array containing the column names
     */
    public function getColumns()
    {
        return $this->definition->getColumns();
    }

    /**
     * @return array
     */
    public function getPrimaryKey()
    {
        return $this->definition->getPrimaryKey();
    }

    /**
     * @param Builder $query
     * @param array|null $startIndex
     * @param array|null $endIndex
     */
    private function applyPrimaryKeyWhere(Builder $query, array $startIndex, array $endIndex = null)
    {
        if($this->where)
        {
            $query->whereRaw($this->where->getWhere(), $this->where->getBindings());
        }

        $key = $this->getPrimaryKey();

        $compoundPrimary = count($key) > 1;

        if($startIndex)
        {
            $query->whereRaw($this->getWhereStart(), $startIndex);

            if($compoundPrimary)
            {
                // Optimisation to isolate all matching columns in index, plus a range query on the last non matching column - also works well for partition pruning
                foreach($startIndex as $column => $value)
                {
                    if(!$endIndex || $endIndex[$column] !== $value)
                    {
                        $query->where($column, '>=', $value);

                        if($endIndex)
                        {
                            $query->where($column, '<=', $endIndex[$column]);
                        }

                        break;
                    }

                    $query->where($column, $value);
                }
            }
        }

        if($endIndex)
        {
            $query->whereRaw($this->getWhereEnd(), $endIndex);

            // If we have start index this will already have been covered above
            if(!$startIndex && $compoundPrimary)
            {
                // Optimisation to isolate first item in index - also works well for partition pruning
                $query->where(reset($key), '<=', reset($endIndex));
            }
        }
    }

    private function getWhereNot()
    {
        $pk = $this->getPrimaryKey();

        return $this->cacheWhereNot ?: ($this->cacheWhereNot = "({$this->columnize($pk)}) <> ({$this->connection->getQueryGrammar()->parameterize($pk)})");
    }

    private function getWhereStart()
    {
        $pk = $this->getPrimaryKey();

        return $this->cacheWhereStart ?: ($this->cacheWhereStart = "({$this->columnize($pk)}) >= ({$this->connection->getQueryGrammar()->parameterize($pk)})");
    }

    private function getWhereEnd()
    {
        $pk = $this->getPrimaryKey();

        return $this->cacheWhereEnd ?: ($this->cacheWhereEnd = "({$this->columnize($pk)}) < ({$this->connection->getQueryGrammar()->parameterize($pk)})");
    }

    /**
     * @return Builder
     */
    private function query()
    {
        return $this->connection->table($this->getQualifiedName());
    }

    /**
     * @param array $columns
     * @return string
     */
    public function columnize(array $columns)
    {
        return $this->connection->getQueryGrammar()->columnize($columns);
    }

    /**
     * @return string
     */
    public function getQualifiedName()
    {
        return "$this->database.$this->table";
    }

    public function __toString()
    {
        return $this->getQualifiedName();
    }
}
