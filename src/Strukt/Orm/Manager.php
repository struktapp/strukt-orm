<?php

namespace Strukt\Orm;

use Strukt\Ar\Connection;
use Strukt\Ar\ConnectionManager;
use Strukt\Ar\Model;
use Strukt\Ar\ModelNotFoundException;

/**
 * The object-relational mapper hub: identity, deferred writes, and queries.
 *
 * A manager composes an {@see IdentityMap}, a {@see UnitOfWork}, and a result
 * cache, and works over plain `Strukt\Ar\Model` entities. `find()` resolves a
 * row to its one managed instance, `persist()` and `remove()` schedule changes
 * that `commit()` flushes in a single transaction, and `query()` hands out
 * {@see Repository} objects that read through the same identity map and cache
 * their row results until the next write.
 *
 * The helper `orm()` returns the shared manager on the default connection;
 * `Manager::for()` binds the manager to a named connection.
 */
final class Manager
{
    /** @var Manager|null Singleton instance. */
    private static ?Manager $instance = null;

    /** @var string|null Connection name the singleton is bound to. */
    private static ?string $bound = null;

    /** @var array<string, array<int, array<string, mixed>>> Cached row results. */
    private array $results = [];

    /**
     * Opens a fresh manager.
     *
     * @param IdentityMap $identityMap Identity map to use.
     * @param UnitOfWork $unitOfWork Unit of work to use.
     */
    public function __construct(
        private IdentityMap $identityMap = new IdentityMap(),
        private UnitOfWork $unitOfWork = new UnitOfWork(),
    ) {
    }

    /**
     * Returns the shared manager for the default connection.
     *
     * @return static Shared manager.
     */
    public static function instance(): static
    {
        return self::$instance ??= new static();
    }

    /**
     * Returns the shared manager bound to a named connection.
     *
     * @param string $connection Connection name.
     * @return static Shared manager on that connection.
     */
    public static function for(string $connection): static
    {
        self::$bound = $connection;

        return self::instance();
    }

    /**
     * Returns the shared manager on the connection described by environment file(s).
     *
     * Files are loaded with `Env::withFile()` in order, so a later file
     * overrides an earlier one. See {@see Connection::fromEnv()} for the keys.
     *
     * @param string ...$files Environment files, last one wins.
     * @return static Shared manager.
     */
    public static function fromEnv(string ...$files): static
    {
        Connection::fromEnv(...$files);

        return self::instance();
    }

    /**
     * Drops the shared manager and its in-memory state.
     *
     * Used by the test suite to isolate cases.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$bound = null;
    }

    /**
     * Returns the connection the manager persists through.
     *
     * @return Connection Bound connection, or the default.
     */
    public function connection(): Connection
    {
        return self::$bound === null ? ConnectionManager::default() : ConnectionManager::get(self::$bound);
    }

    /**
     * Returns the identity map.
     *
     * @return IdentityMap Identity map.
     */
    public function identity(): IdentityMap
    {
        return $this->identityMap;
    }

    /**
     * Returns the unit of work.
     *
     * @return UnitOfWork Unit of work.
     */
    public function unit(): UnitOfWork
    {
        return $this->unitOfWork;
    }

    /**
     * Loads a row into its managed instance.
     *
     * The identity map is consulted first, so repeat loads never reach the
     * database and always return the same object.
     *
     * @param class-string<Model> $class Entity class.
     * @param mixed $key Primary key value.
     * @return object|null Managed entity, or null when not found.
     */
    public function find(string $class, mixed $key): ?object
    {
        if ($this->identityMap->has($class, $key)) {
            return $this->identityMap->get($class, $key);
        }

        $entity = $class::find($key);

        if ($entity === null) {
            return null;
        }

        return $this->manage($entity);
    }

    /**
     * Loads a row into its managed instance or throws.
     *
     * @param class-string<Model> $class Entity class.
     * @param mixed $key Primary key value.
     * @return object Managed entity.
     *
     * @throws ModelNotFoundException When no row matches.
     */
    public function findOrFail(string $class, mixed $key): object
    {
        $entity = $this->find($class, $key);

        if ($entity === null) {
            throw new ModelNotFoundException($class, $key);
        }

        return $entity;
    }

    /**
     * Peeks at the identity map without a database query.
     *
     * @param class-string $class Entity class.
     * @param mixed $key Primary key value.
     * @return object|null Managed entity, or null when not loaded.
     */
    public function lookup(string $class, mixed $key): ?object
    {
        return $this->identityMap->has($class, $key) ? $this->identityMap->get($class, $key) : null;
    }

    /**
     * Schedules an entity for insertion or update at the next commit.
     *
     * @param object $entity Entity to persist.
     * @return static This manager.
     */
    public function persist(object $entity): static
    {
        $this->unitOfWork->track($entity);

        return $this;
    }

    /**
     * Schedules an entity for deletion at the next commit.
     *
     * @param object $entity Entity to remove.
     * @return static This manager.
     */
    public function remove(object $entity): static
    {
        $this->unitOfWork->markDeleted($entity);

        if ($entity instanceof Model && $entity->key() !== null) {
            $this->identityMap->forget($entity::class, $entity->key());
        }

        return $this;
    }

    /**
     * Flushes every scheduled change inside one transaction.
     *
     * New entities are inserted, changed entities updated, and removed entities
     * deleted, in that order. On success the result cache is emptied so reads
     * observe the writes. On failure the database is rolled back, the tracked
     * work is discarded, and the exception is rethrown.
     *
     * @return void
     *
     * @throws \Throwable When any persistence step fails.
     */
    public function commit(): void
    {
        $this->unitOfWork->commit($this->connection());

        foreach ($this->unitOfWork->all() as $entity) {
            if (!$entity instanceof Model || $entity->key() === null) {
                continue;
            }

            $this->identityMap->remember($entity, $entity::class, $entity->key());
        }

        $this->results = [];
    }

    /**
     * Discards every scheduled change and cached result.
     *
     * Nothing is written to the database; only in-memory state is lost.
     *
     * @return void
     */
    public function rollback(): void
    {
        $this->unitOfWork->clear();
        $this->results = [];
    }

    /**
     * Discards every managed entity, scheduled change, and cached result.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->unitOfWork->clear();
        $this->identityMap->clear();
        $this->results = [];
    }

    /**
     * Opens a repository over an entity class.
     *
     * @param class-string<Model> $class Entity class.
     * @return Repository Repository over the class.
     */
    public function query(string $class): Repository
    {
        return new Repository($this, $class);
    }

    /**
     * Fetches rows, memoized by class, SQL, and bindings.
     *
     * @param class-string $class Entity class.
     * @param string $sql SQL to run.
     * @param array<int, mixed> $bindings Bound values.
     * @return array<int, array<string, mixed>> Rows.
     */
    public function rows(string $class, string $sql, array $bindings): array
    {
        $key = $class . '|' . $sql . '|' . json_encode($bindings, JSON_THROW_ON_ERROR);

        return $this->results[$key] ??= $this->connection()->select($sql, $bindings);
    }

    /**
     * Hydrates one row into its managed instance.
     *
     * @param class-string<Model> $class Entity class.
     * @param array<string, mixed> $row Column values.
     * @return object Managed entity.
     */
    public function hydrate(string $class, array $row): object
    {
        $entity = $class::hydrate($row);
        $key = $entity->key();

        if ($key !== null && $this->identityMap->has($class, $key)) {
            return $this->identityMap->get($class, $key);
        }

        return $this->manage($entity);
    }

    /**
     * Hydrates rows into their managed instances.
     *
     * @param class-string<Model> $class Entity class.
     * @param array<int, array<string, mixed>> $rows Column values.
     * @return array<int, object> Managed entities.
     */
    public function hydrateAll(string $class, array $rows): array
    {
        return array_map(fn (array $row): object => $this->hydrate($class, $row), $rows);
    }

    /**
     * Registers an entity in the unit of work and the identity map.
     *
     * @param object $entity Entity to manage.
     * @return object The managed entity.
     */
    private function manage(object $entity): object
    {
        $this->unitOfWork->track($entity);

        if ($entity instanceof Model && $entity->key() !== null) {
            $this->identityMap->remember($entity, $entity::class, $entity->key());
        }

        return $entity;
    }
}