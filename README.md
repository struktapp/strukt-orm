# Strukt Orm

Managed entities over [Strukt Ar](https://github.com/pitsolu/strukt-ar): an
identity map, a unit of work, and result caching, layered on the very same
`Strukt\Ar\Model` classes and connection as the active record layer.

`Strukt\Orm` objects are the same entities you get from `User::find()` — created,
saved, and deleted through `save()`/`delete()` as usual. The manager only adds
managed behavior for the objects you route through it:

| | direct (`strukt/ar`) | managed (`strukt/orm`) |
|---|---|---|
| Style | immediate, explicit writes | deferred, batched writes |
| Identity | one object per call | one object per row, always |
| Writes | `save()`/`delete()` run at once | `persist()`/`remove()` flush on `commit()` |
| Reads | query builder per query | query builder + result cache |
| Use | low-level control | managed entities on a large workload |

## Requirements

- PHP 8.2+
- Composer
- `strukt/ar` (pulled in automatically) with its PDO driver

## Install

```bash
composer require strukt/orm
```

`orm()` returns the shared manager; it resolves through the default connection, and
`Manager::for('name')` binds it to a named one. `Manager::fromEnv()` opens the
connection from `.env` file(s) first — see `Strukt\Ar\Connection::fromEnv()` — and
then returns the shared manager:

```php
Manager::fromEnv('.env', '.env.local');

$sam = orm()->find(User::class, 7);   // reads from the env-described connection
```

## Identity map and unit of work

`find()` consults the identity map before touching the database, so a row always loads
into the same instance and repeated lookups cost nothing. `persist()` and `remove()`
only schedule work, and `commit()` flushes it all inside one transaction.

```php
use Strukt\Orm\Manager;

$sam = orm()->find(User::class, 7);    // one instance per row
$sam->name = 'Samantha';               // nothing written yet

$draft = new User();
$draft->fill(['name' => 'Ada', 'email' => 'ada@strukt.test']);

orm()->persist($draft);
orm()->remove($sam);
orm()->commit();                       // one transaction: insert, update, delete
```

An untouched entity is not written: a snapshot taken when it was loaded is compared at
commit time, and only drifted columns are saved. A failed commit rolls the whole batch
back and rethrows the cause; the manager discards its tracked work, so reload affected
rows with `find()` afterwards. `rollback()` discards pending work without touching the
database, and `lookup()` peeks at the identity map without a query.

```php
$sam = orm()->find(User::class, 7);
orm()->lookup(User::class, 7);         // the same instance, no round trip
orm()->lookup(User::class, 99);        // null, not yet loaded
```

## Repositories

`query()` hands out a `Repository` that proxies the `Ar` query builder but hydrates
through the identity map, so query results share instances with `find()`.

```php
$admins = orm()->query(User::class)
    ->where('is_admin', 1)
    ->orderBy('name')
    ->get();

$first = orm()->query(User::class)
    ->where('email', $email)
    ->first();
```

Row results are cached by SQL and bindings, so re-running the same query reuses the
rows. The cache lives in the manager and is emptied by `commit()`, `rollback()`, and
`clear()` — so reads observe writes made through the manager, and, after the next
commit, writes made around it too. Aggregates such as `count()` and `sum()` always read
live.

## Working with both layers

Installing `strukt/orm` brings `strukt/ar` in as a dependency, so both layers operate on the
same models, tables, and connection. They do not conflict, but three behaviors follow
from reusing `Ar\Model` and are part of the contract:

1. **Ar writes do not invalidate the manager's result cache.** `commit()`, `rollback()`,
   and `clear()` are the only cache flushes. A direct `User::create()` or `$user->save()`
   bypasses the manager, so a repeated `orm()->query(User::class)->get()` can serve rows
   that predate the write until the next `commit()`/`clear()`. Aggregates always read
   live; only row results are cached.

2. **One row can exist as two objects.** Ar `find()`/`first()` materialize fresh
   instances; only what goes through `orm()` is identity-mapped. Editing a *managed*
   entity and then saving it through Ar writes immediately *and* leaves it dirty against
   its snapshot, so `commit()` issues a redundant update — and Ar-`delete()` on a
   tracked entity makes `commit()` try the delete again (a harmless zero-row change).

3. **Interleaved Ar writes ride the commit's transaction.** An Ar `save()` between
   `persist()` and `commit()` uses the same connection, so a failed commit rolls it back
   too — "already saved" can be undone by the failing batch.

In practice: pick one path per row or workflow. Route managed rows through `orm()`,
use `strukt/ar` directly for one-off or bulk work, and when the two touch the same rows, drop
state with `orm()->clear()` after Ar writes. Don't `save()`/`delete()` entities you
also intend to `commit()`.

## Tests

```bash
composer test
```

The suite runs against an in-memory SQLite connection with the same schema the `strukt/ar`
package uses and covers the identity map, unit of work, repositories, and result
cache.

## License

MIT. See `LICENSE`.