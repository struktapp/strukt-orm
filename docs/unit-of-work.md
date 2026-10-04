# Unit of work

The unit of work tracks entities as they pass through the manager and flushes
them together inside one transaction. Instead of writing on every property
set, it records lifecycle state and writes only what actually changed.

## Lifecycle states

Every tracked entity carries a state, one of four constants:

| Constant | Meaning |
|---|---|
| `NEW` | Inserted on the next commit. |
| `CLEAN` | Matches its snapshot; nothing to write. |
| `DIRTY` | Drifted from its snapshot; updated on the next commit. |
| `DELETED` | Removed on the next commit. |

`state($entity)` returns the state for inspection; `newEntities()`, `dirty()`,
`deleted()`, and `all()` collect the entities in a state. `count()` counts
tracked entities.

## Tracking

`persist()` tracks an entity; `remove()` marks one for deletion. The first
state seen wins:

```php
$draft = (new User())->fill(['name' => 'Ada', 'email' => 'ada@strukt.test']);

orm()->persist($draft);              // state NEW
orm()->persist($draft);              // still NEW, already tracked

orm()->unit()->newEntities();        // [$draft]
orm()->unit()->count();              // 1
```

## Snapshots

A snapshot of the entity's column values is taken when it is first tracked.
`dirty()` compares the current values against that snapshot and promotes each
drifted `CLEAN` entity to `DIRTY`.

Snapshot values are serialized so datetime and array casts compare by value
rather than by object identity — a `DateTimeImmutable` is compared as its
formatted string and an array as its JSON document.

```php
$sam = orm()->find(User::class, 7);

orm()->unit()->dirty();              // [], nothing changed

$sam->name = 'Samantha';
orm()->unit()->dirty();              // [$sam]
```

## Committing

`commit($connection)` runs inside a single transaction:

1. new entities are inserted,
2. clean entities that drifted are saved,
3. deleted entities are removed.

New entities that already exist are skipped. After each insert or update the
snapshot is refreshed, so the entity settles back to `CLEAN`.

```php
orm()->persist($draft);
orm()->commit();

$draft->exists();                    // true
orm()->unit()->newEntities();        // [], flushed
```

On failure the transaction is rolled back, every record is discarded, and the
exception is rethrown:

```php
orm()->persist($first);              // email 'dup@strukt.test'
orm()->persist($second);             // email 'dup@strukt.test'

orm()->commit();                     // throws PDOException
orm()->unit()->count();              // 0, everything was discarded
```

After a failed commit the database may not match what the entities think, so
reload affected rows with `find()`.

## Clearing

`clear()` discards every tracked record without writing anything to the
database:

```php
orm()->rollback();   // unit of work clear + result cache clear
```

The manager's `rollback()` and `clear()` both empty the tracked work; `clear()`
additionally forgets every identity-mapped entity.