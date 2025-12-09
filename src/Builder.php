<?php

namespace Geccomedia\Weclapp;

use Geccomedia\Weclapp\Models\ArchivedEmail;
use Geccomedia\Weclapp\Models\Comment;
use Geccomedia\Weclapp\Models\Document;
use Geccomedia\Weclapp\Query\Builder as QueryBuilder;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder as BaseBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Builder extends BaseBuilder
{
    /**
     * List of models that the weclapp api does handle in a special way.
     * See https://github.com/geccomedia/weclapp/issues/22
     *
     * @var array
     */
    protected array $entityModels = [
        ArchivedEmail::class,
        Comment::class,
        Document::class,
    ];

    /**
     * Stores metadata for relations that can be hydrated from referencedEntities.
     * Keyed by relation name.
     *
     * @var array
     */
    protected array $referencedEagerLoads = [];

    /**
     * Include referenced entities in the API response.
     * Accepts array of foreign key fields (e.g., ['unitId','articleCategoryId'])
     * or a comma separated string.
     *
     * @param array|string $keys
     * @return $this
     */
    public function includeReferencedEntities($keys)
    {
        // normalize to array or comma-separated string kept as-is
        if (is_array($keys)) {
            $this->query->includeReferencedEntities = $keys;
        } elseif (is_string($keys)) {
            $this->query->includeReferencedEntities = $keys;
        }
        return $this;
    }

    /**
     * Convenience: eager-load referenced entities without defining relation methods.
     *
     * Accepts a list of relation names (e.g. ['unit','articleCategory']) and optionally
     * a map of relation => model class (e.g. ['unit' => Unit::class]). If a class is
     * provided, results will be hydrated as that model; otherwise raw attribute arrays
     * will be set on the relation key.
     *
     * The foreign key is inferred as relationName . 'Id' (e.g. unit => unitId).
     *
     * @param array|string $relations
     * @return $this
     */
    public function withReferenced($relations)
    {
        $list = is_string($relations) ? func_get_args() : $relations;

        $fkColumns = [];

        foreach ($list as $key => $value) {
            if (is_int($key)) {
                // numeric key, $value is relation name
                $relationName = (string) $value;
                $relatedClass = null;
            } else {
                // associative: relation => class name
                $relationName = (string) $key;
                $relatedClass = is_string($value) && class_exists($value) ? $value : null;
            }

            $foreignKey = $relationName . 'Id';
            $fkColumns[] = $foreignKey;

            $this->referencedEagerLoads[$relationName] = [
                'type' => 'inferred',
                'foreignKey' => $foreignKey,
                'related' => $relatedClass, // may be null
            ];
        }

        if ($fkColumns) {
            $this->includeReferencedEntities(array_values(array_unique($fkColumns)));

            if (is_array($this->query->columns) && !in_array('*', $this->query->columns, true)) {
                $this->query->columns = array_values(array_unique(array_merge($this->query->columns, $fkColumns)));
            }
        }

        return $this;
    }

    /**
     * Get the referenced entities from the last select on this connection.
     *
     * @return array|null
     */
    public function getReferencedEntities(): ?array
    {
        $conn = $this->model->getConnection();
        if (method_exists($conn, 'getLastReferencedEntities')) {
            return $conn->getLastReferencedEntities();
        }
        return null;
    }

    /**
     * @var Model
     */
    protected $model;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @param QueryBuilder $builder
     */
    public function __construct(QueryBuilder $builder)
    {
        parent::__construct($builder);

        $this->client = app(Client::class);
    }

    public function setModel(Model $model)
    {
        $return = parent::setModel($model);
        $this->query->limit = $this->model->getPerPage();
        return $return;
    }

    /**
     * Override with() to translate BelongsTo relations into includeReferencedEntities
     * and keep metadata for hydration from referencedEntities.
     *
     * @param  array|string  $relations
     * @return $this
     */
    public function with($relations,$callback = null)
    {
        // Normalize to array like Eloquent does
        $relations = is_string($relations) ? func_get_args() : $relations;

        $model = $this->getModel();
        $fkColumns = [];
        $validRelationsForParent = [];

        foreach ($relations as $name => $constraints) {
            $relationName = is_string($name) ? $name : $constraints;

            if (method_exists($model, $relationName)) {
                $validRelationsForParent[$relationName] = $constraints instanceof \Closure ? $constraints : function () {};

                $relation = $model->{$relationName}();
                if ($relation instanceof BelongsTo) {
                    $fk = method_exists($relation, 'getForeignKeyName')
                        ? $relation->getForeignKeyName()
                        : (method_exists($relation, 'getForeignKey') ? $relation->getForeignKey() : null);

                    if ($fk) {
                        $fkColumns[] = $fk;
                        $this->referencedEagerLoads[$relationName] = [
                            'type' => 'belongsTo',
                            'foreignKey' => $fk,
                            'related' => get_class($relation->getRelated()),
                        ];
                    }
                }
            } else {
                // Treat as inferred belongs-to: relationName => relationName.'Id'
                $foreignKey = $relationName . 'Id';
                $fkColumns[] = $foreignKey;
                $this->referencedEagerLoads[$relationName] = [
                    'type' => 'inferred',
                    'foreignKey' => $foreignKey,
                    'related' => null,
                ];
            }
        }

        // Register only valid relations with parent to avoid RelationNotFoundException
        if (!empty($validRelationsForParent)) {
            parent::with($validRelationsForParent);
        }

        if ($fkColumns) {
            $this->includeReferencedEntities(array_values(array_unique($fkColumns)));

            // Ensure FK columns are selected unless using *
            if (is_array($this->query->columns) && !in_array('*', $this->query->columns, true)) {
                $this->query->columns = array_values(array_unique(array_merge($this->query->columns, $fkColumns)));
            }
        }

        return $this;
    }

    /**
     * Hydrate belongsTo relations for models using the referencedEntities payload
     * from the last response. No-op if nothing available.
     */
    protected function hydrateReferencedRelations(array $models): void
    {
        $ref = $this->getReferencedEntities();
        if (!$ref || !$this->referencedEagerLoads) {
            return;
        }

        $lookups = [];
        foreach ($this->referencedEagerLoads as $name => $meta) {
            $type = $meta['type'] ?? null;
            if (!in_array($type, ['belongsTo','inferred'], true)) continue;

            $foreignKey = $meta['foreignKey'];
            $entityKey = $this->resolveReferencedBucketKey($foreignKey, $ref);
            $bucket = $ref[$entityKey] ?? null;
            if (!$bucket || !is_array($bucket)) continue;

            $lookups[$name] = [
                'byId' => array_column($bucket, null, 'id'),
                'related' => $meta['related'] ?? null,
                'foreignKey' => $foreignKey,
            ];
        }

        if (!$lookups) return;

        foreach ($models as $model) {
            foreach ($lookups as $relationName => $info) {
                $fkValue = $model->getAttribute($info['foreignKey']);
                if (!$fkValue) {
                    $model->setRelation($relationName, null);
                    continue;
                }

                $raw = $info['byId'][$fkValue] ?? null;
                if ($raw === null) {
                    continue;
                }

                // If a related class is provided and exists, hydrate as model; otherwise set raw array
                $relatedClass = $info['related'] ?? null;
                if (is_string($relatedClass) && class_exists($relatedClass)) {
                    /** @var \Illuminate\Database\Eloquent\Model $related */
                    $related = (new $relatedClass)->newFromBuilder($raw);
                    $model->setRelation($relationName, $related);
                } else {
                    // Store raw attributes array for simple access without relation methods
                    $model->setRelation($relationName, $raw);
                }
            }
        }
    }

    /**
     * First hydrate relations from referencedEntities, then allow normal eager loading.
     */
    public function eagerLoadRelations(array $models)
    {
        $this->hydrateReferencedRelations($models);

        return parent::eagerLoadRelations($models);
    }

    /**
     * Resolve the referencedEntities bucket key for a given foreign key.
     * Default: strip trailing 'Id'. Allows per-model override via protected $referencedEntityBucketMap.
     */
    protected function resolveReferencedBucketKey(string $foreignKey, array $referenced): string
    {
        // Allow the model to provide an override map: ['customerId' => 'party']
        $model = $this->getModel();
        if (property_exists($model, 'referencedEntityBucketMap') && is_array($model->referencedEntityBucketMap)) {
            if (isset($model->referencedEntityBucketMap[$foreignKey])) {
                return $model->referencedEntityBucketMap[$foreignKey];
            }
        }

        // Common defaults for Weclapp
        $defaults = [
            'customerId' => 'party',
            'supplierId' => 'party',
        ];
        if (isset($defaults[$foreignKey])) {
            return $defaults[$foreignKey];
        }

        // Fallback to simple rule: remove trailing 'Id'
        return preg_replace('/Id$/', '', $foreignKey);
    }

    /**
     * Add a where clause on the primary key to the query.
     *
     * @param  mixed $id
     * @return $this
     */
    public function whereKey($id)
    {
        if (is_array($id) || $id instanceof Arrayable) {
            $this->whereIn($this->model->getQualifiedKeyName(), $id);

            return $this;
        }

        return $this->where($this->model->getQualifiedKeyName(), '=', $id);
    }

    /**
     * Used to query for special models that belong to entities and are handled different on weclapp api.
     * See https://github.com/geccomedia/weclapp/issues/22
     *
     * @param string $name
     * @param int $id
     * @throws NotSupportedException
     */
    public function whereEntity(string $name, int $id)
    {
        if (!in_array(get_class($this->model), $this->entityModels)) {
            throw new NotSupportedException('whereEntity are only not supported on ' . get_class($this->model) . ' by weclapp');
        }

        $this->query->wheres[] = ['type' => 'Entity', 'column' => 'entityName', 'value' => $name];
        $this->query->wheres[] = ['type' => 'Entity', 'column' => 'entityId', 'value' => $id];

        return $this;
    }

    /**
     * Set whether to ignore missing properties in API requests
     *
     * @param bool $ignore
     * @return $this
     */
    public function ignoreMissingProperties(bool $ignore = true)
    {
        $this->query->ignoreMissingProperties($ignore);
        return $this;
    }

    /**
     * Update a record in the database.
     *
     * @param  array  $values
     * @return mixed
     */
    public function update(array $values)
    {
        return parent::update($this->model->getAttributes());
    }
}
