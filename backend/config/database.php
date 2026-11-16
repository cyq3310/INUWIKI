<?php

return [
    // 默认连接：知识库业务库（读写）
    'default'         => 'kb',

    // 自定义时间查询规则
    'time_query_rule' => [],

    // 自动写入时间戳字段
    'auto_timestamp'  => true,

    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',

    // 时间字段配置 配置格式：create_time,update_time
    'datetime_field'  => '',

    'connections'     => [
        // EIP 库：仅用于读取用户与 RBAC，业务代码禁止写入
        'eip' => [
            'type'            => 'mysql',
            'hostname'        => env('EIP_DB_HOST', '127.0.0.1'),
            'database'        => env('EIP_DB_NAME', 'INUEIP'),
            'username'        => env('EIP_DB_USER', 'root'),
            'password'        => env('EIP_DB_PASS', ''),
            'hostport'        => env('EIP_DB_PORT', '3306'),
            'charset'         => 'utf8mb4',
            'prefix'          => env('EIP_DB_PREFIX', 'inue_'),
            'deploy'          => 0,
            'rw_separate'     => false,
            'master_num'      => 1,
            'slave_no'        => '',
            'fields_strict'   => true,
            'break_reconnect' => false,
            // 生产关闭 SQL 监听；开启字段缓存避免每请求解析表结构
            'trigger_sql'     => false,
            'fields_cache'    => true,
        ],

        // 知识库业务库：读写
        'kb'  => [
            'type'            => 'mysql',
            'hostname'        => env('KB_DB_HOST', '127.0.0.1'),
            'database'        => env('KB_DB_NAME', 'Inuwiki'),
            'username'        => env('KB_DB_USER', 'root'),
            'password'        => env('KB_DB_PASS', ''),
            'hostport'        => env('KB_DB_PORT', '3306'),
            'charset'         => 'utf8mb4',
            'prefix'          => env('KB_DB_PREFIX', ''),
            'deploy'          => 0,
            'rw_separate'     => false,
            'master_num'      => 1,
            'slave_no'        => '',
            'fields_strict'   => true,
            'break_reconnect' => false,
            'trigger_sql'     => false,
            'fields_cache'    => true,
        ],
    ],
];
