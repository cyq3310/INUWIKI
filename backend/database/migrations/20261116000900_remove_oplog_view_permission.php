<?php

use think\facade\Cache;
use think\migration\Migrator;

/**
 * 操作日志改为所有登录用户可见，不再需要权限配置。
 * 清理历史上由 AddOplogViewPermission 写入的 kb:oplog:view 授权记录。
 */
class RemoveOplogViewPermission extends Migrator
{
    public function up(): void
    {
        $this->execute("DELETE FROM `kb_permission_grant` WHERE `permission_code` = 'kb:oplog:view'");
        Cache::clear();
    }

    public function down(): void
    {
        $this->execute("INSERT IGNORE INTO `kb_permission_grant`
            (`space_id`, `subject_type`, `subject_id`, `permission_code`, `created_by`, `created_at`)
            SELECT g.`space_id`, g.`subject_type`, g.`subject_id`, 'kb:oplog:view', g.`created_by`, g.`created_at`
            FROM `kb_permission_grant` g
            WHERE g.`space_id` = 0
              AND g.`permission_code` IN ('kb:permission:manage', 'kb:global:manage')");
        Cache::clear();
    }
}
