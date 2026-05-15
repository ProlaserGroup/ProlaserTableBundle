<?php

namespace Kilik\TableBundle\Services;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Kilik\TableBundle\Components\Filter;
use Kilik\TableBundle\Components\Table;
use Kilik\TableBundle\Components\TableInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class TableService extends AbstractTableService
{
    public const TOTAL_PREFIX = 'TOTAL_';

    private CacheInterface $cache;

    public function setCache(CacheInterface $cache): void
    {
        $this->cache = $cache;
    }

    private function getCache(): CacheInterface
    {
        if (!isset($this->cache)) {
            $this->cache = ApcuAdapter::isSupported() ? new ApcuAdapter('kilik_table', 30) : new NullAdapter();
        }

        return $this->cache;
    }

    /**
     * @param Table        $table
     * @param Request      $request
     * @param QueryBuilder $queryBuilder
     */
    private function addSearch(Table $table, Request $request, QueryBuilder $queryBuilder)
    {
        $queryParams = $request->query->all($table->getFormId()) ?: $request->request->all($table->getFormId()) ?: [];

        foreach ($table->getAllFilters() as $filter) {
            if (!isset($queryParams[$filter->getName()])) {
                continue;
            }
            // Multiple inputs
            if (is_array($queryParams[$filter->getName()])) {
                $searchParamRaw = array_map('trim', $queryParams[$filter->getName()]);
                if (is_callable($filter->getQueryPartBuilder())) {
                    $callback = $filter->getQueryPartBuilder();
                    $callback($filter, $table, $queryBuilder, $searchParamRaw);
                }
                continue;
            }

            $searchParamRaw = trim($queryParams[$filter->getName()]);

            // query build callback
            if (is_callable($filter->getQueryPartBuilder())) {
                $callback = $filter->getQueryPartBuilder();
                $callback($filter, $table, $queryBuilder, $searchParamRaw);
                continue;
            }

            list($operator, $searchParam) = $filter->getOperatorAndValue($searchParamRaw);
            if ('' === (string)$searchParam) {
                continue;
            }

            list($searchOperator, $formattedSearch) = $filter->getFormattedInput($operator, $searchParam);

            // depending on operator
            switch ($searchOperator) {
                case Filter::TYPE_GREATER:
                case Filter::TYPE_GREATER_OR_EQUAL:
                case Filter::TYPE_LESS:
                case Filter::TYPE_LESS_OR_EQUAL:
                case Filter::TYPE_NOT_EQUAL:
                $sql = $this->buildMultiFieldSql($filter, "%s {$searchOperator} :filter_" . $filter->getName());
                    $queryBuilder->setParameter('filter_'.$filter->getName(), $formattedSearch);
                    break;
                case Filter::TYPE_EQUAL_STRICT:
                    $sql = $this->buildMultiFieldSql($filter, '%s = :filter_' . $filter->getName());
                    $queryBuilder->setParameter('filter_'.$filter->getName(), $formattedSearch);
                    break;
                case Filter::TYPE_EQUAL:
                    $sql = $this->buildMultiFieldSql($filter, '%s like :filter_' . $filter->getName());
                    $queryBuilder->setParameter('filter_'.$filter->getName(), $formattedSearch);
                    break;
                case Filter::TYPE_NOT_LIKE:
                    $sql = $this->buildMultiFieldSql($filter, '%s not like :filter_' . $filter->getName());
                    $queryBuilder->setParameter('filter_'.$filter->getName(), '%'.$formattedSearch.'%');
                    break;
                case Filter::TYPE_NULL:
                    $sql = $this->buildMultiFieldSql($filter, '%s IS NULL');
                    break;
                case Filter::TYPE_NOT_NULL:
                    $sql = $this->buildMultiFieldSql($filter, '%s IS NOT NULL');
                    break;
                case Filter::TYPE_IN:
                    $sql = $this->buildMultiFieldSql($filter, '%s IN (:filter_' . $filter->getName() . ')');
                    // $formattedSearch is like 'new,cancelled'
                    $values = is_array($formattedSearch) ? $formattedSearch : explode(',', $formattedSearch);
                    $queryBuilder->setParameter('filter_'.$filter->getName(), $values);
                    break;
                case Filter::TYPE_NOT_IN:
                    $sql = $this->buildMultiFieldSql($filter, '%s NOT IN (:filter_' . $filter->getName() . ')');
                    // $formattedSearch is like 'new,cancelled'
                    $values = is_array($formattedSearch) ? $formattedSearch : explode(',', $formattedSearch);
                    $queryBuilder->setParameter('filter_'.$filter->getName(), $values);
                    break;
                // when filtering on 'description LIKE WORDS "house red blue"'
                // results are: description LIKE '%house%' AND
                case Filter::TYPE_LIKE_WORDS_AND:
                case Filter::TYPE_LIKE_WORDS_OR:
                    $binaryOperator =  Filter::TYPE_LIKE_WORDS_OR == $searchOperator ? 'OR' : 'AND';
                    $words = array_filter(array_map('trim', explode(' ', trim($formattedSearch))));
                    if (empty($words)) {
                        break;
                    }
                    $sql = '(';
                    foreach ($words as $i => $word) {
                        if ($i > 0) {
                            $sql .= ' '.$binaryOperator.' '; // AND / OR
                        }
                        $termKey = 'filter_'.$filter->getName().'_t'.$i;
                        $sql .= $filter->getField().' like :'.$termKey;
                        $queryBuilder->setParameter($termKey, '%'.$word.'%');
                    }
                    $sql .= ')';
                    break;
                default:
                case Filter::TYPE_LIKE:
                $sql = $this->buildMultiFieldSql($filter, '%s like :filter_' . $filter->getName(), $queryBuilder, $formattedSearch);
                if ($sql) {
                    $queryBuilder->setParameter('filter_' . $filter->getName(), '%' . $formattedSearch . '%');
                }
                    break;
            }

            if (!$sql) {
                continue;
            }

            $filter->getHaving() ? $queryBuilder->andHaving($sql) : $queryBuilder->andWhere($sql);
        }
    }

    /**
     * Set total rows count without filters.
     * Result is cached for 30 seconds keyed on the table id + base DQL to avoid a
     * COUNT query on every AJAX pagination request when the data is stable.
     *
     * @param Table $table
     */
    protected function setTotalRows(Table $table)
    {
        $qb = $table->getQueryBuilder();
        $identifiers = $table->getIdentifierFieldNames();

        $cacheKey = 'tbl_total_' . md5($table->getId() . $qb->getDQL());

        $count = $this->getCache()->get($cacheKey, function (ItemInterface $item) use ($qb, $identifiers) {
            $item->expiresAfter(30);
            return $this->countRows(clone $qb, $identifiers);
        });

        $table->setTotalRows($count);
    }

    /**
     * Set total rows count with filters.
     *
     * @param Table   $table
     * @param Request $request
     */
    private function setFilteredRows(Table $table, Request $request)
    {
        $qb = $table->getQueryBuilder();
        $qbfr = clone $qb;
        $this->addSearch($table, $request, $qbfr);

        $identifiers = $table->getIdentifierFieldNames();
        $count = $this->countRows($qbfr, $identifiers);

        $table->setFilteredRows($count);
    }

    /**
     * {@inheritdoc}
     */
    public function getRows(TableInterface $table, Request $request, $paginate = true, $getObjects = true)
    {
        $table->setRowsPerPage($request->request->get('rowsPerPage') ?? $request->query->get('rowsPerPage') ?? 10);
        $table->setPage($request->request->get('page') ?? $request->query->get('page') ?? 1);

        foreach ($request->query->all('hiddenColumns') ?: $request->request->all('hiddenColumns') as $hiddenColumnName => $notUsed) {
            $column = $table->getColumnByName($hiddenColumnName);
            if (!is_null($column)) {
                $column->setHidden(true);
            }
        }

        $qb = $table->getQueryBuilder();

        if ($paginate) {
            // @todo: had possibility to define custom count queries
            $this->setTotalRows($table);
            $this->setFilteredRows($table, $request);

            // compute last page and floor curent page
            $table->setLastPage(ceil($table->getFilteredRows() / $table->getRowsPerPage()));

            if ($table->getPage() > $table->getLastPage()) {
                $table->setPage($table->getLastPage());
            }

            $qb->setMaxResults($table->getRowsPerPage());
            $qb->setFirstResult(($table->getPage() - 1) * $table->getRowsPerPage());
        }

        // add filters
        $this->addSearch($table, $request, $qb);

        // handle ordering
        $queryParams = $request->query->all($table->getFormId()) ?: $request->request->all($table->getFormId()) ?: [];

        if (isset($queryParams['sortColumn']) && $queryParams['sortColumn'] != '') {
            $column = $table->getColumnByName($queryParams['sortColumn']);
            // if column exists
            if (!is_null($column)) {
                if (!is_null($column->getSort())) {
                    $qb->resetDQLPart('orderBy');
                    if (isset($queryParams['sortReverse'])) {
                        $sortReverse = $queryParams['sortReverse'];
                    } else {
                        $sortReverse = false;
                    }
                    foreach ($column->getAutoSort($sortReverse) as $sortField => $sortOrder) {
                        $qb->addOrderBy($sortField, $sortOrder);
                    }
                }
            }
        }

        // force a final ordering by identifier (skip if already in ORDER BY)
        $orderFields = $table->getIdentifierFieldNames() ?? $table->getAlias() . '.id';
        $existingOrderBy = [];
        foreach ($qb->getDQLPart('orderBy') as $orderBy) {
            foreach ($orderBy->getParts() as $part) {
                // each part looks like "i.itemCode ASC" — extract just the field name
                $existingOrderBy[] = strtolower(trim(preg_replace('/\s+(asc|desc)$/i', '', $part)));
            }
        }
        foreach (explode(',', $orderFields) as $orderField) {
            $orderField = trim($orderField);
            if (!in_array(strtolower($orderField), $existingOrderBy, true)) {
                $qb->addOrderBy($orderField, 'asc');
            }
        }

        if ($table->haveTotalColumns()) {
            $totalQueryBuilder = clone ($qb);
            $totalQueryBuilder->setMaxResults(null)->setFirstResult(null);
            foreach ($table->getColumns() as $column) {
                if ($column->isUseTotal()) {
                    $totalQueryBuilder->addSelect('SUM('.$column->getFilter()->getField(). ') AS ' . self::TOTAL_PREFIX.$column->getName());
                }
            }
            $totalResults = $totalQueryBuilder->getQuery()->getResult()[0] ?? [];
            foreach ($table->getColumns() as $column) {
                $totalColumnResultName = self::TOTAL_PREFIX.$column->getName();
                if ($column->isUseTotal()) {
                    $callback = $column->getDisplayCallback();
                    if (!is_null($callback)) {
                        if (!is_callable($callback)) {
                            throw new \Exception('displayCallback is not callable');
                        }
                        $column->setTotal($callback($totalResults[$totalColumnResultName]) ?? 0);
                    } else {
                        $column->setTotal($totalResults[$totalColumnResultName] ?? 0);
                    }
                }
            }
        }

        $query = $qb->getQuery();

        // if we need to get objects, LEGACY mode
        if ($getObjects && $table->getEntityLoaderMode() == $table::ENTITY_LOADER_LEGACY) {
            if (!is_null($qb->getDQLPart('groupBy'))) {
                // results as objects
                $objects = [];
                foreach ($query->getResult(Query::HYDRATE_OBJECT) as $object) {
                    if (is_object($object)) {
                        $index = is_int($object->getId()) ? $object->getId() : (string) $object->getId();
                        $objects[$index] = $object;
                    } // when results are mixed with objects and scalar
                    elseif (isset($object[0]) && is_object($object[0])) {
                        $index = is_int($object[0]->getId()) ? $object[0]->getId() : (string) $object[0]->getId();
                        $objects[$index] = $object[0];
                    }
                }
            }
        }

        $rows = $query->getResult(Query::HYDRATE_SCALAR);

        // if we need to get objects
        if ($getObjects && in_array($table->getEntityLoaderMode(), [$table::ENTITY_LOADER_REPOSITORY, $table::ENTITY_LOADER_CALLBACK])) {
            // create entities identifiers list from scalar rows
            $identifiers = [];
            // results as scalar
            foreach ($rows as $row) {
                // add row identifier to array
                $identifiers[] = $row[$this->getScalarIdentifierKey($table)];
            }

            // if at least one identifier should be used to load entities
            if (count($identifiers) > 0) {

                // loaded entities
                $entities = $this->loadRowsById($table, $identifiers);

                // associate objects to rows
                if (count($entities) > 0) {
                    foreach ($rows as &$row) {
                        $row['object'] = null;
                        foreach ($entities as $entity) {
                            if ($row[$this->getScalarIdentifierKey($table)] == $entity->getId()) {
                                $row['object'] = $entity;
                                break;
                            }
                        }
                    }
                }
            }
        }

        // if we need to get objects (legacy mode)
        if ($getObjects && $table->getEntityLoaderMode() == $table::ENTITY_LOADER_LEGACY) {
            // results as scalar
            foreach ($rows as &$row) {
                $index = is_int($row[$this->getScalarIdentifierKey($table)])
                    ? $row[$this->getScalarIdentifierKey($table)]
                    : (string)$row[$this->getScalarIdentifierKey($table)];

                if (isset($objects[$index])) {
                    $row['object'] = $objects[$index];
                }
            }
        }

        return $rows;
    }

    /**
     * @inheritdoc
     */
    public function loadRowsById(TableInterface $table, $identifiers)
    {
        $entities = [];
        // if we need to use repository name
        if ($table->getEntityLoaderMode() == $table::ENTITY_LOADER_REPOSITORY) {
            // if repository name is missing
            if (!$table->getEntityLoaderRepository()) {
                throw new \InvalidArgumentException('entity loader repository name is missing for ENTITY_LOADER_REPOSITORY mode');
            }

            // load entities from identifiers
            $loaderQueryBuilder = $table->getQueryBuilder()
                ->getEntityManager()
                ->getRepository($table->getEntityLoaderRepository())
                ->createQueryBuilder('e')
                ->select('e')
                ->where('e.id IN (:identifiers)')
                ->setParameter('identifiers', $identifiers);

            $entities = $loaderQueryBuilder->getQuery()->getResult();
        } elseif ($table->getEntityLoaderMode() == $table::ENTITY_LOADER_CALLBACK) {
            // if repository callback is missing
            if (!is_callable($table->getEntityLoaderCallback())) {
                throw new \InvalidArgumentException('entity loader callback is missing or not callable for ENTITY_LOADER_CALLBACK mode');
            }
            // else, load entities from callback method
            $callback = $table->getEntityLoaderCallback();
            $entities = $callback($identifiers);
        } else {
            throw new \InvalidArgumentException('unsupported entity loader mode');
        }

        return $entities;
    }

    /**
     * @param QueryBuilder $qb
     * @param string|null  $identifiers
     *
     * @return float|int
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    protected function countRows(QueryBuilder $qb, ?string $identifiers = null)
    {
        switch (true) {
            case $qb->getQuery()->hasHint(Query::HINT_CUSTOM_OUTPUT_WALKER) && is_null($identifiers):
                $em = $qb->getEntityManager();
                $sql = $qb->getQuery()->getSQL();
                $rsm = new Query\ResultSetMapping();
                $rsm->addScalarResult('dctrn_count', 'count');
                $nativeQuery = $em->createNativeQuery(sprintf('SELECT COUNT(*) AS dctrn_count FROM (%s) AS dctrn_table', $sql), $rsm);
                foreach ($qb->getParameters() as $item) {
                    $nativeQuery->setParameter($item->getName(), $item->getValue(), $item->getType());
                }

                return (int)$nativeQuery->getSingleScalarResult();
            case is_null($identifiers):
                $paginatorFiltered = new Paginator($qb->getQuery());

                return $paginatorFiltered->count();
            default:
                $qb->select($qb->expr()->count($identifiers));
                $qb->resetDQLPart('orderBy');

                return (int)$qb->getQuery()->getSingleScalarResult();
        }
    }

    /**
     * Build a SQL condition for a filter, supporting multiple fields with OR.
     * Use %s as placeholder for the field name in $template.
     *
     * When $queryBuilder and $rawValue are provided, integer-typed fields (via Filter::setFieldTypes)
     * use equality instead of LIKE:
     *  - Numeric values: field = :filter_name_int_N (integer parameter)
     *  - Non-numeric values: the integer field is skipped entirely
     *
     * Returns empty string when no valid condition can be built (e.g. all fields are integer
     * and the search value is non-numeric).
     *
     * Example: buildMultiFieldSql($filter, '%s like :filter_name')
     * With fields ['f1','f2'] produces: (f1 like :filter_name OR f2 like :filter_name)
     */
    protected function buildMultiFieldSql(Filter $filter, string $template, ?QueryBuilder $queryBuilder = null, string $rawValue = ''): string
    {
        $fields = $filter->getFields();
        $fieldTypes = $filter->getFieldTypes();

        if (empty($fieldTypes) || $queryBuilder === null) {
            // Backward-compatible path: no type info, use template for all fields
            if (count($fields) <= 1) {
                return sprintf($template, $filter->getField());
            }
            $parts = array_map(fn(string $f) => sprintf($template, $f), $fields);

            return '(' . implode(' OR ', $parts) . ')';
        }

        // Type-aware path
        if (count($fields) <= 1) {
            $type = $filter->getFieldType(0, $filter->getField() ?? '');
            if ($type === 'integer') {
                if (!is_numeric($rawValue)) {
                    return '';
                }
                $intParam = 'filter_' . $filter->getName() . '_int_0';
                $queryBuilder->setParameter($intParam, (int)$rawValue);

                return sprintf('%s = :%s', $filter->getField(), $intParam);
            }

            return sprintf($template, $filter->getField());
        }

        $parts = [];
        foreach ($fields as $i => $field) {
            $type = $filter->getFieldType($i, $field);
            if ($type === 'integer') {
                if (!is_numeric($rawValue)) {
                    continue; // non-numeric input cannot match an integer field
                }
                $intParam = 'filter_' . $filter->getName() . '_int_' . $i;
                $queryBuilder->setParameter($intParam, (int)$rawValue);
                $parts[] = "$field = :$intParam";
            } else {
                $parts[] = sprintf($template, $field);
            }
        }

        if (empty($parts)) {
            return '';
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Returns the scalar hydration key for the table's primary identifier.
     * e.g. 'i.itemCode' → 'i_itemCode', falls back to 'alias_id' if not set.
     */
    private function getScalarIdentifierKey(TableInterface $table): string
    {
        $identifierFieldNames = $table->getIdentifierFieldNames();
        if ($identifierFieldNames) {
            $first = trim(explode(',', $identifierFieldNames)[0]);
            return str_replace('.', '_', $first);
        }

        return $table->getAlias() . '_id';
    }
}
