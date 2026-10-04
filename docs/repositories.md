# Repositories

A repository is an identity-mapped, result-cached query facade over one entity
class. It builds on the plain `Strukt\Ar` query builder but reads through the
manager: every result row becomes the entity's single managed instance, and
repeated identical queries reuse the cached rows until the next commit.

Get one through the manager:

```php
$users = orm()->query(User::class);
```

Building methods return `$this`; read methods return hydrated entities.

## Building a query

The building methods proxy the ar query builder one-for-one:

```php
$users->select(['name', 'email'])
    ->distinct()
    ->where('is_admin', 1)
    ->orWhere('name', 'Sam')
    ->whereIn('id', [1, 2, 3])
    ->whereNotIn('id', [4])
    ->whereNull('deleted_at')
    ->whereNotNull('email')
    ->whereBetween('age', [21, 65])
    ->join('posts', 'posts.user_id', 'users.id')
    ->leftJoin('memberships', 'memberships.user_id', '=', 'users.id')
    ->groupBy('name')
    ->orderBy('name', 'desc')
    ->limit(10)
    ->offset(20);
```

`where()` accepts the same shapes as the ar builder: equality shorthand,
an explicit operator, or a map of equalities. `latest()` and `oldest()` order by
a column — the primary key when none is given — and replace any prior ordering.

## Reading

```php
$users->get();              // every matching row as managed entities
$users->first();            // first row or null
$users->firstOrFail();      // first row or ModelNotFoundException
```

Every read hydrates through the identity map, so results share instances with
`find()`:

```php
$found = orm()->query(User::class)->where('name', 'Sam')->first();

orm()->lookup(User::class, $sam->key()) === $found;   // true
```

### Gateway methods

`find()`, `findMany()`, `findAll()`, `findBy()`, and `findOneBy()` start from a
**fresh query** — they ignore any builder state from earlier chaining, so they
always describe the whole table.

```php
$users->find(7);                 // by primary key, through the identity map
$users->findMany([7, 8]);        // by keys, in table order
$users->findOrFail(7);           // or ModelNotFoundException

$users->findAll();               // every row
$users->findAll(2, 15);          // page 2, 15 per page

$users->findBy('is_admin', 1);   // all matches
$users->findOneBy(['name' => 'Sam', 'email' => 'sam@strukt.test']);
```

`findAll()` throws for a non-positive page or page size; `findMany([])` returns
an empty array without a query.

## Chunking

`chunk($size, $callback)` walks the results in fixed-size pages, hydrating
through the identity map. Each page is passed to the callback; the walk stops
when the callback returns `false` or the results run out.

```php
orm()->query(User::class)
    ->orderBy('id')
    ->chunk(500, function (array $users): void {
        foreach ($users as $user) {
            // process $user
        }
    });
```

## Writing

Writes go through the unit of work and commit at once, keeping the identity map
and the read cache coherent:

```php
$ada = $users->create(['name' => 'Ada', 'email' => 'ada@strukt.test']);

$ada->name = 'Adeline';
$users->save($ada);

$users->delete($ada);

$users->count();   // 0
```

## Aggregates and values

`count()`, `exists()`, `sum()`, `avg()`, `min()`, `max()`, `value()`, and
`pluck()` read through the underlying builder. Row results are cached, but
aggregates always read live — check [working with ar](both-layers.md) for why.

```php
$users->where('is_admin', 1)->count();   // live
$users->sum('balance');
$users->pluck('name', 'id');
$users->value('email');
```

## Caching

Row results are memoized by class, SQL, and bindings, so re-running the same
query reuses its rows:

```php
$repository = orm()->query(User::class)->where('name', 'Sam');
$first = $repository->get();
$second = $repository->get();

$second === $first;   // true, rows reused
```

The cache lives in the manager and is emptied by `commit()`, `rollback()`, and
`clear()`.