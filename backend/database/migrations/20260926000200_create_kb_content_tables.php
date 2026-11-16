<?php

use think\migration\Migrator;

/**
 * 知识库内容五表：分类 / 文章 / 文章可编辑授权 / 附件 / 分片上传任务
 */
class CreateKbContentTables extends Migrator
{
    public function up(): void
    {
        $innodb = ['engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci'];

        // 分类表（隶属于空间）
        $this->table('kb_category', array_merge($innodb, ['id' => false, 'primary_key' => ['id'], 'comment' => '知识分类']))
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('space_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '所属空间ID'])
            ->addColumn('parent_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '父级ID'])
            ->addColumn('name', 'string', ['limit' => 50, 'comment' => '分类名称'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '状态 1启用 0停用'])
            ->addColumn('created_at', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['space_id'], ['name' => 'idx_kb_category_space'])
            ->addIndex(['parent_id'], ['name' => 'idx_kb_category_parent'])
            ->create();

        // 文章表
        $this->table('kb_article', array_merge($innodb, ['id' => false, 'primary_key' => ['id'], 'comment' => '知识文章']))
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('space_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '所属空间ID（冗余，便于权限过滤与统计）'])
            ->addColumn('category_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '分类ID'])
            ->addColumn('title', 'string', ['limit' => 200, 'comment' => '标题'])
            ->addColumn('summary', 'string', ['limit' => 500, 'null' => true, 'comment' => '摘要'])
            ->addColumn('cover', 'string', ['limit' => 255, 'null' => true, 'comment' => '封面相对路径'])
            ->addColumn('content', 'text', ['comment' => '富文本正文（已过 XSS 白名单）'])
            ->addColumn('status', 'enum', ['values' => ['draft', 'published', 'offline'], 'default' => 'draft', 'comment' => '草稿/已发布/已下架'])
            ->addColumn('view_scope', 'enum', ['values' => ['public', 'space', 'restricted'], 'default' => 'public', 'comment' => '可见范围'])
            ->addColumn('edit_mode', 'enum', ['values' => ['open', 'restricted'], 'default' => 'open', 'comment' => '可编辑范围 open所有人 restricted指定'])
            ->addColumn('view_count', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '浏览量'])
            ->addColumn('author_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '作者 inue_user.id'])
            ->addColumn('published_at', 'datetime', ['null' => true, 'comment' => '发布时间'])
            ->addColumn('created_at', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['space_id'], ['name' => 'idx_kb_article_space'])
            ->addIndex(['category_id'], ['name' => 'idx_kb_article_category'])
            ->addIndex(['status'], ['name' => 'idx_kb_article_status'])
            ->addIndex(['space_id', 'status'], ['name' => 'idx_kb_article_space_status'])
            ->create();

        // 文章可编辑授权（edit_mode=restricted 时生效；角色 + 个人双粒度）
        $this->table('kb_article_editor', array_merge($innodb, ['id' => false, 'primary_key' => ['id'], 'comment' => '文章可编辑授权']))
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('article_id', 'biginteger', ['signed' => false, 'comment' => '文章ID'])
            ->addColumn('subject_type', 'enum', ['values' => ['user', 'role'], 'default' => 'user', 'comment' => 'user个人 / role角色'])
            ->addColumn('subject_id', 'biginteger', ['signed' => false, 'comment' => 'inue_user.id 或 inue_role.id'])
            ->addIndex(['article_id', 'subject_type', 'subject_id'], ['unique' => true, 'name' => 'uk_kb_article_editor'])
            ->create();

        // 附件表（图片 / 视频 / 文件）
        $this->table('kb_attachment', array_merge($innodb, ['id' => false, 'primary_key' => ['id'], 'comment' => '知识附件']))
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('article_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '文章ID，0表示未挂载'])
            ->addColumn('type', 'enum', ['values' => ['image', 'video', 'file'], 'default' => 'file', 'comment' => '类型'])
            ->addColumn('original_name', 'string', ['limit' => 255, 'null' => true, 'comment' => '原始文件名'])
            ->addColumn('path', 'string', ['limit' => 500, 'comment' => '相对路径'])
            ->addColumn('thumb_path', 'string', ['limit' => 500, 'null' => true, 'comment' => '缩略图/封面相对路径'])
            ->addColumn('mime', 'string', ['limit' => 100, 'null' => true, 'comment' => 'MIME（finfo 嗅探结果）'])
            ->addColumn('size', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '字节大小'])
            ->addColumn('duration', 'integer', ['default' => 0, 'comment' => '视频时长（秒）'])
            ->addColumn('width', 'integer', ['default' => 0, 'comment' => '宽度'])
            ->addColumn('height', 'integer', ['default' => 0, 'comment' => '高度'])
            ->addColumn('sha256', 'string', ['limit' => 64, 'null' => true, 'comment' => '文件指纹'])
            ->addColumn('status', 'enum', ['values' => ['pending', 'merging', 'transcoding', 'ready', 'failed'], 'default' => 'pending', 'comment' => '处理状态'])
            ->addColumn('fail_reason', 'string', ['limit' => 500, 'null' => true, 'comment' => '失败原因'])
            ->addColumn('created_by', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '上传人 inue_user.id'])
            ->addColumn('created_at', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addIndex(['article_id'], ['name' => 'idx_kb_attachment_article'])
            ->addIndex(['sha256'], ['name' => 'idx_kb_attachment_sha256'])
            ->create();

        // 分片上传任务表
        $this->table('kb_upload', array_merge($innodb, ['id' => false, 'primary_key' => ['id'], 'comment' => '分片上传任务']))
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('upload_id', 'string', ['limit' => 64, 'comment' => '上传任务ID'])
            ->addColumn('user_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '上传人'])
            ->addColumn('filename', 'string', ['limit' => 255, 'comment' => '文件名'])
            ->addColumn('size', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '总大小'])
            ->addColumn('chunk_size', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '单片大小'])
            ->addColumn('chunk_total', 'integer', ['default' => 0, 'comment' => '分片总数'])
            ->addColumn('status', 'enum', ['values' => ['uploading', 'completed', 'aborted'], 'default' => 'uploading', 'comment' => '任务状态'])
            ->addColumn('attachment_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '生成的附件ID'])
            ->addColumn('created_at', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['upload_id'], ['unique' => true, 'name' => 'uk_kb_upload_id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('kb_upload')->drop()->save();
        $this->table('kb_attachment')->drop()->save();
        $this->table('kb_article_editor')->drop()->save();
        $this->table('kb_article')->drop()->save();
        $this->table('kb_category')->drop()->save();
    }
}
