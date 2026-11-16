<?php

use think\facade\Cache;
use think\migration\Migrator;

/**
 * 文章回收站 + 历史版本。
 *
 * - kb_article.status 增加 deleted；新增 prev_status / deleted_at / deleted_by。
 *   删除只打标记，不删行、不动附件文件；恢复还原 prev_status；清除才物理删除。
 * - kb_article_version 只保存正文与基础属性快照（正文 gzcompress + base64），
 *   不复制任何附件二进制，磁盘增量可忽略；每篇保留数由 knowledge.version_keep 控制。
 */
class CreateKbArticleVersionAndTrash extends Migrator
{
    public function up(): void
    {
        $article = $this->table('kb_article');
        $article->changeColumn('status', 'enum', [
            'values'  => ['draft', 'published', 'offline', 'deleted'],
            'default' => 'draft',
            'comment' => '草稿/已发布/已下架/已删除（回收站）',
        ]);
        if (!$article->hasColumn('prev_status')) {
            $article->addColumn('prev_status', 'string', [
                'limit'   => 16,
                'null'    => true,
                'default' => null,
                'comment' => '删除前状态，恢复时还原',
            ]);
        }
        if (!$article->hasColumn('deleted_at')) {
            $article->addColumn('deleted_at', 'datetime', [
                'null'    => true,
                'default' => null,
                'comment' => '移入回收站时间',
            ]);
        }
        if (!$article->hasColumn('deleted_by')) {
            $article->addColumn('deleted_by', 'biginteger', [
                'signed'  => false,
                'default' => 0,
                'comment' => '删除操作人',
            ]);
        }
        $article->update();

        if (!$this->hasTable('kb_article_version')) {
            $this->table('kb_article_version', [
                'id'          => false,
                'primary_key' => ['id'],
                'engine'      => 'InnoDB',
                'collation'   => 'utf8mb4_unicode_ci',
                'comment'     => '文章历史版本（只存文本快照，不复制附件）',
            ])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('article_id', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('version_no', 'integer', ['signed' => false, 'default' => 1])
                ->addColumn('title', 'string', ['limit' => 200, 'default' => ''])
                ->addColumn('summary', 'string', ['limit' => 500, 'null' => true, 'default' => null])
                // Phinx TEXT 的 limit 是字节数：>= 16MB 时映射为 MySQL mediumtext
                ->addColumn('content', 'text', ['limit' => 16777215, 'null' => true, 'comment' => 'gzcompress + base64 后的正文'])
                ->addColumn('status', 'string', ['limit' => 16, 'default' => 'draft'])
                ->addColumn('view_scope', 'string', ['limit' => 16, 'default' => 'public'])
                ->addColumn('edit_mode', 'string', ['limit' => 16, 'default' => 'open'])
                ->addColumn('category_id', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('doc_type_id', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('band_color', 'string', ['limit' => 16, 'null' => true, 'default' => null])
                ->addColumn('content_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => '正文 sha256，用于去重'])
                ->addColumn('created_by', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['article_id', 'version_no'], ['name' => 'idx_kb_article_version_no'])
                ->addIndex(['created_at'], ['name' => 'idx_kb_article_version_created'])
                ->create();
        }

        // database.php 开启 fields_cache + fields_strict：不清旧表结构缓存，
        // ORM 会静默过滤新列（写入 NULL、列表不返回该字段）。
        Cache::clear();
    }

    public function down(): void
    {
        if ($this->hasTable('kb_article_version')) {
            $this->table('kb_article_version')->drop()->save();
        }

        $article = $this->table('kb_article');
        $article->changeColumn('status', 'enum', [
            'values'  => ['draft', 'published', 'offline'],
            'default' => 'draft',
            'comment' => '草稿/已发布/已下架',
        ]);
        foreach (['prev_status', 'deleted_at', 'deleted_by'] as $column) {
            if ($article->hasColumn($column)) {
                $article->removeColumn($column);
            }
        }
        $article->update();
        Cache::clear();
    }
}
