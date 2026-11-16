<?php

use think\facade\Cache;
use think\migration\Migrator;

/**
 * 「空间管理」分组新增「全局管理」权限（kb:global:manage），
 * 用于单独控制导航栏【全局管理】入口；全局页内功能仍按现有规则。
 *
 * 1. 旧规则下拥有 kb:permission:manage 的角色即可看到全局管理按钮，
 *    这里给这些全局角色补齐新码，避免升级后入口消失。
 * 2. kb:permission:manage 调整为全局专属权限：清理 space_id > 0 的历史记录，
 *    之后它只按全局角色配置生效，不会被空间人员配置整包覆盖。
 */
class AddGlobalManagePermission extends Migrator
{
    public function up(): void
    {
        $this->execute("INSERT IGNORE INTO `kb_permission_grant`
            (`space_id`, `subject_type`, `subject_id`, `permission_code`, `created_by`, `created_at`)
            SELECT `space_id`, `subject_type`, `subject_id`, 'kb:global:manage', `created_by`, `created_at`
            FROM `kb_permission_grant`
            WHERE `space_id` = 0 AND `permission_code` = 'kb:permission:manage'");

        $this->execute("DELETE FROM `kb_permission_grant`
            WHERE `space_id` > 0 AND `permission_code` = 'kb:permission:manage'");

        Cache::clear();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `kb_permission_grant` WHERE `permission_code` = 'kb:global:manage'");
        Cache::clear();
    }
}
