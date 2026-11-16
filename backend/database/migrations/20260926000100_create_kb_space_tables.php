<?php

use think\migration\Migrator;

/**
 * 知识库空间两表：空间 + 空间成员（按角色 / 按个人双重授权）
 */
class CreateKbSpaceTables extends Migrator
{
    public function up(): void
    {
        $innodb = ['engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci'];

        // 空间表
        $this->table('kb_space', array_merge($innodb, ['id' => false, 'primary_key' => ['id'], 'comment' => '知识空间']))
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('name', 'string', ['limit' => 50, 'comment' => '空间名称'])
            ->addColumn('code', 'string', ['limit' => 50, 'comment' => '空间编码'])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true, 'comment' => '空间描述'])
            ->addColumn('cover', 'string', ['limit' => 255, 'null' => true, 'comment' => '封面相对路径'])
            ->addColumn('is_restricted', 'integer', ['limit' => 1, 'default' => 0, 'comment' => '0公开 1受限'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '状态 1启用 0停用'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('created_by', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '创建人 inue_user.id'])
            ->addColumn('created_at', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uk_kb_space_code'])
            ->addIndex(['status'], ['name' => 'idx_kb_space_status'])
            ->create();

        // 空间成员表：subject_type=user 按个人授权；=role 按角色授权（权限取并集）
        $this->table('kb_space_member', array_merge($innodb, ['id' => false, 'primary_key' => ['id'], 'comment' => '空间成员授权']))
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('space_id', 'biginteger', ['signed' => false, 'comment' => '空间ID'])
            ->addColumn('subject_type', 'enum', ['values' => ['user', 'role'], 'default' => 'user', 'comment' => 'user个人 / role角色'])
            ->addColumn('subject_id', 'biginteger', ['signed' => false, 'comment' => 'inue_user.id 或 inue_role.id'])
            ->addColumn('can_view', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '可查看'])
            ->addColumn('can_edit', 'integer', ['limit' => 1, 'default' => 0, 'comment' => '可编辑'])
            ->addColumn('can_manage', 'integer', ['limit' => 1, 'default' => 0, 'comment' => '可管理'])
            ->addColumn('created_at', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addIndex(['space_id', 'subject_type', 'subject_id'], ['unique' => true, 'name' => 'uk_kb_space_member'])
            ->addIndex(['subject_type', 'subject_id'], ['name' => 'idx_kb_space_member_subject'])
            ->create();
    }

    public function down(): void
    {
        $this->table('kb_space_member')->drop()->save();
        $this->table('kb_space')->drop()->save();
    }
}
