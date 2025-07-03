<?php

namespace Geccomedia\Weclapp\Query\Grammars;

use Geccomedia\Weclapp\NotSupportedException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;

class Grammar extends BaseGrammar
{
    /**
     * Flag to indicate whether to ignore missing properties in API requests
     * @var bool
     */
    protected bool $ignoreMissingProperties = false;

    /**
     * Mapping table from normal eloquent operators to weclapp
     * @var array
     */
    protected array $operatorMappingTable = [
        '=' => 'eq',
        '!=' => 'ne',
        '>' => 'gt',
        '>=' => 'ge',
        '<' => 'lt',
        '<=' => 'le',
        'in' => 'in',
        'not_in' => 'notin',
        'null' => 'null',
        'not_null' => 'notnull',
        'like' => 'like',
        'not_like' => 'notlike',
        'ilike' => 'ilike',
        'not_ilike' => 'notilike',
    ];

    public function __construct()
    {
        $this->operators = array_keys($this->operatorMappingTable);
    }

    private function getOperator($operator)
    {
        return $this->operatorMappingTable[strtolower($operator)];
    }

    /**
     * The components that make up a select clause.
     *
     * @var array
     */
    protected $selectComponents = [
        'from',
        'aggregate',
        'columns',
        'wheres',
        'filterExpressions',
        'orders',
        'offset',
        'limit',
    ];

    /**
     * Convert an array of column names into a delimited string.
     *
     * @param  array $columns
     * @return string
     */
    public function columnize(array $columns): string
    {
        return implode(',', $columns);
    }

    /**
     * Compile a select query into SQL.
     *
     * @param Builder $query
     * @return Request
     */
    public function compileSelect(Builder $query): Request
    {
        // If the query does not have any columns set, we'll set the columns to the
        // * character to just get all of the columns from the database. Then we
        // can build the query and concatenate all the pieces together as one.
        $original = $query->columns;

        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        // To compile the query, we'll spin through each component of the query and
        // see if that component exists. If it does we'll just call the compiler
        // function for the component which is responsible for making the SQL.
        $components = $this->compileComponents($query);
        $baseUri = $components['from'];
        unset($components['from']);
        if (isset($components['aggregate'])) {
            $baseUri .= $components['aggregate'];
            unset($components['aggregate']);
        }

        $queryColumns = [];

        // Add wheres component if it exists
        if (isset($components['wheres'])) {
            $queryColumns = array_merge($queryColumns, $components['wheres']);
            unset($components['wheres']);
        }

        // Add other components
        foreach($components as $component) {
            if (is_array($component)) {
                if (isset($component[0]) && is_array($component[0])) {
                    // If component is already an array of arrays, merge it
                    $queryColumns = array_merge($queryColumns, $component);
                } else {
                    // If component is a single array, add it
                    $queryColumns[] = $component;
                }
            }
        }

        $queryParams = array_column($queryColumns, 1, 0);

        $query->columns = $original;

        return new Request('GET', Uri::withQueryValues(new Uri($baseUri), $queryParams));
    }

    /**
     * Compile the components necessary for a select clause.
     *
     * @param Builder $query
     * @return array
     */
    protected function compileComponents(Builder $query): array
    {
        $sql = [];

        foreach ($this->selectComponents as $component) {
            // To compile the query, we'll spin through each component of the query and
            // see if that component exists. If it does we'll just call the compiler
            // function for the component which is responsible for making the SQL.
            if (isset($query->$component) && !is_null($query->$component)) {
                $method = 'compile' . ucfirst($component);

                $part = $this->$method($query, $query->$component);
                if (!is_null($part)) {
                    $sql[$component] = $part;
                }
            }
        }

        return $sql;
    }

    /**
     * Compile an aggregated select clause.
     *
     * @param Builder $query
     * @param  array $aggregate
     * @return string
     */
    protected function compileAggregate(Builder $query, $aggregate): string
    {
        return '/count';
    }

    /**
     * Compile the "select *" portion of the query.
     *
     * @param Builder $query
     * @param  array $columns
     * @return array|void
     */
    protected function compileColumns(Builder $query, $columns)
    {
        // If the query is actually performing an aggregating select, we will let that
        // compiler handle the building of the select clauses, as it will need some
        // more syntax that is best handled by that function to keep things neat.
        if (!is_null($query->aggregate) || in_array('*', $columns)) {
            return;
        }
        return ['properties', $this->columnize($columns)];
    }

    /**
     * Compile the "from" portion of the query.
     *
     * @param Builder $query
     * @param  string $table
     * @return string
     */
    protected function compileFrom(Builder $query, $table): string
    {
        return $table;
    }

    /**
     * Compile the "where" portions of the query.
     *
     * @param Builder $query
     * @return array
     */
    public function compileWheres(Builder $query): array
    {
        return collect($query->wheres)->map(function ($where) use ($query) {
            if (!in_array($where['type'], ['In', 'NotIn', 'Null', 'NotNull', 'Entity'])) {
                $where['type'] = 'Basic';
            }

            // Prüfen, ob es sich um eine "orWhere"-Bedingung handelt
            $compiledWhere = $this->{"where{$where['type']}"}($query, $where);
            if (isset($where['boolean']) && $where['boolean'] === 'or') {
                $compiledWhere[0] = 'or-' . $compiledWhere[0]; // "or-" Präfix hinzufügen
            }

            return $compiledWhere;
        })->all();
    }

    /**
     * Compile the filter expressions for the query.
     *
     * @param Builder $query
     * @param array $filterExpressions
     * @return array
     */
    protected function compileFilterExpressions(Builder $query, array $filterExpressions): array
    {
        $result = [];

        foreach ($filterExpressions as $index => $expression) {
            // Use a unique key for each filter expression to avoid overwriting
            // when multiple filter expressions are used
            $key = 'filter';
            if ($index > 0) {
                $key = 'filter' . ($index + 1);
            }
            $result[] = [$key, urlencode($expression)];
        }

        return $result;
    }

    /**
     * Compile a basic where clause.
     *
     * @param Builder $query
     * @param  array $where
     * @return array
     */
    protected function whereBasic(Builder $query, $where): array
    {
        return [
            $where['column'] . '-' . $this->getOperator($where['operator']),
            $where['value']
        ];
    }

    /**
     * Compile a "where entity" clause.
     * See https://github.com/geccomedia/weclapp/issues/22
     *
     * @param Builder $query
     * @param array $where
     * @return array
     */
    protected function whereEntity(Builder $query, array $where): array
    {
        return [
            $where['column'],
            $where['value']
        ];
    }

    /**
     * Compile a "where null" clause.
     *
     * @param Builder $query
     * @param  array  $where
     * @return array
     */
    protected function whereNull(Builder $query, $where): array
    {
        $where['value'] = '';
        $where['operator'] = 'null';
        return $this->whereBasic($query, $where);
    }

    /**
     * Compile a "where not null" clause.
     *
     * @param Builder $query
     * @param  array  $where
     * @return array
     */
    protected function whereNotNull(Builder $query, $where): array
    {
        $where['value'] = '';
        $where['operator'] = 'not_null';
        return $this->whereBasic($query, $where);
    }

    /**
     * Compile a "where in" clause.
     *
     * @param Builder $query
     * @param  array $where
     * @return array
     */
    protected function whereIn(Builder $query, $where): array
    {
        $where['value'] = json_encode($where['values']);
        $where['operator'] = 'in';
        return $this->whereBasic($query, $where);
    }

    /**
     * Compile a "where not in" clause.
     *
     * @param Builder $query
     * @param  array $where
     * @return array
     */
    protected function whereNotIn(Builder $query, $where): array
    {
        $where['value'] = json_encode($where['values']);
        $where['operator'] = 'not_in';
        return $this->whereBasic($query, $where);
    }

    /**
     * Compile the "order by" portions of the query.
     *
     * @param Builder $query
     * @param  array $orders
     * @return array
     */
    protected function compileOrders(Builder $query, $orders): array
    {
        return [
            'sort',
            implode(',', $this->compileOrdersToArray($query, $orders))
        ];
    }

    /**
     * Compile the query orders to an array.
     *
     * @param Builder $query
     * @param  array $orders
     * @return array
     */
    protected function compileOrdersToArray(Builder $query, $orders): array
    {
        return array_map(function ($order) {
            return ($order['direction'] == 'desc' ? '-' : '') . $order['column'];
        }, $orders);
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param Builder $query
     * @param  int $limit
     * @return array
     */
    protected function compileLimit(Builder $query, $limit): array
    {
        return ['pageSize', (int)$limit];
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param Builder $query
     * @param  int $offset
     * @return array
     */
    protected function compileOffset(Builder $query, $offset): array
    {
        return ['page', (int)($offset / $query->limit + 1)];
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @param Builder $query
     * @param  array $values
     * @return Request
     */
    public function compileInsert(Builder $query, array $values): Request
    {
        return new Request('POST', $query->from, [], json_encode($values));
    }

    /**
     * Compile an insert and get ID statement into SQL.
     *
     * @param Builder $query
     * @param  array $values
     * @param  string $sequence
     * @return Request
     */
    public function compileInsertGetId(Builder $query, $values, $sequence): Request
    {
        return $this->compileInsert($query, $values);
    }

    /**
     * Compile a delete statement into SQL.
     *
     * @param Builder $query
     * @return Request
     * @throws NotSupportedException
     */
    public function compileDelete(Builder $query): Request
    {
        if (count($query->wheres) != 1 || $query->wheres[0]['column'] != 'id' || $query->wheres[0]['type'] != 'Basic') {
            throw new NotSupportedException('Only single delete by id is supported by weclapp.');
        }
        $key = array_shift($query->wheres);
        return new Request('DELETE', $query->from . '/' . $key['column'] . '/' . $key['value']);
    }

    /**
     * Compile an update statement into SQL.
     *
     * @param Builder $query
     * @param array $values
     * @return Request
     * @throws NotSupportedException
     */
    public function compileUpdate(Builder $query, array $values): Request
    {
        if (count($query->wheres) != 1 || $query->wheres[0]['column'] != 'id' || $query->wheres[0]['type'] != 'Basic') {
            throw new NotSupportedException('Only single update by id is supported by weclapp.');
        }
        $key = array_shift($query->wheres);

        $url = $query->from . '/' . $key['column'] . '/' . $key['value'];

        // Add ignoreMissingProperties parameter if set
        if ($this->ignoreMissingProperties) {
            $url .= '?ignoreMissingProperties=true';
        }

        return new Request('PUT', $url, [], json_encode($values));
    }

    /**
     * Prepare the bindings for an update statement.
     *
     * @param  array  $bindings
     * @param  array  $values
     * @return array
     */
    public function prepareBindingsForUpdate(array $bindings, array $values): array
    {
        return $values;
    }

    /**
     * Get the grammar specific operators.
     *
     * @return array
     */
    public function getOperators(): array
    {
        return $this->operators;
    }

    /**
     * Set whether to ignore missing properties in API requests
     *
     * @param bool $ignore
     * @return $this
     */
    public function setIgnoreMissingProperties(bool $ignore = true)
    {
        $this->ignoreMissingProperties = $ignore;
        return $this;
    }

    // @codeCoverageIgnoreStart
    public function supportsSavepoints(): bool
    {
        return false;
    }

    /**
     * @throws NotSupportedException
     */
    public function compileInsertUsing(Builder $query, array $columns, string $sql)
    {
        throw new NotSupportedException('Inserting using sub queries is not supported by weclapp.');
    }

    /**
     * @throws NotSupportedException
     */
    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        throw new NotSupportedException('Inserting while ignoring errors is not supported by weclapp.');
    }

    /**
     * @throws NotSupportedException
     */
    protected function compileJoins(Builder $query, $joins)
    {
        throw new NotSupportedException('Joins not supported by weclapp.');
    }
    // @codeCoverageIgnoreEnd
}
