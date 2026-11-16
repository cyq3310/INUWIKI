<?php

use think\migration\Migrator;

/**
 * 角色 / 人员目录快照（kb_subject）
 *
 * 由 kb:subject-sync 定时从 EIP 主库同步。授权搜索与名称解析只读本表，
 * 请求链路不再访问 EIP 主库。保留停用 / 软删 / 已从 EIP 移除的记录，
 * 以便历史授权仍能解析出真实名称。
 */
class CreateKbSubject extends Migrator
{
    public function up(): void
    {
        $this->table('kb_subject', [
            'engine'      => 'InnoDB',
            'collation'   => 'utf8mb4_general_ci',
            'id'          => false,
            'primary_key' => ['id'],
            'comment'     => '角色/人员目录快照（EIP 定时同步）',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('subject_type', 'enum', ['values' => ['user', 'role'], 'default' => 'user', 'comment' => 'user人员 / role角色'])
            ->addColumn('subject_id', 'biginteger', ['signed' => false, 'comment' => 'inue_user.id 或 inue_role.id'])
            ->addColumn('name', 'string', ['limit' => 128, 'default' => '', 'comment' => '展示名称：角色 name / 人员 nickname'])
            ->addColumn('code', 'string', ['limit' => 128, 'null' => true, 'comment' => '角色编码 / 登录账号'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '1启用 0停用'])
            ->addColumn('deleted_at', 'datetime', ['null' => true, 'comment' => 'EIP 软删或已从 EIP 移除的时间'])
            ->addColumn('synced_at', 'datetime', ['null' => true, 'comment' => '最近一次从 EIP 同步的时间'])
            ->addIndex(['subject_type', 'subject_id'], ['unique' => true, 'name' => 'uk_kb_subject'])
            ->addIndex(['subject_type', 'status', 'deleted_at'], ['name' => 'idx_kb_subject_search'])
            ->addIndex(['synced_at'], ['name' => 'idx_kb_subject_synced'])
            ->create();
    }

    public function down(): void
    {
        $this->table('kb_subject')->drop()->save();
    }
}
