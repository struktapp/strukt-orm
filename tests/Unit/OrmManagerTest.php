<?php

use Strukt\Ar\ModelNotFoundException;
use Strukt\Orm\Manager;
use Tests\Fixtures\User;

beforeEach(function () {
    withSchema();
    Manager::reset();
});

it('returns the same instance for repeated finds', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);

    $one = orm()->find(User::class, $sam->key());
    $two = orm()->find(User::class, $sam->key());

    expect($one)->toBe($two);
    expect($one)->toBeInstanceOf(User::class);
});

it('fails fast on a missing row', function () {
    expect(orm()->find(User::class, 42))->toBeNull();
    expect(fn () => orm()->findOrFail(User::class, 42))->toThrow(ModelNotFoundException::class);
});

it('serves already loaded rows from the identity map', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    $loaded = orm()->find(User::class, $sam->key());

    expect(orm()->lookup(User::class, $sam->key()))->toBe($loaded);
    expect(orm()->lookup(User::class, 42))->toBeNull();
});

it('defers inserts until commit', function () {
    $draft = (new User())->fill(['name' => 'Ada', 'email' => 'ada@strukt.test']);

    orm()->persist($draft);

    expect(orm()->unit()->newEntities())->toHaveCount(1);
    expect(User::count())->toBe(0);

    orm()->commit();

    expect($draft->exists())->toBeTrue();
    expect($draft->key())->not->toBeNull();
    expect(User::count())->toBe(1);
    expect(orm()->unit()->newEntities())->toHaveCount(0);
});

it('defers updates until commit', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    $loaded = orm()->find(User::class, $sam->key());

    $loaded->name = 'Samantha';

    expect(User::find($sam->key())->name)->toBe('Sam');

    orm()->commit();

    expect(User::find($sam->key())->name)->toBe('Samantha');
});

it('does not write untouched entities', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    orm()->find(User::class, $sam->key());

    expect(orm()->unit()->dirty())->toHaveCount(0);

    orm()->commit();

    expect(orm()->unit()->dirty())->toHaveCount(0);
});

it('defers deletes until commit', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    $loaded = orm()->find(User::class, $sam->key());

    orm()->remove($loaded);

    expect(orm()->unit()->deleted())->toHaveCount(1);
    expect(User::count())->toBe(1);

    orm()->commit();

    expect(User::count())->toBe(0);
});

it('rolls the database back when a commit fails', function () {
    $first = (new User())->fill(['name' => 'Sam', 'email' => 'dup@strukt.test']);
    $second = (new User())->fill(['name' => 'Ada', 'email' => 'dup@strukt.test']);

    orm()->persist($first);
    orm()->persist($second);

    expect(fn () => orm()->commit())->toThrow(PDOException::class);
    expect(User::count())->toBe(0);
    expect(orm()->unit()->count())->toBe(0);
});

it('discards pending work on rollback', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    $loaded = orm()->find(User::class, $sam->key());
    $loaded->name = 'Changed';

    $draft = (new User())->fill(['name' => 'Ada', 'email' => 'ada@strukt.test']);
    orm()->persist($draft);

    orm()->rollback();

    expect(orm()->unit()->count())->toBe(0);
    expect(User::find($sam->key())->name)->toBe('Sam');
    expect(User::count())->toBe(1);
});

it('clears every managed entity with clear', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    orm()->find(User::class, $sam->key());

    expect(orm()->identity()->count())->toBe(1);

    orm()->clear();

    expect(orm()->identity()->count())->toBe(0);
    expect(orm()->unit()->count())->toBe(0);
});

it('opens the manager on the env-described connection', function () {
    $file = tempnam(sys_get_temp_dir(), 'kormenv_');
    unlink($file);
    $env = $file . '.env';
    file_put_contents($env, "DB_CONNECTION=sqlite\nDB_DATABASE={$file}\n");

    $manager = Manager::fromEnv($env);

    expect($manager)->toBe(orm());
    expect(db())->toBe($manager->connection());

    db()->execute('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        is_admin INTEGER NOT NULL DEFAULT 0,
        birthday TEXT,
        profile TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $sam = (new User())->fill(['name' => 'Sam', 'email' => 'sam@strukt.test']);
    $manager->persist($sam);
    $manager->commit();

    expect($manager->find(User::class, $sam->key())->name)->toBe('Sam');

    @unlink($env);
    @unlink($file);
});