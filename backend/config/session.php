<?php

return [
    // 知识库主鉴权为无状态 JWT，session 仅作辅助；生产环境在 .env 设 SESSION_TYPE=redis
    'type'     => env('SESSION_TYPE', 'file'),
    'name'     => 'INUWIKI_SESSID',
    'expire'   => 0,
    'prefix'   => 'inuwiki_',
    'var_session_id' => '',
];
