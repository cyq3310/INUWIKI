<?php

use think\migration\Migrator;

/**
 * 文章可见授权表（kb_article_viewer）
 *
 * 用于 view_scope=restricted 的"指定范围可见"；与 kb_article_editor（可编辑授权）
 * 结构一致，均支持按角色 + 按个人双粒度，权限取并集。
 */
class CreateKbArticleViewer extends Migrator
{
    public function up(): void
    {
        $this->table('kb_article_viewer', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_general_ci',
            'id'        => false,
            'primary_key' => ['id'],
            'comment'   => '文章可见授权',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('article_id', 'biginteger', ['signed' => false, 'comment' => '文章ID'])
            ->addColumn('subject_type', 'enum', ['values' => ['user', 'role'], 'default' => 'user', 'comment' => 'user个人 / role角色'])
            ->addColumn('subject_id', 'biginteger', ['signed' => false, 'comment' => 'inue_user.id 或 inue_role.id'])
            ->addIndex(['article_id', 'subject_type', 'subject_id'], ['unique' => true, 'name' => 'uk_kb_article_viewer'])
            ->create();
    }

    public function down(): void
    {
        $this->table('kb_article_viewer')->drop()->save();
    }
}
