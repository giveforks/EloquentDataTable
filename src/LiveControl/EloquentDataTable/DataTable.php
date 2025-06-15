<?php
namespace LiveControl\EloquentDataTable;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression as raw;
use Illuminate\Support\Str;
use LiveControl\EloquentDataTable\VersionTransformers\Version110Transformer;
use LiveControl\EloquentDataTable\VersionTransformers\VersionTransformerContract;
use Throwable;


class DataTable
{
    protected $builder;
    protected $columns;
    protected $formatRowFunction;
    protected $withApproximateCount = false;

    /**
     * @var VersionTransformerContract
     */
    protected static $versionTransformer;

    protected $rawColumns;
    protected $columnNames;

    protected $total = 0;
    protected $filtered = 0;
    protected $isApproximateCount = false;
    protected $countThreshold = 10000;

    protected $rows = [];

    /**
     * @param Builder|Model $builder
     * @param array $columns
     * @param null|callable $formatRowFunction
     * @throws Exception
     */
    public function __construct($builder, $columns, $formatRowFunction = null)
    {
        $this->setBuilder($builder);
        $this->setColumns($columns);

        if ($formatRowFunction !== null) {
            $this->setFormatRowFunction($formatRowFunction);
        }
    }

    /**
     * @param Builder|Model $builder
     * @return $this
     * @throws Exception
     */
    public function setBuilder($builder)
    {
        if ( ! ($builder instanceof Builder || $builder instanceof Model)) {
            throw new Exception('$builder variable is not an instance of Builder or Model.');
        }

        $this->builder = $builder;
        return $this;
    }

    /**
     * @param mixed $columns
     * @return $this
     */
    public function setColumns($columns)
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * @param callable $function
     * @return $this
     */
    public function setFormatRowFunction($function)
    {
        $this->formatRowFunction = $function;
        return $this;
    }

    /**
     * @param VersionTransformerContract $versionTransformer
     * @return $this
     */
    public function setVersionTransformer(VersionTransformerContract $versionTransformer)
    {
        static::$versionTransformer = $versionTransformer;
        return $this;
    }

    public function setCountThreshold(int $threshold): self
    {
        $this->countThreshold = $threshold;

        return $this;
    }

    /**
     * Make the datatable response.
     * @return array
     * @throws Exception
     */
    public function make()
    {
        $this->rawColumns = $this->getRawColumns($this->columns);
        $this->columnNames = $this->getColumnNames();

        $this->addSelect();

        $this->total = $this->count();

        if (static::$versionTransformer === null) {
            static::$versionTransformer = new Version110Transformer();
        }
        $this->addFilters();

        $this->filtered = $this->count();

        $this->addOrderBy();
        $this->addLimits();

        $this->rows = $this->builder->get();

        $rows = [];
        foreach ($this->rows as $row) {
            $rows[] = $this->formatRow($row);
        }

        return [
            static::$versionTransformer->transform('draw') => (isset($_POST[static::$versionTransformer->transform(
                    'draw'
                )]) ? (int)$_POST[static::$versionTransformer->transform('draw')] : 0),
            static::$versionTransformer->transform('recordsTotal') => $this->total,
            static::$versionTransformer->transform('recordsFiltered') => $this->filtered,
            static::$versionTransformer->transform('data') => $rows,
            'isApproximateCount' => $this->isApproximateCount
        ];
    }

    protected function count()
    {
        $approximateCount = $this->getApproximateCount();

        if ($approximateCount !== null) {
            $this->isApproximateCount = true;

            return $approximateCount;
        }

        $query = (method_exists($this->builder, 'getQuery') ? $this->builder->getQuery() : $this->builder);
        $connection = $query->getConnection();

        try {
            $result = $connection->select(
                'SELECT count(*) as totalCount FROM ('.$this->builder->toSql().') subQuery',
                $this->builder->getBindings()
            );
        } catch (Exception $e) {
            $result = $connection->select(
                'SELECT count(*) as totalCount FROM ('.$query->toSql().') subQuery',
                $query->getBindings()
            );
        }

        return $result[0]->totalCount ?? 0;
    }

    public function withApproximateCount(bool $withApproximateCount = true): self
    {
        $this->withApproximateCount = $withApproximateCount;

        return $this;
    }

    protected function getApproximateCount(): ?int
    {
        if (! $this->withApproximateCount || $this->getDatabaseDriver() !== 'mysql') {
            return null;
        }

        try {
            $exists = (clone $this->builder)
                ->limit($this->countThreshold + 1)
                ->skip($this->countThreshold)
                ->exists();

            return $exists ? $this->countThreshold : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param $data
     * @return array|mixed
     */
    protected function formatRow($data)
    {
        // if we have a custom format row function we trigger it instead of the default handling.
        if ($this->formatRowFunction !== null) {
            $function = $this->formatRowFunction;

            return call_user_func($function, $data);
        }

        $result = [];
        foreach ($this->columnNames as $column) {
            $result[] = $data[$column];
        }
        $data = $result;

        $data = $this->formatRowIndexes($data);

        return $data;
    }

    /**
     * @param $data
     * @return mixed
     */
    protected function formatRowIndexes($data)
    {
        if (isset($data['id'])) {
            $data[static::$versionTransformer->transform('DT_RowId')] = $data['id'];
        }
        return $data;
    }

    /**
     * @return array
     */
    protected function getColumnNames()
    {
        $names = [];
        foreach ($this->columns as $index => $column) {
            if ($column instanceof ExpressionWithName) {
                $names[] = $column->getName();
                continue;
            }

            if (is_string($column) && strstr($column, '.')) {
                $column = explode('.', $column);
            }

            $names[] = (is_array($column) ? $this->arrayToCamelcase($column) : $column);
        }
        return $names;
    }

    /**
     * @param $columns
     * @return array
     */
    protected function getRawColumns($columns)
    {
        $rawColumns = [];
        foreach ($columns as $column) {
            $rawColumns[] = $this->getRawColumnQuery($column);
        }
        return $rawColumns;
    }

    /**
     * @param $column
     * @return raw|string
     */
    protected function getRawColumnQuery($column)
    {
        if ($column instanceof ExpressionWithName) {
            return $column->getExpression();
        }

        if (is_array($column)) {
            if ($this->getDatabaseDriver() == 'sqlite') {
                return '(' . implode(' || " " || ', $this->getRawColumns($column)) . ')';
            }
            return 'CONCAT(' . implode(', " ", ', $this->getRawColumns($column)) . ')';
        }

        return Model::resolveConnection()->getQueryGrammar()->wrap($column);
    }

    /**
     * @return string
     */
    protected function getDatabaseDriver()
    {
        return Model::resolveConnection()->getDriverName();
    }

    /**
     *
     */
    protected function addSelect()
    {
        $rawSelect = [];
        $queryGrammar = Model::resolveConnection()->getQueryGrammar();
        foreach ($this->columns as $index => $column) {
            if (isset($this->rawColumns[$index])) {
                $rawColumn = $this->rawColumns[$index];
                if ($rawColumn instanceof ExpressionWithName) {
                    $rawColumn = $rawColumn->getExpression();
                }
                if ($rawColumn instanceof raw) {
                    $rawColumn = $rawColumn->getValue($queryGrammar);
                }
                $rawSelect[] = $rawColumn . ' as ' . $queryGrammar->wrap($this->columnNames[$index]);
            }
        }
        $this->builder = $this->builder->addSelect(new raw(implode(', ', $rawSelect)));
    }

    /**
     * @param $array
     * @param bool|false $inForeach
     * @return array|string
     */
    protected function arrayToCamelcase($array, $inForeach = false)
    {
        $result = [];
        foreach ($array as $value) {
            if (is_array($value)) {
                $result += $this->arrayToCamelcase($value, true);
            }
            $value = explode('.', $value);
            $value = end($value);
            $result[] = $value;
        }

        return (! $inForeach ? Str::camel(implode('_', $result)) : $result);
    }

    /**
     * Add the filters based on the search value given.
     * @return $this
     */
    protected function addFilters()
    {
        $search = static::$versionTransformer->getSearchValue();
        if ($search != '') {
            $this->addAllFilter($search);
        }
        $this->addColumnFilters();
        return $this;
    }

    /**
     * Searches in all the columns.
     * @param $search
     */
    protected function addAllFilter($search)
    {
        $this->builder = $this->builder->where(
            function ($query) use ($search) {
                foreach ($this->columns as $column) {
                    $query->orWhere(
                        new raw($this->getRawColumnQuery($column)),
                        'like',
                        '%' . $search . '%'
                    );
                }
            }
        );
    }

    /**
     * Add column specific filters.
     */
    protected function addColumnFilters()
    {
        foreach ($this->columns as $i => $column) {
            if (static::$versionTransformer->isColumnSearched($i)) {
                $this->builder->where(
                    new raw($this->getRawColumnQuery($column)),
                    'like',
                    '%' . static::$versionTransformer->getColumnSearchValue($i) . '%'
                );
            }
        }
    }

    /**
     * Depending on the sorted column this will add orderBy to the builder.
     */
    protected function addOrderBy()
    {
        if (static::$versionTransformer->isOrdered()) {
            foreach (static::$versionTransformer->getOrderedColumns() as $index => $direction) {
                if (isset($this->columnNames[$index])) {
                    $this->builder->orderBy(
                        $this->columnNames[$index],
                        $direction
                    );
                }
            }
        }
    }

    /**
     * Adds the pagination limits to the builder
     */
    protected function addLimits()
    {
        if (isset($_POST[static::$versionTransformer->transform(
                    'start'
                )]) && $_POST[static::$versionTransformer->transform('length')] != '-1'
        ) {
            $this->builder->skip((int)$_POST[static::$versionTransformer->transform('start')])->take(
                (int)$_POST[static::$versionTransformer->transform('length')]
            );
        }
    }
}
