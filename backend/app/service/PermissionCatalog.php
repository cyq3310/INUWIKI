<?php
declare(strict_types=1);

namespace app\service;

/**
 * WIKI 权限码唯一清单。
 *
 * 后台权限配置页、用户档案权限集合、超管全放行都以此为准；
 * 不再从 EIP inue_permission / inue_role_permission 读取 kb:* 权限。
 *
 * 分组与菜单一一对应：「空间管理」下知识库空间管理决定导航栏入口、
 * 创建空间控制新建动作、全局管理控制全局管理入口，该分组只允许全局按角色配置；
 * 「权限管理」同样只允许全局按角色配置，避免被当前空间的人员配置覆盖；
 * 「配置管理」下目录 / 文档类型 / 权限管理三项分别控制配置管理页的页签与接口。
 */
class PermissionCatalog
{
    /**
     * 显式「零权限」占位：空间配置一个码都不勾时仍需表示「该人员已配置」，
     * 否则会被判成「当前空间无配置」而错误回落全局角色权限。
     * 该值不在 codes() 白名单内，鉴权时不会产生任何权限。
     */
    public const EMPTY_MARKER = '__none__';

    /**
     * 只在全局（space_id=0，按角色）配置时展示与分配的权限。
     * 空间级「配置管理 - 权限管理」按人员配置时不下发、也不能调整这些权限；
     * 它们始终按全局角色配置生效，不被空间人员配置整包覆盖，
     * 否则管理员在当前空间给自己配置人员权限时会被自己的空间配置挡在界面外。
     */
    public const GLOBAL_ONLY_GROUPS = ['空间管理'];
    public const GLOBAL_ONLY_CODES = ['kb:permission:manage'];

    /** code / 展示名 / 分组：由 GET admin/permissions 按范围下发给前端 */
    public const ITEMS = [
        ['code' => 'kb:article:list',    'name' => '知识库查看',     'group' => '知识内容'],
        ['code' => 'kb:article:create',  'name' => '知识库新建',     'group' => '知识内容'],
        ['code' => 'kb:article:update',  'name' => '知识库编辑',     'group' => '知识内容'],
        ['code' => 'kb:article:delete',  'name' => '知识库删除',     'group' => '知识内容'],
        ['code' => 'kb:article:publish', 'name' => '知识库发布下架', 'group' => '知识内容'],
        ['code' => 'kb:article:purge',   'name' => '知识库清除',     'group' => '知识内容'],
        ['code' => 'kb:space:manage',      'name' => '知识库空间管理', 'group' => '空间管理'],
        ['code' => 'kb:space:create',      'name' => '创建空间',       'group' => '空间管理'],
        ['code' => 'kb:global:manage',     'name' => '全局管理',       'group' => '空间管理'],
        ['code' => 'kb:category:manage',   'name' => '目录管理',       'group' => '配置管理'],
        ['code' => 'kb:doc_type:manage',   'name' => '文档类型',       'group' => '配置管理'],
        ['code' => 'kb:permission:manage', 'name' => '权限管理',       'group' => '配置管理'],
        ['code' => 'kb:media:upload',    'name' => '知识库媒体上传', 'group' => '媒体资源'],
    ];

    /** @return string[] 可分配的权限码白名单 */
    public static function codes(): array
    {
        return array_column(self::ITEMS, 'code');
    }

    /** 全局专属权限码：空间级人员配置不能覆盖，只允许全局按角色分配 */
    public static function globalOnlyCodes(): array
    {
        $codes = [];
        foreach (self::ITEMS as $item) {
            if (self::isGlobalOnly($item)) {
                $codes[] = $item['code'];
            }
        }
        return $codes;
    }

    /** 指定范围可展示的权限项：空间级按人员配置不含全局专属权限 */
    public static function itemsForScope(int $spaceId): array
    {
        if ($spaceId <= 0) {
            return self::ITEMS;
        }
        return array_values(array_filter(
            self::ITEMS,
            static fn (array $item): bool => !self::isGlobalOnly($item)
        ));
    }

    /** 指定范围允许写入、并能在鉴权中生效的权限码 */
    public static function assignableCodes(int $spaceId): array
    {
        return array_column(self::itemsForScope($spaceId), 'code');
    }

    private static function isGlobalOnly(array $item): bool
    {
        return in_array($item['group'], self::GLOBAL_ONLY_GROUPS, true)
            || in_array($item['code'], self::GLOBAL_ONLY_CODES, true);
    }
}
