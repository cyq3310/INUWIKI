<?php

use think\facade\Cache;
use think\migration\Migrator;

/**
 * WIKI 本地权限配置：按「角色」或「人员」分别授予 kb:* 权限码。
 *
 * 原先 kb:* 权限点由 seed 写入 EIP 库、在 EIP 后台分配给角色；现由 WIKI
 * 配置管理页统一维护。本授权与 EIP 已有角色权限取并集，超管仍全放行。
 * 全局生效，不随当前空间切换。
 */
class CreateKbPermissionGrant extends Migrator
{
    public function up(): void
    {
        $this->table('kb_permission_grant', [
            'engine'      => 'InnoDB',
            'collation'   => 'utf8mb4_general_ci',
            'id'          => false,
            'primary_key' => ['id'],
            'comment'     => '权限配置（按角色/人员授予 kb:* 权限码）',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('subject_type', 'enum', ['values' => ['user', 'role'], 'default' => 'user', 'comment' => 'user人员 / role角色'])
            ->addColumn('subject_id', 'biginteger', ['signed' => false, 'comment' => 'inue_user.id 或 inue_role.id'])
            ->addColumn('permission_code', 'string', ['limit' => 64, 'comment' => '权限码，如 kb:article:create'])
            ->addColumn('created_by', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '配置人 inue_user.id'])
            ->addColumn('created_at', 'datetime', ['null' => true, 'comment' => '配置时间'])
            ->addIndex(['subject_type', 'subject_id', 'permission_code'], ['unique' => true, 'name' => 'uk_kb_permission_grant'])
            ->addIndex(['subject_type', 'subject_id'], ['name' => 'idx_kb_permission_subject'])
            ->addIndex(['permission_code'], ['name' => 'idx_kb_permission_code'])
            ->create();

        // fields_cache=true：清掉字段缓存，否则 fields_strict 下新表写入会被拦截
        Cache::clear();
    }

    public function down(): void
    {
        $this->table('kb_permission_grant')->drop()->save();
        Cache::clear();
    }
}
