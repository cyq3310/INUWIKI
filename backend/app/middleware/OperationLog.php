<?php
declare(strict_types=1);

namespace app\middleware;

/**
 * 后台写操作日志中间件。
 *
 * 只记录方法、路由、结果、对象 ID、耗时等白名单字段；不记录请求体、正文、
 * 密码、Token、Authorization、Cookie 或上传分片内容。挂载在 admin 路由分组，
 * 因此用户身份已由 AuthCheck 注入。
 */
class OperationLog
{
    private const WRITE_METHODS = ['POST', 'PUT', 'DELETE'];

    public function handle($request, \Closure $next)
    {
        $method = strtoupper((string) $request->method());
        if (!in_array($method, self::WRITE_METHODS, true)) {
            return $next($request);
        }

        $start = microtime(true);
        $response = $next($request);

        try {
            $path = trim((string) $request->pathinfo(), '/');
            $path = preg_replace('#^kbapi/admin/#', '', $path) ?? $path;
            [$action, $detail] = $this->describe($method, $path);
            if ($action === '') {
                return $response;
            }

            $payload = $this->responsePayload($response);
            $code    = (int) ($payload['code'] ?? 0);
            $status  = method_exists($response, 'getCode') ? (int) $response->getCode() : 200;
            $result  = ($code === 0 && $status < 400) ? 'success' : 'fail';

            // 只读取分类所需白名单字段，不写请求体、权限码或正文内容。
            $subjectType   = $this->subjectType($request);
            $subjectId     = $this->subjectId($request);
            $requestedSpace = $this->requestedSpaceId($request);
            $objectType    = $this->objectType($action, $path, $subjectType);

            write_oplog($action, $detail, '', [
                'method'      => $method,
                'path'        => $path,
                'result'      => $result,
                'object_type' => $objectType,
                'object_id'   => $this->objectId($payload, $path, $objectType, $subjectId),
                'space_id'    => $this->objectSpaceId($payload, $path, $action, $requestedSpace),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('操作日志中间件失败: ' . $e->getMessage());
        }

        return $response;
    }

    /** 由方法与路由 pattern 推导固定 action，避免把用户输入原样写入日志 */
    private function describe(string $method, string $path): array
    {
        $path = preg_replace('#^kbapi/admin/#', '', $path) ?? $path;
        $rules = [
            ['POST',   '#^spaces$#',                              'kb.space.create',              '创建空间'],
            ['PUT',    '#^spaces/\d+$#',                         'kb.space.update',              '更新空间'],
            ['DELETE', '#^spaces/\d+$#',                         'kb.space.delete',              '删除空间'],
            ['POST',   '#^spaces/\d+/members$#',                 'kb.space.set-member',          '配置空间成员'],
            ['DELETE', '#^spaces/\d+/members$#',                 'kb.space.remove-member',       '移除空间成员'],

            ['POST',   '#^categories$#',                         'kb.category.create',           '创建目录'],
            ['PUT',    '#^categories/\d+$#',                     'kb.category.update',           '更新目录'],
            ['DELETE', '#^categories/\d+$#',                     'kb.category.delete',           '删除目录'],

            ['POST',   '#^doc-types$#',                          'kb.doc-type.create',           '创建文档类型'],
            ['PUT',    '#^doc-types/\d+$#',                      'kb.doc-type.update',           '更新文档类型'],
            ['DELETE', '#^doc-types/\d+$#',                      'kb.doc-type.delete',           '删除文档类型'],

            ['POST',   '#^permissions$#',                        'kb.permission.save',           '保存权限配置'],
            ['POST',   '#^permissions/remove$#',                 'kb.permission.remove',         '移除权限配置'],

            ['POST',   '#^articles$#',                           'kb.article.create',            '创建文章'],
            ['PUT',    '#^articles/\d+$#',                       'kb.article.update',            '更新文章'],
            ['DELETE', '#^articles/\d+$#',                       'kb.article.delete',            '删除文章'],
            ['POST',   '#^articles/\d+/status$#',                'kb.article.set-status',        '调整文章状态'],
            ['POST',   '#^articles/\d+/grants$#',                'kb.article.set-grants',        '配置文章授权'],
            ['POST',   '#^articles/\d+/attachments$#',           'kb.article.attach',            '绑定文章附件'],
            ['POST',   '#^articles/\d+/restore$#',               'kb.article.restore',           '恢复文章'],
            ['POST',   '#^articles/\d+/purge$#',                 'kb.article.purge',             '彻底删除文章'],
            ['POST',   '#^articles/\d+/versions/\d+/restore$#',  'kb.article.restore-version',   '恢复历史版本'],

            ['POST',   '#^kb/uploads/init$#',                    'kb.upload.init',               '初始化上传'],
            ['POST',   '#^kb/uploads/complete$#',                'kb.upload.complete',           '完成上传'],
            ['POST',   '#^kb/images$#',                          'kb.upload.image',              '上传正文图片'],
        ];

        foreach ($rules as [$ruleMethod, $pattern, $action, $detail]) {
            if ($method === $ruleMethod && preg_match($pattern, $path) === 1) {
                return [$action, $detail];
            }
        }
        return ['', ''];
    }

    /** 授权类操作中的对象类型（role / user），仅取白名单值 */
    private function subjectType($request): string
    {
        $type = strtolower(trim((string) $request->param('subject_type', '')));
        return in_array($type, ['user', 'role'], true) ? $type : '';
    }

    /** 授权类操作中的对象 ID */
    private function subjectId($request): int
    {
        return max(0, (int) $request->param('subject_id', 0));
    }

    /** 请求显式携带的空间；权限配置允许 0（全局） */
    private function requestedSpaceId($request): int
    {
        return max(0, (int) $request->param('space_id', 0));
    }

    /** 由动作与路径推导对象类型，供日志界面区分「文章 / 权限 / 人员」等 */
    private function objectType(string $action, string $path, string $subjectType): string
    {
        if ($action === 'kb.oplog.init') {
            return 'system';
        }
        if ($action === 'kb.space.set-member' || $action === 'kb.space.remove-member'
            || $action === 'kb.permission.save' || $action === 'kb.permission.remove') {
            return $subjectType !== '' ? $subjectType : 'subject';
        }
        if (strpos($action, 'kb.article.') === 0) {
            if (strpos($path, '/versions/') !== false) {
                return 'version';
            }
            if (strpos($path, '/attachments') !== false) {
                return 'attachment';
            }
            return 'article';
        }
        if (strpos($action, 'kb.space.') === 0) {
            return 'space';
        }
        if (strpos($action, 'kb.category.') === 0) {
            return 'category';
        }
        if (strpos($action, 'kb.doc-type.') === 0) {
            return 'doc_type';
        }
        if (strpos($action, 'kb.upload.') === 0) {
            return strpos($action, 'init') !== false ? 'upload' : 'attachment';
        }
        return '';
    }

    /** 优先从统一响应的 data.id 取对象 ID，失败时回退到路径中的数字 ID */
    private function objectId(array $payload, string $path, string $objectType = '', int $subjectId = 0): int
    {
        if (in_array($objectType, ['user', 'role', 'subject'], true) && $subjectId > 0) {
            return $subjectId;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            foreach (['id', 'article_id', 'version_id', 'attachment_id', 'space_id', 'subject_id'] as $key) {
                if (isset($data[$key]) && is_scalar($data[$key])) {
                    return max(0, (int) $data[$key]);
                }
            }
        }

        if (preg_match('#^articles/\d+/versions/(\d+)(?:/|$)#', $path, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('#(?:^|/)(?:articles|spaces|categories|doc-types)/(\d+)(?:/|$)#', $path, $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }

    /** 优先取请求 / 响应 / 路由里的实际空间，普通写操作沿用请求头 X-Space-Id */
    private function objectSpaceId(array $payload, string $path, string $action = '', int $requestedSpace = 0): int
    {
        // 权限配置可能作用于全局（space_id=0），不能用顶栏当前空间代替
        if (strpos($action, 'kb.permission.') === 0) {
            return $requestedSpace;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data) && isset($data['space_id']) && is_scalar($data['space_id'])) {
            return max(0, (int) $data['space_id']);
        }
        if ($requestedSpace > 0) {
            return $requestedSpace;
        }
        if (preg_match('#^spaces/(\d+)(?:/|$)#', $path, $m) === 1) {
            return (int) $m[1];
        }
        return function_exists('kb_space_id') ? kb_space_id() : 0;
    }

    /** 兼容 Json Response 与已序列化响应，且不读取大体积响应体 */
    private function responsePayload($response): array
    {
        $data = method_exists($response, 'getData') ? $response->getData() : null;
        if (is_array($data)) {
            return $data;
        }
        if (!method_exists($response, 'getContent')) {
            return [];
        }
        $content = (string) $response->getContent();
        if ($content === '' || strlen($content) > 1024 * 1024) {
            return [];
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }
}
