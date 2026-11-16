<?php
declare(strict_types=1);

namespace app\service;

use app\model\EipUser;
use think\facade\Db;

/**
 * RBAC 读取服务：用户与角色基本信息仍来自 EIP 库（只读），
 * 但 WIKI 权限码完全由本地 kb_permission_grant 决定，不再叠加 EIP 角色权限。
 *
 * 空间规则：
 * - spaceId = 0 → 全局配置，按角色（全局管理页），多角色取并集；
 * - spaceId > 0 → 若该人员在此空间有配置，整包替换全局配置；否则回落全局。
 * - 超管恒拥有 PermissionCatalog 的全部权限码，不受空间覆盖影响。
 *
 * 注意：INUEIP 与 INUWIKI 是不同库，ORM 关联会把中间表解析到默认连接，
 * 因此角色一律用 Db::connect('eip') 显式查询。
 */
class RbacService
{
    /** superAdminUserIds() 的请求内查询结果缓存 */
    private static array $superAdminCache = [];

    /**
     * 用户档案：基本信息 + 角色 + 指定空间下的有效权限码集合
     *
     * @throws \RuntimeException 账号不存在或已禁用
     */
    public function buildProfile(int $userId, int $spaceId = 0): array
    {
        $user = EipUser::find($userId);
        if (!$user || (int) $user->status !== 1) {
            throw new \RuntimeException('账号不存在或已被禁用', 401);
        }

        $roleIds = Db::connect('eip')->name('user_role')
            ->where('user_id', $userId)
            ->column('role_id');
        $roleIds = array_values(array_map('intval', $roleIds));

        $roles = [];
        if ($roleIds !== []) {
            $roles = Db::connect('eip')->name('role')
                ->where('id', 'in', $roleIds)
                ->select()
                ->toArray();
        }

        $roleCodes = array_values(array_column($roles, 'code'));
        $isSuper   = in_array('super_admin', $roleCodes, true);
        $effectiveRoleIds = array_values(array_map('intval', array_column($roles, 'id')));

        return [
            'id'           => (int) $user->id,
            'username'     => $user->username,
            'nickname'     => $user->nickname,
            'avatar'       => $user->avatar,
            'roles'        => $roleCodes,
            'roleIds'      => $effectiveRoleIds,
            'isSuperAdmin' => $isSuper,
            'spaceId'      => $spaceId,
            'permissions'  => $this->effectivePermissions($userId, $effectiveRoleIds, $isSuper, $spaceId),
        ];
    }

    /**
     * 批量判定人员是否拥有 super_admin 角色，返回以 user_id 为键的映射。
     * EIP 不可用时降级为空映射，避免目录搜索 / 权限保存整体失败。
     */
    public function superAdminUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }
        sort($userIds);
        $cacheKey = implode(',', $userIds);
        if (array_key_exists($cacheKey, self::$superAdminCache)) {
            return self::$superAdminCache[$cacheKey];
        }

        try {
            $roleIds = Db::connect('eip')->name('role')
                ->where('code', 'super_admin')
                ->column('id');
            if ($roleIds === []) {
                return self::$superAdminCache[$cacheKey] = [];
            }
            $ids = Db::connect('eip')->name('user_role')
                ->where('role_id', 'in', $roleIds)
                ->where('user_id', 'in', $userIds)
                ->column('user_id');
            return self::$superAdminCache[$cacheKey] = array_fill_keys(
                array_values(array_map('intval', $ids)),
                true
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** 单人超管判定，供权限写入接口做服务端兜底校验 */
    public function isSuperAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $map = $this->superAdminUserIds([$userId]);
        return isset($map[$userId]);
    }

    /**
     * 某人员由全局角色授权得到的基础权限码。
     * 按指定空间可分配范围过滤：空间人员配置界面不能调整的全局专属权限不作为下限。
     */
    public function globalPermissionsForUser(int $userId, int $spaceId = 0): array
    {
        if ($userId <= 0) {
            return [];
        }
        $roleIds = $this->userRoleIds($userId);
        if ($roleIds === []) {
            return [];
        }

        try {
            $codes = Db::name('kb_permission_grant')
                ->where('space_id', 0)
                ->where('subject_type', 'role')
                ->where('subject_id', 'in', $roleIds)
                ->column('permission_code');
            return $this->cleanCodes($codes, $spaceId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** 单个人员的 EIP 角色 ID 集合；EIP 不可用时返回空 */
    private function userRoleIds(int $userId): array
    {
        try {
            $ids = Db::connect('eip')->name('user_role')
                ->where('user_id', $userId)
                ->column('role_id');
            return array_values(array_unique(array_filter(
                array_map('intval', $ids),
                static fn (int $id): bool => $id > 0
            )));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 计算某用户在指定空间的有效权限码集合。
     * 表不存在 / 迁移未执行时静默返回空，绝不能让登录链路整体 500。
     */
    public function effectivePermissions(int $userId, array $roleIds, bool $isSuper, int $spaceId = 0): array
    {
        if ($isSuper) {
            return PermissionCatalog::codes();
        }

        try {
            if ($spaceId > 0) {
                $local = Db::name('kb_permission_grant')
                    ->where('space_id', $spaceId)
                    ->where('subject_type', 'user')
                    ->where('subject_id', $userId)
                    ->column('permission_code');

                // 有任意行（含显式零权限占位）就代表「该空间已配置」，整包替换全局；
                // 但全局专属权限（空间管理 / 权限管理）仍按全局角色配置生效，
                // 否则管理员会被自己当前空间的人员配置挡在配置界面外，形成授权死锁。
                if ($local !== []) {
                    return array_values(array_unique(array_merge(
                        $this->cleanCodes($local, $spaceId),
                        $this->globalOnlyPermissions($roleIds)
                    )));
                }
            }

            $roleIds = array_values(array_unique(array_filter($roleIds, static fn ($id) => $id > 0)));
            if ($roleIds === []) {
                return [];
            }

            $global = Db::name('kb_permission_grant')
                ->where('space_id', 0)
                ->where('subject_type', 'role')
                ->where('subject_id', 'in', $roleIds)
                ->column('permission_code');

            return $this->cleanCodes($global);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** 只保留该范围允许生效的权限码，过滤零权限占位与历史脏数据 */
    private function cleanCodes(array $codes, int $spaceId = 0): array
    {
        return array_values(array_unique(array_intersect(
            PermissionCatalog::assignableCodes($spaceId),
            array_map('strval', $codes)
        )));
    }

    /**
     * 从全局角色配置取「全局专属权限」：空间人员配置整包覆盖时仍需保留，
     * 否则管理入口（空间管理 / 权限管理）会随当前空间的人员配置一起消失。
     */
    private function globalOnlyPermissions(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_filter($roleIds, static fn ($id) => $id > 0)));
        if ($roleIds === []) {
            return [];
        }

        $global = Db::name('kb_permission_grant')
            ->where('space_id', 0)
            ->where('subject_type', 'role')
            ->where('subject_id', 'in', $roleIds)
            ->column('permission_code');

        return array_values(array_intersect(
            PermissionCatalog::globalOnlyCodes(),
            array_map('strval', $global)
        ));
    }

    /** 是否拥有指定权限码（超管恒为 true） */
    public function hasPermission(array $profile, string $code): bool
    {
        if (!empty($profile['isSuperAdmin'])) {
            return true;
        }
        return in_array($code, $profile['permissions'] ?? [], true);
    }
}
