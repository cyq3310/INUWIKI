<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\PermissionCatalog;
use app\service\RbacService;
use app\service\SpaceService;
use think\facade\Db;

/**
 * 后台 · 权限配置管理。
 *
 * space_id = 0 → 全局配置，按角色（全局管理页）；
 * space_id > 0 → 该空间配置，按人员（配置管理页），整包覆盖全局角色配置；
 *   且不含「空间管理」分组与「权限管理」权限（这些仅全局按角色配置）。
 * 读写接口需 kb:permission:manage（配置管理 - 权限管理）。
 */
class Permission extends BaseController
{
    /** 权限码清单 + 指定范围下已配置对象（按 subject 聚合） */
    public function index()
    {
        $spaceId = max(0, (int) $this->request->get('space_id', 0));
        if (!$this->assertSpace($spaceId)) {
            return resp_fail('空间不存在或无权限');
        }

        $rows = Db::name('kb_permission_grant')
            ->where('space_id', $spaceId)
            ->order('subject_type', 'asc')
            ->order('subject_id', 'asc')
            ->select()
            ->toArray();

        $assignable = PermissionCatalog::assignableCodes($spaceId);
        $grants     = [];
        foreach ($rows as $row) {
            $code = (string) $row['permission_code'];
            // 零权限占位与「全局专属分组」的历史脏数据都不回显到配置界面
            if ($code === PermissionCatalog::EMPTY_MARKER || !in_array($code, $assignable, true)) {
                continue;
            }
            $key = $row['subject_type'] . ':' . $row['subject_id'];
            if (!isset($grants[$key])) {
                $grants[$key] = [
                    'subject_type' => $row['subject_type'],
                    'subject_id'   => (int) $row['subject_id'],
                    'codes'        => [],
                ];
            }
            $grants[$key]['codes'][] = $code;
        }

        // 超级管理员始终拥有全部权限，空间级人员配置不回显其历史记录，避免被误编辑
        if ($spaceId > 0 && $grants !== []) {
            $userIds = [];
            foreach ($grants as $grant) {
                if ($grant['subject_type'] === 'user') {
                    $userIds[] = (int) $grant['subject_id'];
                }
            }
            $superIds = (new RbacService())->superAdminUserIds($userIds);
            foreach ($grants as $key => $grant) {
                if ($grant['subject_type'] === 'user' && isset($superIds[(int) $grant['subject_id']])) {
                    unset($grants[$key]);
                }
            }
        }

        return resp_ok([
            'permissions' => PermissionCatalog::itemsForScope($spaceId),
            'grants'      => array_values($grants),
            'space_id'    => $spaceId,
        ]);
    }

    /** 空间人员配置必须至少包含的全局角色权限码，供前端预选与提示 */
    public function globalRequired()
    {
        $spaceId   = max(0, (int) $this->request->get('space_id', 0));
        $subjectId = max(0, (int) $this->request->get('subject_id', 0));
        if ($spaceId <= 0 || $subjectId <= 0) {
            return resp_fail('授权对象与配置范围不匹配');
        }
        if (!$this->assertSpace($spaceId)) {
            return resp_fail('空间不存在或无权限');
        }
        if ($this->isProtectedSubject($spaceId, 'user', $subjectId)) {
            return resp_fail('超级管理员始终拥有全部权限，不能单独配置');
        }

        return resp_ok([
            'codes' => (new RbacService())->globalPermissionsForUser($subjectId, $spaceId),
        ]);
    }

    /** 全量覆盖某个对象在指定范围的权限码；传空数组 = 显式零权限（不回落全局） */
    public function save()
    {
        $data      = $this->request->post();
        $data      = is_array($data) ? $data : [];
        $spaceId   = max(0, (int) ($data['space_id'] ?? 0));
        $type      = (string) ($data['subject_type'] ?? '');
        $subjectId = (int) ($data['subject_id'] ?? 0);
        $codes     = $data['codes'] ?? [];

        $expectedType = $spaceId > 0 ? 'user' : 'role';
        if ($type !== $expectedType || $subjectId <= 0) {
            return resp_fail('授权对象与配置范围不匹配');
        }
        if (!$this->assertSpace($spaceId)) {
            return resp_fail('空间不存在或无权限');
        }
        if ($this->isProtectedSubject($spaceId, $type, $subjectId)) {
            return resp_fail('超级管理员始终拥有全部权限，不能单独配置');
        }
        if (!is_array($codes)) {
            return resp_fail('权限列表不合法');
        }

        $codes = array_values(array_unique(array_intersect(
            PermissionCatalog::assignableCodes($spaceId),
            array_map('strval', $codes)
        )));

        if ($spaceId > 0 && $type === 'user') {
            $required = (new RbacService())->globalPermissionsForUser($subjectId, $spaceId);
            $missing  = array_values(array_diff($required, $codes));
            if ($missing !== []) {
                return resp_fail('空间人员权限需至少包含全局角色权限：' . $this->permissionNames($missing));
            }
        }

        $now  = date('Y-m-d H:i:s');
        $rows = [];
        if ($codes === []) {
            $rows[] = $this->row($spaceId, $type, $subjectId, PermissionCatalog::EMPTY_MARKER, $now);
        } else {
            foreach ($codes as $code) {
                $rows[] = $this->row($spaceId, $type, $subjectId, $code, $now);
            }
        }

        Db::transaction(function () use ($spaceId, $type, $subjectId, $rows): void {
            Db::name('kb_permission_grant')
                ->where('space_id', $spaceId)
                ->where('subject_type', $type)
                ->where('subject_id', $subjectId)
                ->delete();
            Db::name('kb_permission_grant')->insertAll($rows);
        });

        return resp_ok([], '权限配置已保存');
    }

    /** 移除某个对象在指定范围的全部配置（移除后该空间回落全局角色配置） */
    public function remove()
    {
        $data      = $this->request->post();
        $data      = is_array($data) ? $data : [];
        $spaceId   = max(0, (int) ($data['space_id'] ?? 0));
        $type      = (string) ($data['subject_type'] ?? '');
        $subjectId = (int) ($data['subject_id'] ?? 0);

        $expectedType = $spaceId > 0 ? 'user' : 'role';
        if ($type !== $expectedType || $subjectId <= 0) {
            return resp_fail('授权对象与配置范围不匹配');
        }
        if (!$this->assertSpace($spaceId)) {
            return resp_fail('空间不存在或无权限');
        }
        if ($this->isProtectedSubject($spaceId, $type, $subjectId)) {
            return resp_fail('超级管理员始终拥有全部权限，不能单独配置');
        }

        Db::name('kb_permission_grant')
            ->where('space_id', $spaceId)
            ->where('subject_type', $type)
            ->where('subject_id', $subjectId)
            ->delete();

        return resp_ok([], '已移除');
    }

    private function row(int $spaceId, string $type, int $subjectId, string $code, string $now): array
    {
        return [
            'space_id'        => $spaceId,
            'subject_type'    => $type,
            'subject_id'      => $subjectId,
            'permission_code' => $code,
            'created_by'      => kb_user_id(),
            'created_at'      => $now,
        ];
    }

    /** 把权限码转换成配置界面使用的名称，便于保存失败时定位缺失项 */
    private function permissionNames(array $codes): string
    {
        $names = array_column(PermissionCatalog::ITEMS, 'name', 'code');
        return implode('、', array_map(
            static fn (string $code): string => $names[$code] ?? $code,
            array_map('strval', $codes)
        ));
    }

    /** 空间级人员配置不允许操作超级管理员，前端禁用外的服务端兜底 */
    private function isProtectedSubject(int $spaceId, string $type, int $subjectId): bool
    {
        return $spaceId > 0
            && $type === 'user'
            && (new RbacService())->isSuperAdmin($subjectId);
    }

    /** 空间配置必须落在当前用户可见的空间内，避免越权配置 */
    private function assertSpace(int $spaceId): bool
    {
        if ($spaceId <= 0) {
            return true;
        }
        return (new SpaceService())->findVisible(
            $spaceId, kb_user_id(), kb_role_ids(), kb_is_super()
        ) !== null;
    }
}
