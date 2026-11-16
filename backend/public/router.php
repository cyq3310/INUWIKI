<?php
// PHP 内置服务器路由文件（仅本地开发用，生产由 Nginx 承接）

// 上传资源禁止由内置服务器静态直出：本地也必须与生产一致，
// 统一经 /kbapi/assets 校验资源票据后才能读取，否则知道 path 即可绕过文章可见性。
$config = require __DIR__ . '/../config/myconfig.php';
$assetPrefix = rtrim((string) ($config['knowledge']['upload_prefix'] ?? ''), '/');
if ($assetPrefix === '') {
    $assetPrefix = rtrim((string) ($config['upload']['url_prefix'] ?? '/uploads'), '/') . '/knowledge';
}
$requestPath = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''));
if ($requestPath === $assetPrefix || str_starts_with($requestPath, $assetPrefix . '/')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    return true;
}
unset($config, $assetPrefix, $requestPath);

if (is_file($_SERVER["DOCUMENT_ROOT"] . $_SERVER["SCRIPT_NAME"])) {
    return false;
} else {
    $_SERVER["SCRIPT_FILENAME"] = __DIR__ . '/index.php';

    require __DIR__ . "/index.php";
}
