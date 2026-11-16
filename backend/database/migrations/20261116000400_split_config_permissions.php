<?php

use think\facade\Cache;
use think\migration\Migrator;

/**
 * 权限清单拆分：原「空间与配置」拆成「空间管理」与「配置管理」，
 * 配置管理再分为目录管理 / 文档类型 / 权限管理三个独立权限码。
 *
 * 旧 kb:category:manage 同时承担这三类操作；拆分后目录仍用 kb:category:manage，
 * 文档类型改用 kb:doc_type:manage、权限配置改用 kb:permission:manage。
 * 这里给已有授权补齐新码，避免升级后原有管理员突然失去这两类能力。
 * INSERT IGNORE 依赖唯一索引 uk_kb_perm_scope，可重复执行。
 */
class SplitConfigPermissions extends Migrator
{
    private const LEGACY_CODE = 'kb:category:manage';
    private const ADDED_CODES = ['kb:doc_type:manage', 'kb:permission:manage'];

    public function up(): void
    {
        foreach (self::ADDED_CODES as $code) {
            $this->execute("INSERT IGNORE INTO `kb_permission_grant`
                (`space_id`, `subject_type`, `subject_id`, `permission_code`, `created_by`, `created_at`)
                SELECT `space_id`, `subject_type`, `subject_id`, '{$code}', `created_by`, `created_at`
                FROM `kb_permission_grant`
                WHERE `permission_code` = '" . self::LEGACY_CODE . "'");
        }

        Cache::clear();
    }

    public function down(): void
    {
        foreach (self::ADDED_CODES as $code) {
            $this->execute("DELETE FROM `kb_permission_grant` WHERE `permission_code` = '{$code}'");
        }

        Cache::clear();
    }
}
