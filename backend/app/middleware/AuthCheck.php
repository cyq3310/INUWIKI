<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\AuthService;
use app\service\RbacService;

/**
 * JWT 认证中间件：校验 Bearer Token 并注入用户档案到请求
 * 用户与权限均来自 EIP 库（只读）
 */
class AuthCheck
{
    public function handle($request, \Closure $next)
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            try {
                $userId = (new AuthService())->verifyToken($matches[1]);
            } catch (\Throwable $e) {
                return auth_expired_response('登录状态无效，请重新登录');
            }
        } elseif ($this->isMediaResource($request)) {
            // <a href> 下载与 <video src> 播放由浏览器直接发起，无法携带 Authorization 头。
            // 仅媒体资源路径允许改用登录时下发的 HttpOnly 受限票据 cookie，
            // 业务接口仍强制 Bearer Token，票据泄露也无法调用任何业务 API。
            $userId = (new AuthService())->verifyAssetTicket(
                (string) $request->cookie('kb_asset', '')
            );
        } else {
            return auth_expired_response();
        }

        if ($userId <= 0) {
            return auth_expired_response('登录状态无效，请重新登录');
        }

        // 每次请求都回源校验账号状态：EIP 后台禁用账号后即时生效。
        // X-Space-Id 是前端顶栏选中的空间，决定本次请求的有效权限集合（无对象时按它判定）。
        try {
            $spaceId = max(0, (int) $request->header('X-Space-Id', '0'));
            $profile = (new RbacService())->buildProfile($userId, $spaceId);
        } catch (\Throwable $e) {
            return auth_expired_response('账号不存在或已被禁用');
        }

        $request->userId      = $userId;
        $request->userProfile = $profile;

        return $next($request);
    }

    /** 媒体播放 / 下载路径：GET kbapi/articles/:id/media/:attachment_id */
    private function isMediaResource($request): bool
    {
        $path = trim((string) $request->pathinfo(), '/');
        return (bool) preg_match('#^(?:.*/)?articles/\d+/media/\d+$#', $path);
    }
}
