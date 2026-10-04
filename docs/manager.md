# The manager

`Strukt\Orm\Manager` is the hub of the package. It composes an
[`IdentityMap`](identity-map.md), a [`UnitOfWork`](unit-of-work.md), and a row
result cache, and it works over plain `Strukt\Ar\Model` entities.

`find()` resolves a row to its single managed instance, `persist()` and
`remove()` only schedule changes that `commit()` flushes in one transaction,
and `query()` hands out [`Repository`](repositories.md) objects that read
through the same identity map and cache.

## Accessing the manager

`orm()` returns the shared manager, resolved through the default connection:

```php
$sam = orm()->find(User::class, 7);
```

`Manager::for()` binds the shared manager to a named connection:

```php
Manager::for('audit');

$row = orm()->find(AuditLog::class, 3);   // reads through the 'audit' connection
```

`Manager::fromEnv()` opens the connection described by `.env` file(s) first and
then returns the shared manager:

```php
Manager::fromEnv('.env', '.env.local');   // defaults to ['.env']

$sam = orm()->find(User::class, 7);       // reads from the env-described connection
```

See [Strukt Ar connections](../ar/docs/connections.md#connections-from-environment-files)
for the keys and precedence. `Manager::reset()` drops the singleton and its
binding; the test suite uses it to isolate cases.

The manager relies on the connection set as the default (or the named one it is
bound to). `connection()` returns it:

```php
$connection = orm()->connection();
```

## Reading

`find($class, $key)` consults the identity map before any database round trip,
so loading the same row twice returns the very same object:

```php
$one = orm()->find(User::class, 7);
$two = orm()->find(User::class, 7);

$one === $two;   // true, no second query
```

`findOrFail()` throws a `Strukt\Ar\ModelNotFoundException` when the row is
missing, and `lookup()` peeks at the identity map without a query:

```php
$sam  = orm()->find(User::class, 7);
$same = orm()->lookup(User::class, 7);   // the managed instance, no round trip
$none = orm()->lookup(User::class, 99);  // null, never loaded
```

## Writing

`persist()` and `remove()` only schedule work. `commit()` flushes everything
inside one transaction, in this order: new entities are inserted, entities that
changed are updated, removed entities are deleted.

```php
$draft = new User();
$draft->fill(['name' => 'Ada', 'email' => 'ada@strukt.test']);

orm()->persist($draft);
orm()->remove($sam);
orm()->commit();   // one transaction: insert + delete
```

An untouched entity is not written: the unit of work compares a snapshot taken
when the entity was loaded, and only drifted columns are saved.

On success the result cache is emptied, so reads observe the writes. On failure
the database is rolled back, the tracked work is discarded, and the exception is
rethrown — reload affected rows with `find()` afterwards, because the in-memory
persistence flags no longer match the database.

`rollback()` discards every scheduled change and cached result without touching
the database; the identity map is left intact. `clear()` drops managed entities,
scheduled changes, *and* cached results:

```php
orm()->persist($draft);          // scheduled
orm()->rollback();               // nothing was written

orm()->clear();                  // forget everything, in memory
```

## Querying

`query($class)` opens a `Repository` over an entity class:

```php
$admins = orm()->query(User::class)
    ->where('is_admin', 1)
    ->orderBy('name')
    ->get();
```

Read [repositories](repositories.md) for the full surface.

## Convenience accessors

```php
orm()->identity();   // the IdentityMap
orm()->unit();       // the UnitOfWork
```

These allow fine-grained inspection — counting tracked entities, listing
pending inserts, or measuring dirty set — before a `commit()`.