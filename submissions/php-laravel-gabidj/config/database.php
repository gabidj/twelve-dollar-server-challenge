<?php

return [
    'default' => 'sqlite',

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('SQLITE_PATH'),
            'prefix' => '',
            'foreign_key_constraints' => false,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'pragmas' => [
                'cache_size' => -65536,      // 64 MiB page cache
                'mmap_size' => 268435456,    // 256 MiB
                'temp_store' => 'MEMORY',
            ],
        ],
    ],

    'migrations' => ['table' => 'migrations'],
];
