<?php
declare(strict_types=1);

namespace app;

use think\Service;

/**
 * 应用服务：在全局配置加载完成后注入运行时配置。
 */
class AppService extends Service
{
    public function register()
    {
    }

    public function boot()
    {
        // 操作日志来源 IP：本机回环始终可信；反向代理不在本机时，
        // 由 myconfig.oplog.trusted_proxies 追加可信 IP / CIDR。
        $trustedProxies = config('myconfig.oplog.trusted_proxies', []);
        if (is_array($trustedProxies) && $trustedProxies !== []) {
            Request::setTrustedProxies($trustedProxies);
        }
    }
}
