<?php

namespace Strukt\Orm;

use Strukt\Ar\Model;
use Strukt\Ar\ModelNotFoundException;
use Strukt\Ar\Query;

/**
 * An identity-mapped, result-cached query facade over an entity class.
 *
 * A repository builds on the plain `Strukt\Ar` query builder but reads through
 * the manager: every result row becomes the entity's single managed instance,
 * and repeated identical queries reuse the cached rows until the next commit.
 * Building methods return `$this`; read methods return hydrated entities.
 *
 * Gateway methods — `find()`, `findMany()`, `findAll()`, `findBy()` and
 * `findOneBy()` — start from a fresh query, ignoring any prior builder state,
 * so they always describe the whole table and not an earlier chain. Writes go
 * through the unit of work: `create()`, `save()` and `delete()` commit at
 * once, keeping the identity map and the read cache coherent.
 */
final class Repository
{
    private Query $query;

    /**
     * @param Manager $manager Owning manager.
     * @param class-string<Model> $class Entity class.
     */
    public function __construct(
        private readonly Manager $manager,
        private readonly string $class,
    ) {
        $this->query = $manager->connection()->table($class::tableName());
    }

    /**
     * Returns the entity class the repository reads.
     *
     * @return class-string<Model> Entity class.
     */
    public function model(): string
    {
        return $this->class;
    }

    /**
     * Selects specific columns.
     *
     * @param string|array<int, string> $columns Columns to select.
     * @return static This repository.
     */
    public function select(string|array $columns): static
    {
        $this->query->select($columns);

        return $this;
    }

    /**
     * Adds a `DISTINCT` marker to the select.
     *
     * @return static This repository.
     */
    public function distinct(): static
    {
        $this->query->distinct();

        return $this;
    }

    /**
     * Adds a where clause.
     *
     * @see Strukt\Ar\Query::where() for the accepted shapes.
     * @param string|array<string, mixed> $column Column name or map of equalities.
     * @param mixed $operatorOrValue Operator, or the value when no operator is given.
     * @param mixed $value Value; required when an operator is given.
     * @param string $boolean `and` or `or`.
     * @return static This repository.
     */
    public function where(string|array $column, mixed $operatorOrValue = null, mixed $value = null, string $boolean = 'and'): static
    {
        $this->query->where(...func_get_args());

        return $this;
    }

    /**
     * Adds an `OR` where clause.
     *
     * @param string|array<string, mixed> $column Column name or map of equalities.
     * @param mixed $operatorOrValue Operator, or the value when no operator is given.
     * @param mixed $value Value; required when an operator is given.
     * @return static This repository.
     */
    public function orWhere(string|array $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        $this->query->orWhere(...func_get_args());

        return $this;
    }

    /**
     * Adds a `column IN (...)` clause.
     *
     * @param string $column Column name.
     * @param array<int, mixed> $values Candidate values.
     * @return static This repository.
     */
    public function whereIn(string $column, array $values): static
    {
        $this->query->whereIn($column, $values);

        return $this;
    }

    /**
     * Adds a `column NOT IN (...)` clause.
     *
     * @param string $column Column name.
     * @param array<int, mixed> $values Excluded values.
     * @return static This repository.
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->query->whereNotIn($column, $values);

        return $this;
    }

    /**
     * Adds a `column IS NULL` clause.
     *
     * @param string $column Column name.
     * @return static This repository.
     */
    public function whereNull(string $column): static
    {
        $this->query->whereNull($column);

        return $this;
    }

    /**
     * Adds a `column IS NOT NULL` clause.
     *
     * @param string $column Column name.
     * @return static This repository.
     */
    public function whereNotNull(string $column): static
    {
        $this->query->whereNotNull($column);

        return $this;
    }

    /**
     * Adds a `column BETWEEN min AND max` clause.
     *
     * @param string $column Column name.
     * @param array{0: mixed, 1: mixed} $range Inclusive lower and upper bounds.
     * @return static This repository.
     */
    public function whereBetween(string $column, array $range): static
    {
        $this->query->whereBetween($column, $range);

        return $this;
    }

    /**
     * Adds a join clause.
     *
     * @see Strukt\Ar\Query::join() for the accepted shapes.
     * @param string $table Joined table name.
     * @param string $first Left column.
     * @param string $operator Operator, or the right column when no operator is given.
     * @param string|null $second Right column.
     * @param string $type Join type.
     * @return static This repository.
     */
    public function join(string $table, string $first, string $operator = '=', ?string $second = null, string $type = 'inner'): static
    {
        $this->query->join(...func_get_args());

        return $this;
    }

    /**
     * Adds a `LEFT JOIN` clause.
     *
     * @param string $table Joined table name.
     * @param string $first Left column.
     * @param string $operator Operator, or the right column when no operator is given.
     * @param string|null $second Right column.
     * @return static This repository.
     */
    public function leftJoin(string $table, string $first, string $operator = '=', ?string $second = null): static
    {
        $this->query->leftJoin(...func_get_args());

        return $this;
    }

    /**
     * Orders the results.
     *
     * @param string $column Column to order by.
     * @param string $direction `asc` or `desc`.
     * @return static This repository.
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->query->orderBy($column, $direction);

        return $this;
    }

    /**
     * Groups the results.
     *
     * @param string|array<int, string> $columns Columns to group by.
     * @return static This repository.
     */
    public function groupBy(string|array $columns): static
    {
        $this->query->groupBy($columns);

        return $this;
    }

    /**
     * Limits the number of results.
     *
     * @param int $limit Maximum rows.
     * @return static This repository.
     */
    public function limit(int $limit): static
    {
        $this->query->limit($limit);

        return $this;
    }

    /**
     * Skips the first rows.
     *
     * @param int $offset Rows to skip.
     * @return static This repository.
     */
    public function offset(int $offset): static
    {
        $this->query->offset($offset);

        return $this;
    }

    /**
     * Returns every matching row as managed entities.
     *
     * @return array<int, object> Managed entities.
     */
    public function get(): array
    {
        return $this->run($this->query);
    }

    /**
     * Returns the first matching row as a managed entity.
     *
     * @return object|null Managed entity, or null when empty.
     */
    public function first(): ?object
    {
        return $this->run((clone $this->query)->limit(1))[0] ?? null;
    }

    /**
     * Returns the first matching row as a managed entity or throws.
     *
     * @return object Managed entity.
     *
     * @throws ModelNotFoundException When no row matches.
     */
    public function firstOrFail(): object
    {
        $entity = $this->first();

        if ($entity === null) {
            throw new ModelNotFoundException($this->class, null);
        }

        return $entity;
    }

    /**
     * Finds a row by its primary key through the identity map.
     *
     * Repeat loads return the same managed instance without another query.
     *
     * @param mixed $key Primary key value.
     * @return object|null Managed entity, or null when not found.
     */
    public function find(mixed $key): ?object
    {
        return $this->manager->find($this->class, $key);
    }

    /**
     * Finds multiple rows by their primary keys.
     *
     * @param array<int, mixed> $keys Primary key values.
     * @return array<int, object> Managed entities, in table order.
     */
    public function findMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return $this->run($this->fresh()->whereIn($this->class::keyName(), $keys));
    }

    /**
     * Finds a row by its primary key or throws.
     *
     * @param mixed $key Primary key value.
     * @return object Managed entity.
     *
     * @throws ModelNotFoundException When no row matches.
     */
    public function findOrFail(mixed $key): object
    {
        return $this->manager->findOrFail($this->class, $key);
    }

    /**
     * Returns every row, optionally one page of them.
     *
     * @param int $page 1-based page number.
     * @param int|null $perPage Page size; null returns every row.
     * @return array<int, object> Managed entities.
     *
     * @throws \InvalidArgumentException When page or per-page is not positive.
     */
    public function findAll(int $page = 1, ?int $perPage = null): array
    {
        if ($page < 1 || ($perPage !== null && $perPage < 1)) {
            throw new \InvalidArgumentException('Repository::findAll() expects a positive page and per-page size.');
        }

        $query = $this->fresh();

        if ($perPage !== null) {
            $query->limit($perPage)->offset(($page - 1) * $perPage);
        }

        return $this->run($query);
    }

    /**
     * Returns every row matching the criteria.
     *
     * @see Strukt\Ar\Query::where() for the accepted shapes.
     * @param string|array<string, mixed> $column Column name or map of equalities.
     * @param mixed $operatorOrValue Operator, or the value when no operator is given.
     * @param mixed $value Value; required when an operator is given.
     * @return array<int, object> Managed entities.
     */
    public function findBy(string|array $column, mixed $operatorOrValue = null, mixed $value = null): array
    {
        return $this->run($this->fresh()->where(...func_get_args()));
    }

    /**
     * Returns the first row matching the criteria.
     *
     * @see Strukt\Ar\Query::where() for the accepted shapes.
     * @param string|array<string, mixed> $column Column name or map of equalities.
     * @param mixed $operatorOrValue Operator, or the value when no operator is given.
     * @param mixed $value Value; required when an operator is given.
     * @return object|null Managed entity, or null when empty.
     */
    public function findOneBy(string|array $column, mixed $operatorOrValue = null, mixed $value = null): ?object
    {
        return $this->run($this->fresh()->where(...func_get_args())->limit(1))[0] ?? null;
    }

    /**
     * Orders the results newest first.
     *
     * Replaces any prior ordering so the basis direction stays fresh.
     *
     * @param string|null $column Column to order by, the primary key when null.
     * @return static This repository.
     */
    public function latest(?string $column = null): static
    {
        $this->query->reorder($column ?? $this->class::keyName(), 'desc');

        return $this;
    }

    /**
     * Orders the results oldest first.
     *
     * Replaces any prior ordering so the basis direction stays fresh.
     *
     * @param string|null $column Column to order by, the primary key when null.
     * @return static This repository.
     */
    public function oldest(?string $column = null): static
    {
        $this->query->reorder($column ?? $this->class::keyName(), 'asc');

        return $this;
    }

    /**
     * Walks the results in fixed-size, identity-mapped pages.
     *
     * Each page is fetched, passed to the callback fresh, and the walk stops
     * when the callback returns false or the results run out.
     *
     * @param int $size Page size.
     * @param callable(array<int, object>): bool|void $callback Page consumer.
     * @return static This repository.
     *
     * @throws \InvalidArgumentException When the page size is not positive.
     */
    public function chunk(int $size, callable $callback): static
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Repository::chunk() expects a positive page size.');
        }

        $offset = 0;

        while (true) {
            $page = $this->run((clone $this->query)->limit($size)->offset($offset));

            if ($page === []) {
                break;
            }

            if ($callback($page) === false) {
                break;
            }

            $offset += $size;
        }

        return $this;
    }

    /**
     * Creates and persists a managed entity from column values.
     *
     * @param array<string, mixed> $attributes Column values.
     * @return object Persisted managed entity.
     */
    public function create(array $attributes): object
    {
        $entity = (new $this->class())->fill($attributes);
        $this->manager->persist($entity);
        $this->manager->commit();

        return $entity;
    }

    /**
     * Inserts or updates a managed entity and clears the read cache.
     *
     * @param object $entity Entity of the repository's model.
     * @return object The persisted entity.
     */
    public function save(object $entity): object
    {
        $this->manager->persist($entity);
        $this->manager->commit();

        return $entity;
    }

    /**
     * Deletes a managed entity and clears the read cache.
     *
     * @param object $entity Entity of the repository's model.
     * @return void
     */
    public function delete(object $entity): void
    {
        $this->manager->remove($entity);
        $this->manager->commit();
    }

    /**
     * Counts matching rows.
     *
     * @return int Row count.
     */
    public function count(): int
    {
        return $this->query->count();
    }

    /**
     * Checks whether any row matches.
     *
     * @return bool True when at least one row matches.
     */
    public function exists(): bool
    {
        return $this->query->exists();
    }

    /**
     * Sums a column over the matching rows.
     *
     * @param string $column Column to sum.
     * @return int|float|null Total, or null when no rows match.
     */
    public function sum(string $column): int|float|null
    {
        return $this->query->sum($column);
    }

    /**
     * Averages a column over the matching rows.
     *
     * @param string $column Column to average.
     * @return int|float|null Average, or null when no rows match.
     */
    public function avg(string $column): int|float|null
    {
        return $this->query->avg($column);
    }

    /**
     * Returns the smallest value of a column.
     *
     * @param string $column Column to inspect.
     * @return mixed Minimum value, or null when no rows match.
     */
    public function min(string $column): mixed
    {
        return $this->query->min($column);
    }

    /**
     * Returns the largest value of a column.
     *
     * @param string $column Column to inspect.
     * @return mixed Maximum value, or null when no rows match.
     */
    public function max(string $column): mixed
    {
        return $this->query->max($column);
    }

    /**
     * Returns a single column value from the first result.
     *
     * @param string $column Column to read.
     * @param mixed $default Value when the result is empty.
     * @return mixed Column value, or the default.
     */
    public function value(string $column, mixed $default = null): mixed
    {
        return $this->query->value($column, $default);
    }

    /**
     * Collects a column into a list.
     *
     * @param string $column Column to collect.
     * @param string|null $key Optional column used as the array keys.
     * @return array<array-key, mixed> Column values.
     */
    public function pluck(string $column, ?string $key = null): array
    {
        return $this->query->pluck($column, $key);
    }

    /**
     * Runs a query through the manager's row cache and hydration.
     *
     * @param Query $query Query to run.
     * @return array<int, object> Managed entities.
     */
    private function run(Query $query): array
    {
        $rows = $this->manager->rows($this->class, $query->toSql(), $query->bindings());

        return $this->manager->hydrateAll($this->class, $rows);
    }

    /**
     * Opens a fresh, unbounded query over the entity table.
     *
     * @return Query Fresh query.
     */
    private function fresh(): Query
    {
        return $this->manager->connection()->table($this->class::tableName());
    }
}