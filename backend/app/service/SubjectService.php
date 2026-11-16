<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 角色 / 人员目录：读取 WIKI 本地快照 kb_subject。
 *
 * 展示字段由 `php think kb:subject-sync` 定时从 EIP 主库同步；人员的超管标记
 * 实时从 EIP 角色关系补充，EIP 不可用时降级为 false，不影响目录搜索。
 * 授权表（kb_article_editor / kb_article_viewer / kb_space_member）只存
 * subject_type + subject_id，名称统一从这里按 ID 解析。
 * 两种模式：
 *  - 搜索模式：keyword 模糊匹配，过滤停用 / 软删对象，最多 MAX_RESULTS 条；
 *  - 精确解析模式：传 ids，按 ID 返回名称（含已停用 / 软删的历史对象，保证旧授权仍可读）。
 */
class SubjectService
{
    /** 搜索模式最多返回条数；按 ID 精确解析不受此限制 */
    public const MAX_RESULTS = 20;
    public const MAX_IDS     = 100;

    public function search(string $type, string $keyword = '', array $ids = []): array
    {
        if (!in_array($type, ['role', 'user'], true)) {
            return [];
        }

        $query = Db::name('kb_subject')->where('subject_type', $type);
        $exact = $ids !== [];
        if ($exact) {
            $query->where('subject_id', 'in', $ids)->limit(count($ids));
        } else {
            $query->where('status', 1)->whereNull('deleted_at');
            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', '%' . $keyword . '%')
                        ->whereOr('code', 'like', '%' . $keyword . '%');
                });
            }
            $query->limit(self::MAX_RESULTS);
        }

        $rows = $query->field('subject_type, subject_id, name, code, status')
            ->order('subject_id', 'asc')
            ->select()
            ->toArray();

        $superIds = $type === 'user'
            ? (new RbacService())->superAdminUserIds(array_column($rows, 'subject_id'))
            : [];

        $label = $type === 'role' ? '角色' : '人员';
        $out   = [];
        foreach ($rows as $row) {
            $id   = (int) $row['subject_id'];
            $code = (string) ($row['code'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));
            $out[] = [
                'subject_type'  => $type,
                'subject_id'    => $id,
                'name'          => $name !== '' ? $name : ($code !== '' ? $code : $label . ' #' . $id),
                'code'          => $code,
                'status'        => (int) ($row['status'] ?? 1),
                'is_superadmin' => $type === 'user' && isset($superIds[$id]),
            ];
        }
        return $out;
    }
}
