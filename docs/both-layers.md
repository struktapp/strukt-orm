# Working with both layers

Installing `strukt/orm` brings `strukt/ar` in as a dependency, so both layers
operate on the same models, tables, and connection. They do not conflict, but
three behaviors follow from reusing `Strukt\Ar\Model` and are part of the
contract.

## 1. Ar writes do not invalidate the manager's result cache

`commit()`, `rollback()`, and `clear()` are the only cache flushes. A direct
`User::create()` or `$user->save()` bypasses the manager, so a repeated
`orm()->query(User::class)->get()` can serve rows that predate the write until
the next `commit()`/`clear()`:

```php
orm()->query(User::class)->get();                       // 1 row

db()->table('users')->insert(['name' => 'Ada', ...]);   // ar write

orm()->query(User::class)->get();                       // still 1 row (cached)

orm()->commit();                                        // flush

orm()->query(User::class)->get();                       // 2 rows
```

Aggregates always read live; only row results are cached. A `count()` reflects
the ar insert immediately.

## 2. One row can exist as two objects

Ar `find()`/`first()` materialize fresh instances; only what goes through
`orm()` is identity-mapped.

```php
$managed = orm()->find(User::class, 7);   // the managed instance
$fresh    = User::find(7);                // a different, unmanaged instance
```

Editing a *managed* entity and then saving it through Ar writes immediately
*and* leaves it dirty against its snapshot, so `commit()` issues a redundant
update. Ar-`delete()` on a tracked entity makes `commit()` try the delete again
— a harmless zero-row change.

## 3. Interleaved Ar writes ride the commit's transaction

An Ar `save()` between `persist()` and `commit()` uses the same connection, so a
failed commit rolls it back too — "already saved" can be undone by the failing
batch.

## In practice

Pick one path per row or workflow:

- Route managed rows through `orm()`.
- Use `strukt/ar` directly for one-off or bulk work.
- When the two touch the same rows, drop state with `orm()->clear()` after ar
  writes.
- Don't `save()`/`delete()` entities you also intend to `commit()`.

This keeps the identity map, the result cache, and the row's lifecycle coherent.
See [Strukt Ar](../ar/docs/README.md) for the active record layer beneath it.