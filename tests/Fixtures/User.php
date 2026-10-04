<?php

namespace Tests\Fixtures;

use Strukt\Ar\Model;

/**
 * User fixture used throughout the test suite.
 */
class User extends Model
{
    protected static bool $timestamps = true;

    protected static array $fillable = ['name', 'email', 'is_admin', 'birthday'];

    protected static array $casts = [
        'is_admin' => 'bool',
        'birthday' => 'datetime',
    ];

    public function posts(): array
    {
        return $this->hasMany(Post::class);
    }
}