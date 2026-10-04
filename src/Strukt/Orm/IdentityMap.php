<?php

namespace Strukt\Orm;

/**
 * Keeps at most one object per class and primary key.
 *
 * The manager consults the map before any database round trip, so loading the
 * same row twice returns the very same instance. Identity keys are normalized
 * to strings so an integer `1` and a string `"1"` address the same object.
 */
final class IdentityMap
{
    /** @var array<class-string, array<string, object>> Entities keyed by normalized key. */
    private array $entities = [];

    /**
     * Checks whether an entity is remembered.
     *
     * @param class-string $class Entity class.
     * @param mixed $key Primary key value.
     * @return bool True when an entity is remembered for the key.
     */
    public function has(string $class, mixed $key): bool
    {
        return isset($this->entities[$class][self::normalize($key)]);
    }

    /**
     * Returns the remembered entity, or null.
     *
     * @param class-string $class Entity class.
     * @param mixed $key Primary key value.
     * @return object|null Remembered entity, or null.
     */
    public function get(string $class, mixed $key): ?object
    {
        return $this->entities[$class][self::normalize($key)] ?? null;
    }

    /**
     * Remembers an entity under its primary key.
     *
     * @param object $entity Entity to remember.
     * @param class-string $class Entity class.
     * @param mixed $key Primary key value.
     * @return object The remembered entity.
     */
    public function remember(object $entity, string $class, mixed $key): object
    {
        $this->entities[$class][self::normalize($key)] = $entity;

        return $entity;
    }

    /**
     * Forgets the entity under a primary key.
     *
     * @param class-string $class Entity class.
     * @param mixed $key Primary key value.
     * @return void
     */
    public function forget(string $class, mixed $key): void
    {
        unset($this->entities[$class][self::normalize($key)]);
    }

    /**
     * Drops every remembered entity.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->entities = [];
    }

    /**
     * Number of remembered entities.
     *
     * @return int Entity count.
     */
    public function count(): int
    {
        return count($this->entities, COUNT_RECURSIVE) - count($this->entities);
    }

    /**
     * Normalizes a primary key to its identity form.
     *
     * @param mixed $key Primary key value.
     * @return string Normalized key.
     */
    private static function normalize(mixed $key): string
    {
        return (string) $key;
    }
}