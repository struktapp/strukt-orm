<?php

declare(strict_types=1);

use Strukt\Orm\Manager;

if (!function_exists('orm')) {
    /**
     * Returns the shared object-relational mapper on the default connection.
     *
     * ```php
     * $sam = orm()->find(User::class, 7);
     * $sam->name = 'Samantha';
     *
     * $draft = new User();
     * $draft->fill(['name' => 'Ada', 'email' => 'ada@strukt.test']);
     *
     * orm()->persist($draft);
     * orm()->commit();   // one transaction: insert + update
     * ```
     *
     * @return Manager Shared manager.
     */
    function orm(): Manager
    {
        return Manager::instance();
    }
}