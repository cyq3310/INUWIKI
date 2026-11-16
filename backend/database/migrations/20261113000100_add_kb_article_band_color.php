<?php

use think\facade\Cache;
use think\migration\Migrator;

/**
 * 卡片改版：取消封面图片配置，标题改为满宽色带。
 * kb_article 增加 band_color（g1~g8，共 8 种非黑色色块）；
 * 历史文章留空，由前端按文章 ID 稳定分配一种颜色，无需回填数据。
 */
class AddKbArticleBandColor extends Migrator
{
    public function up(): void
    {
        $table = $this->table('kb_article');
        if (!$table->hasColumn('band_color')) {
            $table->addColumn('band_color', 'string', [
                'limit'   => 16,
                'null'    => true,
                'default' => null,
                'comment' => '卡片标题色带颜色 g1~g8',
            ])->update();
        }
        // database.php 开启 fields_cache + fields_strict：不清旧表结构缓存，
        // ORM 会静默过滤新列（写入 NULL、列表不返回该字段）。
        Cache::clear();
    }

    public function down(): void
    {
        $table = $this->table('kb_article');
        if ($table->hasColumn('band_color')) {
            $table->removeColumn('band_color')->update();
        }
    }
}
