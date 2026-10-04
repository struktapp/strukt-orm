# Identity map

The identity map keeps at most one object per class and primary key. It is what
makes a managed entity *the same object* no matter how many times it is loaded.

The manager consults the map before any database round trip, so loading the same
row twice returns the very same instance — with no additional query.

## Normalization

Identity keys are normalized to strings, so an integer `1` and a string `"1"`
address the same object:

```php
$sam = orm()->find(User::class, 1);
$also = orm()->find(User::class, '1');

$sam === $also;   // true
```

## The class

`Strukt\Orm\IdentityMap` is a small registry. You rarely touch it directly, but
its methods define the contract the manager relies on:

| Method | Behavior |
|---|---|
| `has($class, $key)` | Whether an entity is remembered for the normalized key. |
| `get($class, $key)` | The remembered entity, or `null`. |
| `remember($entity, $class, $key)` | Remembers an entity under its normalized key. |
| `forget($class, $key)` | Drops the entry for a key. |
| `clear()` | Forgets every entity. |
| `count()` | Number of remembered entities. |

## When entries are written

- `Manager::find()` regsisters the loaded entity under its class and key.
- Repository reads hydrate through the map, so query results share instances
  with `find()`.
- `commit()` remembers every tracked entity that survived the flush.
- `Manager::remove()` forgets an entity that has a key, since the row is about
  to disappear.

## When entries are dropped

`Manager::clear()` and the removal path above are the main ways entries leave
the map. `rollback()` does *not* clear the identity map — a rolled-back commit
discards scheduled work and cached rows, but already managed entities stay
managed.

```php
orm()->persist($draft);
orm()->rollback();

orm()->identity()->count();   // entities loaded before rollback are still here
```

Because persistence flags may no longer match the rolled-back database, reload
affected rows with `find()` — the map will hand back the same instance with
values refreshed.