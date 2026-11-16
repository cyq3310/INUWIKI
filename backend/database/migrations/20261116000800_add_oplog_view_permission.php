<?php

use think\facade\Cache;
use think\migration\Migrator;

/**
 * 「日志审计」分组新增「操作日志查看」权限（kb:oplog:view）。
 *
 * 给已拥有全局管理 / 权限管理的全局角色补齐新码，避免升级后日志查询页不可见；
 * 该权限是全局专属权限，空间级按人员配置不允许调整。
 */
class AddOplogViewPermission extends Migrator
{
    public function up(): void
    {
        $this->execute("INSERT IGNORE INTO `kb_permission_grant`
            (`space_id`, `subject_type`, `subject_id`, `permission_code`, `created_by`, `created_at`)
            SELECT g.`space_id`, g.`subject_type`, g.`subject_id`, 'kb:oplog:view', g.`created_by`, g.`created_at`
            FROM `kb_permission_grant` g
            WHERE g.`space_id` = 0
              AND g.`permission_code` IN ('kb:permission:manage', 'kb:global:manage')");

        $this->execute("DELETE FROM `kb_permission_grant`
            WHERE `space_id` > 0 AND `permission_code` = 'kb:oplog:view'");

        Cache::clear();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `kb_permission_grant` WHERE `permission_code` = 'kb:oplog:view'");
        Cache::clear();
    }
}
