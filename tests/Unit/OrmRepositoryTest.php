<?php

use Strukt\Orm\Manager;
use Tests\Fixtures\User;

beforeEach(function () {
    withSchema();
    Manager::reset();
});

it('returns identity-mapped entities from queries', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    $found = orm()->query(User::class)->where('name', 'Sam')->first();

    expect($found)->toBeInstanceOf(User::class);
    expect(orm()->lookup(User::class, $sam->key()))->toBe($found);
});

it('proxies filters, ordering, and paging', function () {
    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    User::create(['name' => 'Ada', 'email' => 'ada@strukt.test']);
    User::create(['name' => 'Eve', 'email' => 'eve@strukt.test']);

    $entities = orm()->query(User::class)->orderBy('name')->limit(2)->offset(1)->get();
    $names = array_map(static fn (User $user): string => (string) $user->name, $entities);

    expect($names)->toBe(['Eve', 'Sam']);
});

it('reads single results and empty results', function () {
    $empty = orm()->query(User::class);

    expect($empty->first())->toBeNull();

    orm()->commit();

    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect(orm()->query(User::class)->first()->name)->toBe('Sam');
});

it('memoizes identical queries', function () {
    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    $repository = orm()->query(User::class)->where('name', 'Sam');
    $first = $repository->get();
    $second = $repository->get();

    expect($second)->toBe($first);
});

it('flushes the result cache on commit', function () {
    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    expect(orm()->query(User::class)->get())->toHaveCount(1);

    db()->table('users')->insert(['name' => 'Ada', 'email' => 'ada@strukt.test']);

    expect(orm()->query(User::class)->get())->toHaveCount(1);

    orm()->commit();

    expect(orm()->query(User::class)->get())->toHaveCount(2);
});

it('keeps aggregates live', function () {
    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    User::create(['name' => 'Ada', 'email' => 'ada@strukt.test']);

    expect(orm()->query(User::class)->count())->toBe(2);

    db()->table('users')->insert(['name' => 'Eve', 'email' => 'eve@strukt.test']);

    expect(orm()->query(User::class)->count())->toBe(3);
});

it('collects values through the builder', function () {
    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    $repository = orm()->query(User::class);

    expect($repository->pluck('name'))->toBe(['Sam']);
    expect($repository->value('name'))->toBe('Sam');
    expect($repository->exists())->toBeTrue();
});

it('finds by key through the identity map', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    User::create(['name' => 'Ada', 'email' => 'ada@strukt.test']);

    $repository = orm()->query(User::class);
    $first = $repository->find($sam->key());

    expect($first)->toBeInstanceOf(User::class);
    expect($first->name)->toBe('Sam');
    expect($repository->find($sam->key()))->toBe($first);
    expect($repository->find(4242))->toBeNull();
    expect($repository->findOrFail($sam->key()))->toBe($first);
    expect(fn () => $repository->findOrFail(4242))->toThrow(Strukt\Ar\ModelNotFoundException::class);
    expect($repository->findMany([$sam->key(), 4242]))->toHaveCount(1);
    expect($repository->findMany([]))->toHaveCount(0);
});

it('finds all rows, optionally paginated', function () {
    foreach (['Sam', 'Ada', 'Eve'] as $name) {
        User::create(['name' => $name, 'email' => $name . '@strukt.test']);
    }

    $repository = orm()->query(User::class);

    expect($repository->findAll())->toHaveCount(3);

    $page = $repository->findAll(2, 2);

    expect($page)->toHaveCount(1);
    expect($page[0]->name)->toBe('Eve');
    expect(fn () => $repository->findAll(0, 2))->toThrow(InvalidArgumentException::class);
});

it('finds rows and single rows by criteria', function () {
    User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    User::create(['name' => 'Sam', 'email' => 'sam2@strukt.test']);
    User::create(['name' => 'Ada', 'email' => 'ada@strukt.test']);

    $repository = orm()->query(User::class);

    expect($repository->findBy('name', 'Sam'))->toHaveCount(2);
    expect($repository->findOneBy('name', 'Sam'))->toBeInstanceOf(User::class);
    expect($repository->findOneBy(['name' => 'Sam', 'email' => 'sam@strukt.test'])->email)->toBe('sam@strukt.test');
    expect($repository->findOneBy('name', 'Nobody'))->toBeNull();
});

it('fails loudly when the first result is missing', function () {
    $repository = orm()->query(User::class);

    expect(fn () => $repository->firstOrFail())->toThrow(Strukt\Ar\ModelNotFoundException::class);
    expect(fn () => $repository->where('name', 'Sam')->firstOrFail())->toThrow(Strukt\Ar\ModelNotFoundException::class);
});

it('orders latest first and chunks pages', function () {
    foreach (['Sam', 'Ada', 'Eve'] as $name) {
        User::create(['name' => $name, 'email' => $name . '@strukt.test']);
    }

    $repository = orm()->query(User::class);

    expect($repository->latest()->first()->name)->toBe('Eve');
    expect($repository->oldest()->first()->name)->toBe('Sam');

    $visited = [];
    $repository->chunk(2, static function (array $items) use (&$visited): void {
        foreach ($items as $item) {
            $visited[] = $item->name;
        }
    });

    expect($visited)->toBe(['Sam', 'Ada', 'Eve']);
});

it('creates, saves, and deletes through the unit of work', function () {
    $repository = orm()->query(User::class);

    $ada = $repository->create(['name' => 'Ada', 'email' => 'ada@strukt.test']);

    expect($ada->key())->not->toBeNull();
    expect($repository->find($ada->key()))->toBe($ada);

    $ada->name = 'Adeline';
    $repository->save($ada);

    expect($repository->find($ada->key())->name)->toBe('Adeline');

    $repository->delete($ada);

    expect($repository->find($ada->key()))->toBeNull();
    expect($repository->count())->toBe(0);
});