<?php

use think\facade\Cache;
use think\migration\Migrator;

/**
 * 操作日志增加 object_type：让日志界面能区分对象 ID 属于文章、空间、人员、角色还是权限。
 * 历史日志没有该字段，查询接口会按 action/path 降级推导类型。
 */
class AddOperationLogObjectType extends Migrator
{
    public function up(): void
    {
        $table = $this->table('operation_log');
        if (!$table->hasColumn('object_type')) {
            $table->addColumn('object_type', 'string', [
                'limit'   => 40,
                'null'    => false,
                'default' => '',
                'comment' => '操作对象类型：article/space/category/doc_type/user/role/attachment/version/upload/system',
            ]);
            $table->update();
        }
        Cache::clear();
    }

    public function down(): void
    {
        $table = $this->table('operation_log');
        if ($table->hasColumn('object_type')) {
            $table->removeColumn('object_type');
            $table->update();
        }
        Cache::clear();
    }
}
