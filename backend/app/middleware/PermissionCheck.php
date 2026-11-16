<?php
declare(strict_types=1);

namespace app\middleware;

/**
 * 接口级权限中间件：按权限码校验（超管放行），需在 AuthCheck 之后执行
 * 权限码来自本地 kb_permission_grant。
 *
 * $code 支持逗号分隔多个权限码，任一命中即放行（如空间列表允许 manage 或 create）；
 * 单个权限码的原有写法保持兼容。
 */
class PermissionCheck
{
    public function handle($request, \Closure $next, string $code)
    {
        $profile = $request->userProfile ?? null;
        if (!$profile) {
            return auth_expired_response();
        }

        $required = array_values(array_filter(array_map('trim', explode(',', $code))));
        $owned    = $profile['permissions'] ?? [];
        if (!empty($profile['isSuperAdmin']) || array_intersect($required, $owned) !== []) {
            return $next($request);
        }
        return resp_fail('无权限执行该操作', 403);
    }
}
