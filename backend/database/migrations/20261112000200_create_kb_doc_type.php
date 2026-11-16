<?php

use think\facade\Db;
use think\migration\Migrator;

/**
 * 文档类型（kb_doc_type）：与目录相互独立的「文章分类 + 默认可见范围」配置。
 * 每个空间各自一套，初始默认四项：职能文档 / 项目文档 / 会议纪要 / 其它。
 * 同时在 kb_article 增加 doc_type_id 冗余列，便于文章按类型筛选与统计。
 */
class CreateKbDocType extends Migrator
{
    private const DEFAULTS = [
        ['name' => '职能文档', 'visibility' => 'public'],
        ['name' => '项目文档', 'visibility' => 'space'],
        ['name' => '会议纪要', 'visibility' => 'space'],
        ['name' => '其它',     'visibility' => 'public'],
    ];

    public function up(): void
    {
        $innodb = ['engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci'];

        $this->table('kb_doc_type', array_merge($innodb, ['id' => false, 'primary_key' => ['id'], 'comment' => '文档类型']))
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'comment' => '主键'])
            ->addColumn('space_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '所属空间ID'])
            ->addColumn('name', 'string', ['limit' => 50, 'comment' => '类型名称'])
            ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序'])
            ->addColumn('visibility', 'enum', ['values' => ['public', 'space', 'restricted'], 'default' => 'space', 'comment' => '默认可见范围'])
            ->addColumn('subjects', 'text', ['null' => true, 'comment' => '指定范围时的授权对象 JSON'])
            ->addColumn('is_default', 'integer', ['limit' => 1, 'default' => 0, 'comment' => '1=初始默认类型'])
            ->addColumn('created_at', 'datetime', ['null' => true, 'comment' => '创建时间'])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'comment' => '更新时间'])
            ->addIndex(['space_id'], ['name' => 'idx_kb_doc_type_space'])
            ->create();

        // 存量空间补齐默认四项文档类型
        $spaceIds = Db::name('kb_space')->where('status', 1)->column('id');
        $now = date('Y-m-d H:i:s');
        foreach ($spaceIds as $spaceId) {
            $rows = [];
            foreach (self::DEFAULTS as $i => $d) {
                $rows[] = [
                    'space_id'   => $spaceId,
                    'name'       => $d['name'],
                    'sort'       => $i,
                    'visibility' => $d['visibility'],
                    'subjects'   => json_encode([], JSON_UNESCAPED_UNICODE),
                    'is_default' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Db::name('kb_doc_type')->insertAll($rows);
        }

        // 文章表增加文档类型冗余列
        $this->table('kb_article')
            ->addColumn('doc_type_id', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => '文档类型ID'])
            ->addIndex(['doc_type_id'], ['name' => 'idx_kb_article_doc_type'])
            ->update();
    }

    public function down(): void
    {
        $this->table('kb_article')->removeColumn('doc_type_id')->update();
        $this->table('kb_doc_type')->drop()->save();
    }
}
