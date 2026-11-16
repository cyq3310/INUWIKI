<?php
namespace app;

use think\exception\Handle;
use think\exception\HttpException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理（与 INUEIP 保持一致：统一 JSON 响应）
 */
class ExceptionHandle extends Handle
{
    public function render($request, Throwable $e): Response
    {
        // 参数验证错误
        if ($e instanceof ValidateException) {
            return json(['code' => 400, 'msg' => $e->getError(), 'data' => null]);
        }

        // HTTP 异常
        if ($e instanceof HttpException) {
            return json(['code' => $e->getStatusCode(), 'msg' => $e->getMessage(), 'data' => null], $e->getStatusCode());
        }

        // 其他错误交给系统处理
        return parent::render($request, $e);
    }
}
