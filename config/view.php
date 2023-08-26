<?php

return [
    'paths' => [
        resource_path('views'),
    ],

    'compiled' => \Phar::running()
        ? $_SERVER['HOME'] . '/.hcpc/cache/views'
        : env('VIEW_COMPILED_PATH', realpath(storage_path('framework/views'))),
];
