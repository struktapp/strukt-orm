<?php

namespace Strukt\Orm;

use Strukt\Ar\Connection;
use Strukt\Ar\Model;

/**
 * Tracks entities as they pass through the manager and flushes them together.
 *
 * Every tracked entity carries a snapshot of its column values, taken when it
 * is first seen. `commit()` runs inside a transaction: new entities are saved,
 * clean entities that drifted from their snapshot are saved, and deleted
 * entities are removed. Snapshot values are serialized so datetime and array
 * casts compare by value rather than by object identity.
 */
final class UnitOfWork
{
    public const NEW = 'new';

    public const CLEAN = 'clean';

    public const DIRTY = 'dirty';

    public const DELETED = 'deleted';

    /** @var array<int, array{entity: object, state: string, snapshot: array<string, mixed>}> Records keyed by object id. */
    private array $records = [];

    /**
     * Starts tracking an entity.
     *
     * An entity that already exists is recorded as clean; one that does not is
     * recorded as new. A tracked entity is left untouched, so the first state
     * seen wins.
     *
     * @param object $entity Entity to track.
     * @return void
     */
    public function track(object $entity): void
    {
        $marker = spl_object_id($entity);

        if (isset($this->records[$marker])) {
            return;
        }

        $this->records[$marker] = [
            'entity' => $entity,
            'state' => $entity instanceof Model && $entity->exists() ? self::CLEAN : self::NEW,
            'snapshot' => $this->snapshot($entity),
        ];
    }

    /**
     * Marks an entity for deletion on the next commit.
     *
     * @param object $entity Entity to delete.
     * @return void
     */
    public function markDeleted(object $entity): void
    {
        $marker = spl_object_id($entity);

        if (!isset($this->records[$marker])) {
            $this->track($entity);
        }

        $this->records[$marker]['state'] = self::DELETED;
    }

    /**
     * Returns the lifecycle state of a tracked entity.
     *
     * @param object $entity Entity to inspect.
     * @return string|null State constant, or null when untracked.
     */
    public function state(object $entity): ?string
    {
        return $this->records[spl_object_id($entity)]['state'] ?? null;
    }

    /**
     * Entities waiting for insertion.
     *
     * @return array<int, object> New entities.
     */
    public function newEntities(): array
    {
        return $this->forState(self::NEW);
    }

    /**
     * Entities whose snapshot drifted from their current values.
     *
     * @return array<int, object> Dirty entities.
     */
    public function dirty(): array
    {
        $dirty = [];

        foreach ($this->records as $marker => $record) {
            if ($record['state'] !== self::CLEAN) {
                continue;
            }

            if ($record['snapshot'] !== $this->snapshot($record['entity'])) {
                $dirty[] = $record['entity'];
                $this->records[$marker]['state'] = self::DIRTY;
            }
        }

        return $dirty;
    }

    /**
     * Entities waiting for deletion.
     *
     * @return array<int, object> Deleted entities.
     */
    public function deleted(): array
    {
        return $this->forState(self::DELETED);
    }

    /**
     * Every tracked entity that is not marked for deletion.
     *
     * @return array<int, object> Tracked entities.
     */
    public function all(): array
    {
        $entities = [];

        foreach ($this->records as $record) {
            if ($record['state'] !== self::DELETED) {
                $entities[] = $record['entity'];
            }
        }

        return $entities;
    }

    /**
     * Number of tracked entities.
     *
     * @return int Tracked count.
     */
    public function count(): int
    {
        return count($this->records);
    }

    /**
     * Flushes the tracked work inside one transaction.
     *
     * On failure the database is rolled back, every record is discarded, and
     * the exception is rethrown. Tracked entities may then re-fetch through
     * the manager, because the in-memory persistence flags no longer match the
     * rolled-back database.
     *
     * @param Connection $connection Connection to persist through.
     * @return void
     *
     * @throws \Throwable When any persistence step fails.
     */
    public function commit(Connection $connection): void
    {
        try {
            $connection->transaction(function () use ($connection) {
                foreach ($this->records as $marker => $record) {
                    if ($record['state'] !== self::NEW || $record['entity']->exists()) {
                        continue;
                    }

                    $record['entity']->save();
                    $this->records[$marker]['snapshot'] = $this->snapshot($record['entity']);
                    $this->records[$marker]['state'] = self::CLEAN;
                }

                foreach ($this->dirty() as $entity) {
                    $entity->save();
                }

                foreach ($this->records as $marker => $record) {
                    if ($record['state'] !== self::DELETED) {
                        continue;
                    }

                    $record['entity']->delete();
                    unset($this->records[$marker]);
                }
            });
        } catch (\Throwable $exception) {
            $this->records = [];
            throw $exception;
        }
    }

    /**
     * Discards every tracked record.
     *
     * Only in-memory state is affected; nothing is written to the database.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->records = [];
    }

    /**
     * Entities in one lifecycle state.
     *
     * @param string $state State constant.
     * @return array<int, object> Entities in that state.
     */
    private function forState(string $state): array
    {
        $entities = [];

        foreach ($this->records as $record) {
            if ($record['state'] === $state) {
                $entities[] = $record['entity'];
            }
        }

        return $entities;
    }

    /**
     * Serialized snapshot of an entity's current column values.
     *
     * @param object $entity Entity to snapshot.
     * @return array<string, mixed> Serialized values keyed by column.
     */
    private function snapshot(object $entity): array
    {
        if (!$entity instanceof Model) {
            return [];
        }

        $values = [];

        foreach ($entity->attributes() as $column => $value) {
            $values[$column] = self::serialize($value);
        }

        return $values;
    }

    /**
     * Serializes a value so object and array casts compare by value.
     *
     * @param mixed $value Value to serialize.
     * @return mixed Comparable serialization.
     */
    private static function serialize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $value;
    }
}