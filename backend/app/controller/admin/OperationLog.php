<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use think\facade\Db;

/**
 * 后台 · 操作日志查询（只读，所有已登录用户可访问）。
 *
 * 优先查询按月分区的 operation_log 表；若迁移尚未执行、表不可读或暂无数据，
 * 自动回退读取 runtime/oplog 下已有的 JSON Lines 文件日志，避免升级期间页面空白。
 */
class OperationLog extends BaseController
{
    /** 文件日志回退最多扫描的行数，避免极端情况下拖慢查询 */
    private const FILE_LOG_MAX_LINES = 20000;

    public function index()
    {
        $page = max(1, (int) $this->request->get('page', 1));
        $size = min(100, max(1, (int) $this->request->get('size', 10)));
        $empty = [
            'total'   => 0,
            'page'    => $page,
            'size'    => $size,
            'list'    => [],
            'actions' => [],
            'source'  => 'empty',
            'notice'  => '暂无操作日志，完成一次后台写操作后会自动显示。',
        ];
        $filters = $this->filters();
        $filters['space_ids'] = $this->visibleSpaceIds();

        // 未跑迁移时自动补建分区表，避免数据库里始终没有 operation_log
        if (function_exists('kb_ensure_oplog_table')) {
            kb_ensure_oplog_table();
        }

        try {
            $db = $this->queryDatabase($page, $size, $filters);
            if ($db['total'] > 0) {
                return resp_ok($this->withMeta($db, 'database', ''));
            }

            // 数据库还没有数据时，用已有文件日志兜底
            $file = $this->queryFiles($page, $size, $filters);
            $file['actions'] = $this->mergeActions($db['actions'], $file['actions']);

            // 首次打开且两个数据源都为空时写一条初始化记录，避免界面长期空白
            if ($db['total'] === 0 && $file['total'] === 0 && !$this->hasFilters($filters)) {
                $this->seedInitialLog();
                try {
                    $db = $this->queryDatabase($page, $size, $filters);
                    if ($db['total'] > 0) {
                        return resp_ok($this->withMeta(
                            $db,
                            'init',
                            '操作日志表已自动初始化，后续后台写操作会同时写入数据库和文件日志。'
                        ));
                    }
                } catch (\Throwable $ignored) {
                    // 继续用文件日志展示初始化记录
                }
                $file = $this->queryFiles($page, $size, $filters);
                if ($file['total'] > 0) {
                    return resp_ok($this->withMeta($file, 'file', '数据库表不可用，当前显示文件操作日志。'));
                }
            }

            $notice = $file['total'] === 0
                ? ($this->hasFilters($filters) ? '未找到符合条件的操作日志。' : $empty['notice'])
                : '';
            return resp_ok($this->withMeta($file, $file['total'] > 0 ? 'file' : 'empty', $notice));
        } catch (\Throwable $e) {
            \think\facade\Log::error('操作日志数据库查询失败，回退文件日志: ' . $e->getMessage());
            try {
                $file = $this->queryFiles($page, $size, $filters);
                if ($file['total'] === 0 && !$this->hasFilters($filters)) {
                    $this->seedInitialLog();
                    $file = $this->queryFiles($page, $size, $filters);
                }
                $notice = $file['total'] === 0
                    ? ($this->hasFilters($filters) ? '未找到符合条件的操作日志。' : $empty['notice'])
                    : '数据库表不可用，当前显示文件操作日志。';
                return resp_ok($this->withMeta($file, $file['total'] > 0 ? 'file' : 'empty', $notice));
            } catch (\Throwable $fileError) {
                \think\facade\Log::error('操作日志文件回退失败: ' . $fileError->getMessage());
                return resp_ok($empty);
            }
        }
    }

    private function hasFilters(array $f): bool
    {
        foreach (['keyword', 'username', 'action', 'result', 'date_from', 'date_to'] as $key) {
            if ((string) ($f[$key] ?? '') !== '') {
                return true;
            }
        }
        return false;
    }

    /** 首次打开且两个数据源都为空时生成一条可见的初始化记录 */
    private function seedInitialLog(): void
    {
        if (!function_exists('write_oplog')) {
            return;
        }
        write_oplog('kb.oplog.init', '操作日志组件初始化', '', [
            'method'      => 'GET',
            'path'        => 'kbapi/admin/operation-logs',
            'result'      => 'success',
            'object_type' => 'system',
            'object_id'   => 0,
            'duration_ms' => 0,
        ]);
    }

    private function withMeta(array $data, string $source, string $notice): array
    {
        $data['list']   = $this->attachOperatorIds($this->attachObjectLabels($this->attachSpaceNames($data['list'] ?? [])));
        $data['source'] = $source;
        $data['notice'] = $notice;
        return $data;
    }

    /** 当前用户可见空间 + 全局日志（space_id=0） */
    private function visibleSpaceIds(): array
    {
        $ids = [];
        try {
            $ids = (new \app\service\SpaceService())->visibleSpaceIds(
                kb_user_id(),
                kb_role_ids(),
                kb_is_super()
            );
        } catch (\Throwable $e) {
            \think\facade\Log::error('操作日志可见空间计算失败: ' . $e->getMessage());
        }
        return array_values(array_unique(array_merge([0], array_map('intval', $ids))));
    }

    /** 给日志列表补充空间名称：0 显示「全局」，避免把全局日志显示成「全部」 */
    private function attachSpaceNames(array $list): array
    {
        $ids = [];
        foreach ($list as $row) {
            $spaceId = (int) ($row['space_id'] ?? 0);
            if ($spaceId > 0) {
                $ids[] = $spaceId;
            }
        }
        $names = $ids !== [] ? \app\model\Space::whereIn('id', array_unique($ids))->column('name', 'id') : [];

        foreach ($list as &$row) {
            $spaceId = (int) ($row['space_id'] ?? 0);
            $row['space_name'] = $spaceId === 0
                ? '全局'
                : ((string) ($names[$spaceId] ?? ('空间 #' . $spaceId)));
        }
        unset($row);
        return $list;
    }

    /** 历史登录日志 user_id=0：按登录账号从人员目录回填展示用 ID */
    private function attachOperatorIds(array $list): array
    {
        $usernames = [];
        foreach ($list as $row) {
            if ((int) ($row['user_id'] ?? 0) <= 0) {
                $username = trim((string) ($row['username'] ?? ''));
                if ($username !== '') {
                    $usernames[$username] = $username;
                }
            }
        }

        $byCode = [];
        $byName = [];
        if ($usernames !== []) {
            try {
                $byCode = Db::name('kb_subject')
                    ->where('subject_type', 'user')
                    ->whereIn('code', array_values($usernames))
                    ->column('subject_id', 'code');
            } catch (\Throwable $e) {
                \think\facade\Log::error('操作日志账号回填失败: ' . $e->getMessage());
            }
            try {
                $byName = Db::name('kb_subject')
                    ->where('subject_type', 'user')
                    ->whereIn('name', array_values($usernames))
                    ->column('subject_id', 'name');
            } catch (\Throwable $e) {
                \think\facade\Log::error('操作日志姓名回填失败: ' . $e->getMessage());
            }
        }

        foreach ($list as &$row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0) {
                $username = trim((string) ($row['username'] ?? ''));
                $userId = (int) ($byCode[$username] ?? $byName[$username] ?? 0);
            }
            $row['display_user_id'] = $userId;
        }
        unset($row);
        return $list;
    }

    /** 日志对象类型展示名；未知类型回退为「对象」 */
    private const OBJECT_LABELS = [
        'article'    => '文章',
        'space'      => '空间',
        'category'   => '目录',
        'doc_type'   => '文档类型',
        'user'       => '人员',
        'role'       => '角色',
        'subject'    => '授权对象',
        'permission' => '权限',
        'version'    => '历史版本',
        'attachment' => '附件',
        'upload'     => '上传',
        'system'     => '系统',
    ];

    /** 给日志列表补充 object_type / object_label，旧日志按 action + path 推导 */
    private function attachObjectLabels(array $list): array
    {
        $types = [];
        foreach ($list as &$row) {
            $type = $this->normalizeObjectType($row);
            $row['object_type'] = $type;
            $row['object_label'] = $this->objectLabel($type, (int) ($row['object_id'] ?? 0), '');
            $types[$type][] = (int) ($row['object_id'] ?? 0);
        }
        unset($row);

        $names = $this->resolveObjectNames($types);
        foreach ($list as &$row) {
            $type = (string) ($row['object_type'] ?? '');
            $id   = (int) ($row['object_id'] ?? 0);
            $row['object_label'] = $this->objectLabel($type, $id, (string) ($names[$type][$id] ?? ''));
        }
        unset($row);
        return $list;
    }

    private function normalizeObjectType(array $row): string
    {
        $type = strtolower(trim((string) ($row['object_type'] ?? '')));
        if (isset(self::OBJECT_LABELS[$type])) {
            return $type;
        }

        $action = (string) ($row['action'] ?? '');
        $path   = (string) ($row['path'] ?? '');
        if ($action === 'kb.oplog.init') {
            return 'system';
        }
        if (strpos($action, 'auth.') === 0) {
            return 'user';
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
            return strpos($path, '/members') !== false ? 'subject' : 'space';
        }
        if (strpos($action, 'kb.category.') === 0) {
            return 'category';
        }
        if (strpos($action, 'kb.doc-type.') === 0) {
            return 'doc_type';
        }
        if (strpos($action, 'kb.permission.') === 0) {
            return 'subject';
        }
        if (strpos($action, 'kb.upload.') === 0) {
            return strpos($action, 'init') !== false ? 'upload' : 'attachment';
        }
        return '';
    }

    /** 按类型批量取对象名称，失败时保持空名并仍显示「类型 #ID」 */
    private function resolveObjectNames(array $groups): array
    {
        $names = [];
        foreach ($groups as $type => $ids) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if ($ids === []) {
                continue;
            }
            try {
                switch ($type) {
                    case 'article':
                        $names[$type] = Db::name('kb_article')->whereIn('id', $ids)->column('title', 'id');
                        break;
                    case 'space':
                        $names[$type] = \app\model\Space::whereIn('id', $ids)->column('name', 'id');
                        break;
                    case 'category':
                        $names[$type] = Db::name('kb_category')->whereIn('id', $ids)->column('name', 'id');
                        break;
                    case 'doc_type':
                        $names[$type] = Db::name('kb_doc_type')->whereIn('id', $ids)->column('name', 'id');
                        break;
                    case 'user':
                    case 'role':
                        $names[$type] = Db::name('kb_subject')
                            ->where('subject_type', $type)
                            ->whereIn('subject_id', $ids)
                            ->column('name', 'subject_id');
                        break;
                    case 'attachment':
                        $names[$type] = Db::name('kb_attachment')->whereIn('id', $ids)->column('original_name', 'id');
                        break;
                    case 'version':
                        $names[$type] = Db::name('kb_article_version')->whereIn('id', $ids)->column('title', 'id');
                        break;
                }
            } catch (\Throwable $e) {
                \think\facade\Log::error('操作日志对象名称解析失败(' . $type . '): ' . $e->getMessage());
                $names[$type] = [];
            }
        }
        return $names;
    }

    private function objectLabel(string $type, int $id, string $name): string
    {
        $label = self::OBJECT_LABELS[$type] ?? ($type !== '' ? '对象' : '');
        $name  = trim($name);
        if ($name !== '') {
            $label .= '：' . mb_substr($name, 0, 40);
        }
        if ($id > 0) {
            $label .= ($name !== '' ? ' ' : '') . '(#' . $id . ')';
        }
        return $label !== '' ? $label : '—';
    }

    private function filters(): array
    {
        $from = $this->validDay($this->param('date_from'));
        $to   = $this->validDay($this->param('date_to'));
        return [
            'keyword'       => $this->param('keyword'),
            'username'      => $this->param('username'),
            'action'        => $this->param('action'),
            'result'        => $this->param('result'),
            'date_from'     => $from,
            'date_to'       => $to,
            'date_from_sql' => $this->dayBoundary($from, false),
            'date_to_sql'   => $this->dayBoundary($to, true),
        ];
    }

    private function queryDatabase(int $page, int $size, array $f): array
    {
        $query = Db::name('operation_log');

        // space_id=0 为全局操作；受限空间日志只对可见该空间的用户开放
        $query->whereIn('space_id', $f['space_ids'] ?? [0]);

        $keyword = (string) $f['keyword'];
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->whereRaw(
                '(`username` LIKE ? OR `action` LIKE ? OR `detail` LIKE ? OR `ip` LIKE ? OR `path` LIKE ?)',
                [$like, $like, $like, $like, $like]
            );
        }
        if ((string) $f['username'] !== '') {
            $query->where('username', (string) $f['username']);
        }
        if ((string) $f['action'] !== '') {
            $query->where('action', (string) $f['action']);
        }
        if ((string) $f['result'] !== '') {
            $query->where('result', (string) $f['result']);
        }
        if ($f['date_from_sql'] !== null) {
            $query->whereRaw("created_at >= '{$f['date_from_sql']}'");
        }
        if ($f['date_to_sql'] !== null) {
            $query->whereRaw("created_at < '{$f['date_to_sql']}'");
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('created_at', 'desc')
            ->order('id', 'desc')
            ->page($page, $size)
            ->select()
            ->toArray();

        return [
            'total'   => $total,
            'page'    => $page,
            'size'    => $size,
            'list'    => $list,
            'actions' => $this->actions(),
        ];
    }

    private function queryFiles(int $page, int $size, array $f): array
    {
        $rows = $this->readFileRows($f);
        $total = count($rows);
        $list  = array_slice($rows, ($page - 1) * $size, $size);
        $actions = array_values(array_unique(array_filter(
            array_map(static fn (array $row): string => (string) ($row['action'] ?? ''), $rows)
        )));
        sort($actions, SORT_STRING);

        return [
            'total'   => $total,
            'page'    => $page,
            'size'    => $size,
            'list'    => $list,
            'actions' => $actions,
        ];
    }

    private function readFileRows(array $f): array
    {
        $dir = function_exists('kb_oplog_dir') ? kb_oplog_dir() : '';
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }

        $rows      = [];
        $sequence  = 0;
        $lineCount = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if (!$item->isFile() || strtolower($item->getExtension()) !== 'log') {
                    continue;
                }
                $file = new \SplFileObject($item->getPathname(), 'r');
                while (!$file->eof()) {
                    if ($lineCount++ >= self::FILE_LOG_MAX_LINES) {
                        break 2;
                    }
                    $line = trim((string) $file->fgets());
                    if ($line === '') {
                        continue;
                    }
                    $data = json_decode($line, true);
                    if (!is_array($data)) {
                        continue;
                    }
                    $timestamp = strtotime((string) ($data['time'] ?? ''));
                    if (!$timestamp) {
                        continue;
                    }
                    $row = [
                        'id'          => ++$sequence,
                        'user_id'     => (int) ($data['user_id'] ?? 0),
                        'username'    => (string) ($data['username'] ?? ''),
                        'action'      => (string) ($data['action'] ?? ''),
                        'detail'      => (string) ($data['detail'] ?? ''),
                        'ip'          => (string) ($data['ip'] ?? ''),
                        'method'      => (string) ($data['method'] ?? ''),
                        'path'        => (string) ($data['path'] ?? ''),
                        'result'      => (string) ($data['result'] ?? 'success'),
                        'object_type' => (string) ($data['object_type'] ?? ''),
                        'object_id'   => (int) ($data['object_id'] ?? 0),
                        'space_id'    => (int) ($data['space_id'] ?? 0),
                        'duration_ms' => (int) ($data['duration_ms'] ?? 0),
                        'created_at'  => date('Y-m-d H:i:s', $timestamp),
                    ];
                    if ($this->matchFilters($row, $f)) {
                        $rows[] = $row;
                    }
                }
            }
        } catch (\Throwable $e) {
            // 文件日志目录不可读时返回已解析部分，不阻断接口
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp((string) $b['created_at'], (string) $a['created_at']);
            return $cmp !== 0 ? $cmp : ((int) $b['id'] <=> (int) $a['id']);
        });
        return $rows;
    }

    private function matchFilters(array $row, array $f): bool
    {
        $spaceId = (int) ($row['space_id'] ?? 0);
        if (!in_array($spaceId, $f['space_ids'] ?? [0], true)) {
            return false;
        }
        if ((string) $f['username'] !== '' && (string) ($row['username'] ?? '') !== (string) $f['username']) {
            return false;
        }
        if ((string) $f['action'] !== '' && (string) ($row['action'] ?? '') !== (string) $f['action']) {
            return false;
        }
        if ((string) $f['result'] !== '' && (string) ($row['result'] ?? '') !== (string) $f['result']) {
            return false;
        }
        if ((string) $f['keyword'] !== '') {
            $haystack = implode(' ', [
                (string) ($row['username'] ?? ''),
                (string) ($row['action'] ?? ''),
                (string) ($row['detail'] ?? ''),
                (string) ($row['ip'] ?? ''),
                (string) ($row['path'] ?? ''),
            ]);
            if (stripos($haystack, (string) $f['keyword']) === false) {
                return false;
            }
        }
        $day = substr((string) ($row['created_at'] ?? ''), 0, 10);
        if ((string) $f['date_from'] !== '' && $day < (string) $f['date_from']) {
            return false;
        }
        if ((string) $f['date_to'] !== '' && $day > (string) $f['date_to']) {
            return false;
        }
        return true;
    }

    private function param(string $key): string
    {
        $value = $this->request->get($key, '');
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function validDay(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    /** 日期参数严格校验后转成 SQL 字面量，既安全又能命中 RANGE 分区裁剪 */
    private function dayBoundary(string $value, bool $exclusiveNextDay): ?string
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            return null;
        }
        if ($exclusiveNextDay) {
            $date = $date->modify('+1 day');
        }
        return $date->format('Y-m-d 00:00:00');
    }

    /** 前端动作下拉选项 */
    private function actions(): array
    {
        $table = (string) config('database.connections.kb.prefix', '') . 'operation_log';
        $rows  = Db::query("SELECT DISTINCT `action` FROM `{$table}` ORDER BY `action`");
        return array_values(array_map('strval', array_column($rows, 'action')));
    }

    private function mergeActions(array ...$groups): array
    {
        $merged = array_values(array_unique(array_filter(array_merge(...$groups), 'strlen')));
        sort($merged, SORT_STRING);
        return $merged;
    }
}
