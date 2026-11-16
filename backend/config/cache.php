<?php

return [
    // 默认缓存驱动：本地开发用 file，生产环境在 .env 设 CACHE_TYPE=redis
    'default' => env('CACHE_TYPE', 'file'),

    'stores'  => [
        'file'  => [
            'type'       => 'File',
            'path'       => runtime_path() . 'cache',
            'expire'     => 0,
            'prefix'     => 'inuwiki_',
            'serialize'  => true,
        ],
        'redis' => [
            'type'       => 'redis',
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            'port'       => env('REDIS_PORT', 6379),
            'password'   => env('REDIS_PASS', ''),
            'select'     => env('REDIS_SELECT', 0),
            'timeout'    => 0,
            'expire'     => 0,
            'prefix'     => 'inuwiki_',
            'tag_prefix' => 'tag:inuwiki_',
            'serialize'  => true,
        ],
    ],
];
