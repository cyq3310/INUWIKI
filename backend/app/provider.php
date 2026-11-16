<?php
use app\ExceptionHandle;
use app\Request;

// 容器 Provider 定义文件
return [
    'think\Request'          => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,
];
