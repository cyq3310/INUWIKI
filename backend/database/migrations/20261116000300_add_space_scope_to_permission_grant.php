<?php

use think\facade\Cache;
use think\migration\Migrator;

/**
 * 权限配置增加空间维度。
 *
 * space_id = 0 → 全局配置（全局管理页，按角色）；
 * space_id > 0 → 该空间配置（配置管理页，按人员），整包覆盖全局角色配置。
 * 旧数据一律归为全局。唯一索引加入 space_id，使同一人员可在不同空间分别配置。
 */
class AddSpaceScopeToPermissionGrant extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `kb_permission_grant`
            ADD COLUMN `space_id` BIGINT UNSIGNED NOT NULL DEFAULT 0
            COMMENT '0=全局，其余=kb_space.id' AFTER `subject_id`");
        $this->execute('ALTER TABLE `kb_permission_grant` DROP INDEX `uk_kb_permission_grant`');
        $this->execute("ALTER TABLE `kb_permission_grant`
            ADD UNIQUE INDEX `uk_kb_perm_scope`
            (`space_id`, `subject_type`, `subject_id`, `permission_code`)");
        $this->execute("ALTER TABLE `kb_permission_grant`
            ADD INDEX `idx_kb_perm_scope_subject`
            (`space_id`, `subject_type`, `subject_id`)");

        Cache::clear();
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE `kb_permission_grant` DROP INDEX `idx_kb_perm_scope_subject`');
        $this->execute('ALTER TABLE `kb_permission_grant` DROP INDEX `uk_kb_perm_scope`');
        $this->execute("ALTER TABLE `kb_permission_grant`
            ADD UNIQUE INDEX `uk_kb_permission_grant`
            (`subject_type`, `subject_id`, `permission_code`)");
        $this->execute('ALTER TABLE `kb_permission_grant` DROP COLUMN `space_id`');

        Cache::clear();
    }
}
