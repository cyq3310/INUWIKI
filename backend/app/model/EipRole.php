<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * EIP 角色表（只读连接 eip，表 inue_role）
 */
class EipRole extends Model
{
    protected $connection         = 'eip';
    protected $name               = 'role';
    protected $autoWriteTimestamp = false;

    // 同 EipUser：跨库关联交由 RbacService 显式查询处理
}
