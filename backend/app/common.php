<?php
// 应用公共文件（helper 命名与 INUEIP 保持一致，便于对照维护）

use think\Response;

/**
 * 统一成功响应 {code:0, msg, data}
 */
function resp_ok($data = [], string $msg = 'ok'): Response
{
    return json(['code' => 0, 'msg' => $msg, 'data' => $data]);
}

/**
 * 统一失败响应 {code:非0, msg, data}
 */
function resp_fail(string $msg = 'error', int $code = 400, $data = null, int $httpStatus = 200): Response
{
    return json(['code' => $code, 'msg' => $msg, 'data' => $data], $httpStatus);
}

/**
 * 登录凭证（JWT / 资源票据）失效时的统一响应：
 * - 浏览器文档导航（下载链接、地址栏直开资源）→ 302 回前端登录页，避免展示 JSON 401
 * - 其余请求（XHR、<img>、<video>）→ 保持 {code:401} 并打标记，由前端统一跳登录
 */
function auth_expired_response(string $msg = '未登录或登录已过期'): Response
{
    $request = request();
    $accept  = (string) $request->header('Accept', '');
    $isDoc   = stripos($accept, 'text/html') !== false
        || (string) $request->header('Sec-Fetch-Dest', '') === 'document';

    if ($isDoc) {
        return response('', 302)->header([
            'Location'      => kb_login_url(),
            'Cache-Control' => 'no-store',
        ]);
    }

    return resp_fail($msg, 401)->header(['X-Kb-Auth-Expired' => '1']);
}

/** 登录页绝对地址：优先取 Referer 的来源（用户实际访问的前端地址），回落部署配置 */
function kb_login_url(): string
{
    $origin  = '';
    $referer = (string) request()->header('Referer', '');
    if ($referer !== '') {
        $parts = parse_url($referer);
        if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
            $origin = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
        }
    }
    if ($origin === '') {
        $origin = rtrim((string) config('myconfig.deploy.web.base_url'), '/');
    }
    return rtrim($origin, '/') . '/login';
}

/**
 * 上传资源访问前缀（取自 myconfig）
 *
 * @param string $group myconfig 中的资源分组，如 knowledge
 */
function upload_prefix(string $group): string
{
    $defaults = ['knowledge' => '/uploads/knowledge'];
    return (string) (config('myconfig.' . $group . '.upload_prefix') ?: ($defaults[$group] ?? '/uploads/' . $group));
}

/**
 * 业务分组相对 upload_root 的子目录（去掉 url_prefix 部分）
 *
 * 例：upload_prefix=/uploads/knowledge、url_prefix=/uploads → 'knowledge'
 */
function upload_group_rel(string $group): string
{
    $prefix = trim(upload_prefix($group), '/');
    $base   = trim((string) (config('myconfig.upload.url_prefix') ?: '/uploads'), '/');

    if ($base !== '' && str_starts_with($prefix, $base . '/')) {
        $prefix = substr($prefix, strlen($base) + 1);
    }
    return trim($prefix, '/');
}

/**
 * 上传资源物理目录：upload_root/{groupRel}/{subDir}
 *
 * 例：root=/.../public/uploads、upload_prefix=/uploads/knowledge
 *     → upload_dir('knowledge', 'cover') = /.../public/uploads/knowledge/cover
 */
function upload_dir(string $group, string $subDir = ''): string
{
    $dir = upload_root() . '/' . upload_group_rel($group);
    return $subDir === '' ? $dir : $dir . '/' . $subDir;
}

/**
 * 业务分组下「DB 相对路径」对应的绝对磁盘路径。
 *
 * DB 只存相对 upload_prefix 的路径（如 image/2026/11/x.png，历史为 image/20261113/x.png），
 * 落盘、播放、回收站都必须经此函数换算，避免前缀错位。
 */
function upload_abs_path(string $group, string $relative): string
{
    $rel      = ltrim(str_replace('\\', '/', $relative), '/');
    $groupRel = upload_group_rel($group);
    $suffix   = $groupRel !== '' ? $groupRel . '/' . $rel : $rel;
    return upload_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $suffix);
}

/**
 * 允许上传的扩展名（逗号串）
 */
function upload_allowed_ext(string $type = 'image'): string
{
    $key = ['image' => 'image_ext', 'video' => 'video_ext', 'file' => 'file_ext'][$type] ?? 'image_ext';
    $ext = config('myconfig.knowledge.' . $key, []);
    if (is_array($ext) && $ext !== []) {
        return implode(',', $ext);
    }
    return (string) $ext ?: 'jpg,jpeg,png,webp,gif';
}

/**
 * 单文件上传上限（字节）
 */
function upload_max_size(string $type = 'image'): int
{
    $key = ['image' => 'image_max', 'video' => 'video_max', 'file' => 'file_max'][$type] ?? 'image_max';
    return (int) (config('myconfig.knowledge.' . $key) ?: config('myconfig.upload.max_size') ?: 10485760);
}

/**
 * 上传资源物理根目录（绝对路径）
 */
function upload_root(): string
{
    return rtrim((string) (config('myconfig.upload.root') ?: root_path() . 'public/uploads'), '/\\');
}

/**
 * 统一回收站根目录（绝对路径）
 */
function trash_root(): string
{
    return rtrim((string) (config('myconfig.trash.root') ?: upload_root() . '/trash'), '/\\');
}

/**
 * 回收站内某业务分组目录：trash_root/{group}
 */
function trash_group_dir(string $group): string
{
    return trash_root() . '/' . $group;
}

/**
 * 某资源分组的暂存目录（绝对路径）：upload.root/{group前缀}/_tmp
 */
function temp_dir(string $group): string
{
    $name = (string) (config('myconfig.trash.temp_dir') ?: '_tmp');
    return upload_dir($group, $name);
}

/**
 * 校验目标路径位于 upload_root 之内，杜绝目录穿越
 */
function path_within_upload_root(string $path): bool
{
    $realRoot = realpath(upload_root());
    $realPath = realpath($path);
    if ($realRoot === false || $realPath === false) {
        return false;
    }
    return str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR);
}

/**
 * 把位于 upload.root 下的相对路径（文件或目录）整体移入统一回收站。
 * 失败仅记 warning 日志并返回 false，不抛异常——删除接口不应因磁盘异常返回 500。
 */
function move_to_trash(string $group, string $sourceRelative, ?string $label = null): bool
{
    try {
        $realRoot = realpath(upload_root());
        if ($realRoot === false) {
            \think\facade\Log::warning('move_to_trash 上传根目录不存在');
            return false;
        }
        // 源文件以业务分组根为基准；兼容历史上直接落在 upload_root 下的老文件
        $src = upload_abs_path($group, $sourceRelative);
        if (!(is_file($src) || is_dir($src))) {
            $legacy = $realRoot . DIRECTORY_SEPARATOR . ltrim($sourceRelative, '/\\');
            if (is_file($legacy) || is_dir($legacy)) {
                $src = $legacy;
            }
        }
        $realSrc = (is_file($src) || is_dir($src)) ? realpath($src) : false;
        if ($realSrc === false || !path_within_upload_root($realSrc)) {
            \think\facade\Log::warning('move_to_trash 跳过越界或不存在的路径: ' . $sourceRelative);
            return false;
        }

        $groupDir = trash_group_dir($group);
        if (!is_dir($groupDir) && !mkdir($groupDir, 0755, true) && !is_dir($groupDir)) {
            \think\facade\Log::warning('move_to_trash 回收站分组目录创建失败: ' . $groupDir);
            return false;
        }

        $stamp = date('YmdHis');
        $rand  = bin2hex(random_bytes(3));
        $base  = $label ?? basename($realSrc);
        $safe  = preg_replace('/[^\w\-]/', '_', $base);

        if (is_dir($realSrc)) {
            $target = $groupDir . DIRECTORY_SEPARATOR . $safe . '_' . $stamp . '_' . $rand;
            while (is_dir($target) || is_file($target)) {
                $target .= '_' . bin2hex(random_bytes(2));
            }
            return rename($realSrc, $target);
        }

        $targetDir = $groupDir . DIRECTORY_SEPARATOR . $safe . '_' . $stamp . '_' . $rand;
        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            \think\facade\Log::warning('move_to_trash 回收目标目录创建失败: ' . $targetDir);
            return false;
        }
        return rename($realSrc, $targetDir . DIRECTORY_SEPARATOR . basename($realSrc));
    } catch (\Throwable $e) {
        \think\facade\Log::warning('move_to_trash 异常: ' . $e->getMessage());
        return false;
    }
}

/**
 * 彻底删除目录（递归），用于 GC 清理回收站超期条目
 */
function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($full)) {
            remove_dir($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($dir);
}

/**
 * 当前登录用户档案（由 AuthCheck 中间件注入到请求）
 */
function kb_profile(): array
{
    return request()->userProfile ?? [];
}

/**
 * 当前登录用户 ID（对应 inue_user.id）
 */
function kb_user_id(): int
{
    return (int) (request()->userId ?? 0);
}

/**
 * 当前用户角色 ID 集合（空间按角色授权时用 inue_role.id 匹配）
 */
function kb_role_ids(): array
{
    return array_map('intval', kb_profile()['roleIds'] ?? []);
}

/**
 * 是否超管（超管绕过空间与文章的全部限制）
 */
function kb_is_super(): bool
{
    return !empty(kb_profile()['isSuperAdmin']);
}

/**
 * 当前请求的「前端顶栏选中空间」（X-Space-Id），0 = 全部空间 / 全局配置
 */
function kb_space_id(): int
{
    return (int) (kb_profile()['spaceId'] ?? 0);
}

/**
 * 按指定空间重新计算有效权限。
 * 单篇操作必须按「文章所属空间」判定，不能直接用请求头空间，否则跨空间操作会用错配置。
 */
function kb_permissions_for_space(int $spaceId): array
{
    if (kb_is_super()) {
        return \app\service\PermissionCatalog::codes();
    }
    return (new \app\service\RbacService())->effectivePermissions(
        kb_user_id(),
        kb_role_ids(),
        false,
        max(0, $spaceId)
    );
}

/** 操作日志目录：默认 runtime/oplog，可在 myconfig.oplog.dir 配置绝对路径 */
function kb_oplog_dir(): string
{
    $dir = trim((string) config('myconfig.oplog.dir'));
    if ($dir === '') {
        return rtrim(root_path(), '/\\') . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'oplog';
    }
    if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $dir) !== 1) {
        $dir = rtrim(root_path(), '/\\') . DIRECTORY_SEPARATOR . ltrim($dir, '/\\');
    }
    return rtrim($dir, '/\\');
}

/** 操作日志表名（WIKI 默认 prefix 为空，即 operation_log） */
function kb_oplog_table_name(): string
{
    return (string) config('database.connections.kb.prefix', '') . 'operation_log';
}

function kb_oplog_table_exists(): bool
{
    $rows = \think\facade\Db::query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        [kb_oplog_table_name()]
    );
    return $rows !== [];
}

/** 兼容未执行迁移的旧表：缺 object_type 列时运行时补列 */
function kb_ensure_oplog_object_type(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!kb_oplog_table_exists()) {
        return $ready = false;
    }

    try {
        $table = kb_oplog_table_name();
        $columns = \think\facade\Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'object_type'");
        if ($columns === []) {
            \think\facade\Db::execute(
                "ALTER TABLE `{$table}` ADD COLUMN `object_type` VARCHAR(40) NOT NULL DEFAULT '' COMMENT '操作对象类型' AFTER `object_id`"
            );
            // fields_cache 开启时不清缓存，ORM 会继续按旧表结构过滤新列
            \think\facade\Cache::clear();
        }
        return $ready = true;
    } catch (\Throwable $e) {
        \think\facade\Log::error('操作日志表补列失败: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * 操作日志表自愈：未执行迁移时，运行时自动创建与 EIP 同构的按月 RANGE 分区表。
 * 仅在当前请求内缓存成功结果；失败不抛异常，仍允许写文件日志。
 */
function kb_ensure_oplog_table(): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }

    try {
        if (kb_oplog_table_exists()) {
            return $ready = kb_ensure_oplog_object_type();
        }

        $table = kb_oplog_table_name();
        $definitions = [];
        $cursor = new \DateTimeImmutable(date('Y-m-01'));
        for ($i = 0; $i <= 6; $i++) {
            $key      = $cursor->format('Ym');
            $boundary = $cursor->modify('first day of next month')->format('Y-m-d');
            $definitions[] = "PARTITION `p{$key}` VALUES LESS THAN (TO_DAYS('{$boundary}'))";
            $cursor = $cursor->modify('first day of next month');
        }
        $definitions[] = 'PARTITION `pmax` VALUES LESS THAN MAXVALUE';

        \think\facade\Db::execute(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'EIP 用户 ID',
                `username` VARCHAR(50) NULL DEFAULT NULL COMMENT '登录名快照',
                `action` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '模块.动作',
                `detail` TEXT NULL COMMENT '操作描述，不含敏感请求体',
                `ip` VARCHAR(45) NULL DEFAULT NULL COMMENT '来源 IP',
                `method` VARCHAR(12) NULL DEFAULT NULL COMMENT 'HTTP 方法',
                `path` VARCHAR(200) NULL DEFAULT NULL COMMENT '请求路径',
                `result` VARCHAR(16) NOT NULL DEFAULT 'success' COMMENT 'success / fail',
                `object_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作对象 ID',
                `object_type` VARCHAR(40) NOT NULL DEFAULT '' COMMENT '操作对象类型',
                `space_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当前空间 ID',
                `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '接口耗时毫秒',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                PRIMARY KEY (`id`, `created_at`),
                INDEX `idx_oplog_user_created` (`user_id`, `created_at`),
                INDEX `idx_oplog_created` (`created_at`),
                INDEX `idx_oplog_action` (`action`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台操作日志表'
            PARTITION BY RANGE (TO_DAYS(`created_at`)) (" . implode(', ', $definitions) . ')'
        );

        return $ready = kb_oplog_table_exists() && kb_ensure_oplog_object_type();
    } catch (\Throwable $e) {
        \think\facade\Log::error('操作日志表自动创建失败: ' . $e->getMessage());
        return false;
    }
}

/** 操作日志字段清洗：去控制字符、压平换行、按长度截断，避免日志注入与大字段 */
function kb_oplog_text(string $value, int $max = 2000): string
{
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, max(1, $max));
}

/**
 * 操作日志双写：独立文件（JSON Lines）+ 数据库分区表。
 * 只记录白名单上下文，绝不写请求体、正文、密码、Token、Authorization 或 Cookie。
 * 文件与数据库分别独立容错，任一写入失败都不阻断业务主流程（与 EIP write_oplog 一致）。
 */
function write_oplog(string $action, string $detail = '', string $username = '', array $context = []): void
{
    $row = [];
    try {
        $request = request();
        $profile = $request->userProfile ?? [];
        $userId  = (int) ($request->userId ?? 0);
        if ($username === '') {
            $username = (string) ($profile['username'] ?? $profile['nickname'] ?? '');
        }

        $detailMax = max(200, (int) (config('myconfig.oplog.detail_max') ?: 2000));
        $row = [
            'time'     => date('c'),
            'user_id'  => $userId,
            'username' => kb_oplog_text($username, 50),
            'action'   => kb_oplog_text($action, 80),
            'detail'   => kb_oplog_text($detail, $detailMax),
            'ip'       => kb_oplog_text((string) $request->ip(), 64),
            'method'   => kb_oplog_text((string) $request->method(), 12),
            'path'     => kb_oplog_text((string) $request->pathinfo(), 200),
            'result'   => 'success',
        ];

        $allowedContext = ['user_id', 'method', 'path', 'result', 'object_type', 'object_id', 'space_id', 'duration_ms'];
        foreach ($allowedContext as $key) {
            if (!array_key_exists($key, $context) || !is_scalar($context[$key])) {
                continue;
            }
            if (in_array($key, ['user_id', 'object_id', 'space_id', 'duration_ms'], true)) {
                $row[$key] = max(0, (int) $context[$key]);
            } else {
                $max = $key === 'path' ? 200 : ($key === 'object_type' ? 40 : 32);
                $row[$key] = kb_oplog_text((string) $context[$key], $max);
            }
        }
    } catch (\Throwable $e) {
        \think\facade\Log::error('操作日志字段生成失败: ' . $e->getMessage());
        return;
    }

    // 1) 文件日志：原有 JSON Lines 行为保持不变
    try {
        $dir = kb_oplog_dir() . DIRECTORY_SEPARATOR . date('Ym');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建操作日志目录');
        }
        $file = $dir . DIRECTORY_SEPARATOR . date('d') . '.log';
        $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            throw new \RuntimeException('操作日志 JSON 编码失败');
        }
        if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('操作日志文件写入失败');
        }
    } catch (\Throwable $e) {
        \think\facade\Log::error('操作日志文件写入失败: ' . $e->getMessage());
    }

    // 2) 数据库日志：与 EIP 同构，created_at 使用标准 SQL datetime
    if (config('myconfig.oplog.db_enabled') === false) {
        return;
    }
    try {
        if (!kb_ensure_oplog_table()) {
            \think\facade\Log::error('操作日志数据库表不可用，跳过数据库写入');
            return;
        }
        \app\model\OperationLog::create([
            'user_id'     => (int) ($row['user_id'] ?? 0),
            'username'    => (string) ($row['username'] ?? ''),
            'action'      => (string) ($row['action'] ?? ''),
            'detail'      => (string) ($row['detail'] ?? ''),
            'ip'          => (string) ($row['ip'] ?? ''),
            'method'      => (string) ($row['method'] ?? ''),
            'path'        => (string) ($row['path'] ?? ''),
            'result'      => (string) ($row['result'] ?? 'success'),
            'object_type' => (string) ($row['object_type'] ?? ''),
            'object_id'   => (int) ($row['object_id'] ?? 0),
            'space_id'    => (int) ($row['space_id'] ?? 0),
            'duration_ms' => (int) ($row['duration_ms'] ?? 0),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        \think\facade\Log::error('操作日志数据库写入失败: ' . $e->getMessage());
    }
}

/**
 * 清理统一回收站中超过保留期的分组目录（按目录 mtime 判断）
 */
function gc_trash(int $retentionDays, bool $dryRun = false): array
{
    $root    = trash_root();
    $scanned = 0;
    $would   = 0;

    if (is_dir($root)) {
        $threshold = time() - $retentionDays * 86400;
        foreach (scandir($root) ?: [] as $group) {
            if ($group === '.' || $group === '..') {
                continue;
            }
            $gdir = $root . DIRECTORY_SEPARATOR . $group;
            if (!is_dir($gdir)) {
                continue;
            }
            foreach (scandir($gdir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $gdir . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($full)) {
                    continue;
                }
                $scanned++;
                if (filemtime($full) < $threshold) {
                    $would++;
                    if (!$dryRun) {
                        remove_dir($full);
                    }
                }
            }
        }
    }

    return [
        'scanned'      => $scanned,
        'removed'      => $dryRun ? 0 : $would,
        'would_remove' => $would,
    ];
}
