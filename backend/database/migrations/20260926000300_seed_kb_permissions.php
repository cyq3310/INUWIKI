<?php

use think\facade\Db;
use think\migration\Migrator;

/**
 * 向 EIP 库（只读连接指向的 INUEIP）注册知识库权限点
 *
 * 说明：这是本项目唯一写入 EIP 库的地方，且只写 inue_permission 一张表；
 * 目的是让权限点出现在 EIP 后台的角色分配界面中，由管理员统一授权。
 * 已存在（按 code）则跳过，可重复执行。
 */
class SeedKbPermissions extends Migrator
{
    private const PERMISSIONS = [
        ['kb:article:list', '知识库查看', 'menu'],
        ['kb:article:create', '知识库新建', 'api'],
        ['kb:article:update', '知识库编辑', 'api'],
        ['kb:article:delete', '知识库删除', 'api'],
        ['kb:article:publish', '知识库发布下架', 'api'],
        ['kb:category:manage', '知识库分类管理', 'api'],
        ['kb:space:manage', '知识库空间管理', 'api'],
        ['kb:media:upload', '知识库媒体上传', 'api'],
    ];

    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $conn = Db::connect('eip');

        foreach (self::PERMISSIONS as [$code, $name, $type]) {
            $exists = $conn->name('permission')->where('code', $code)->count();
            if ($exists > 0) {
                continue;
            }
            $conn->name('permission')->insert([
                'parent_id'  => 0,
                'name'       => $name,
                'code'       => $code,
                'type'       => $type,
                'path'       => null,
                'sort'       => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $codes = array_column(self::PERMISSIONS, 0);
        Db::connect('eip')->name('permission')->where('code', 'in', $codes)->delete();
    }
}
