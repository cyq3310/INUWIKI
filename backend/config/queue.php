<?php

return [
    // 生产环境必须为 redis（Supervisor 守护 queue:work）；
    // 本地开发若无 Redis，可在 .env 设 QUEUE_CONNECTION=sync 以同步方式跑通链路
    'default'     => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        'sync'  => [
            'type' => 'sync',
        ],
        'redis' => [
            'type'       => 'redis',
            'queue'      => 'inuwiki',
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            'port'       => env('REDIS_PORT', 6379),
            'password'   => env('REDIS_PASS', ''),
            'select'     => env('REDIS_QUEUE_SELECT', 1),
            'timeout'    => 0,
            'persistent' => false,
        ],
    ],

    'failed'      => [
        'type'  => 'none',
        'table' => 'kb_queue_failed',
    ],
];
