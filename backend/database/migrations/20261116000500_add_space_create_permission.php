<?php

use think\facade\Cache;
use think\migration\Migrator;

/**
 * 新增「创建空间」权限（kb:space:create，空间管理分组，仅全局按角色配置）。
 *
 * 1. 原 POST admin/spaces 由 kb:space:manage 控制，拆分后由 kb:space:create 控制；
 *    给已有 kb:space:manage 的全局角色补齐新码，避免升级后失去创建能力。
 * 2. 空间级「配置管理 - 权限管理」按人员配置不再允许调整空间管理分组，
 *    清理已有的 space_id > 0 记录，避免历史配置继续生效。
 */
class AddSpaceCreatePermission extends Migrator
{
    public function up(): void
    {
        $this->execute("INSERT IGNORE INTO `kb_permission_grant`
            (`space_id`, `subject_type`, `subject_id`, `permission_code`, `created_by`, `created_at`)
            SELECT `space_id`, `subject_type`, `subject_id`, 'kb:space:create', `created_by`, `created_at`
            FROM `kb_permission_grant`
            WHERE `space_id` = 0 AND `permission_code` = 'kb:space:manage'");

        $this->execute("DELETE FROM `kb_permission_grant`
            WHERE `space_id` > 0
              AND `permission_code` IN ('kb:space:manage', 'kb:space:create')");

        Cache::clear();
    }

    public function down(): void
    {
        // 被清理的空间级空间管理配置无法还原，这里只移除新权限码
        $this->execute("DELETE FROM `kb_permission_grant` WHERE `permission_code` = 'kb:space:create'");
        Cache::clear();
    }
}
