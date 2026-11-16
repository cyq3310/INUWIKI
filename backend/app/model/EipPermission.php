<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * EIP 权限表（只读连接 eip，表 inue_permission）
 */
class EipPermission extends Model
{
    protected $connection         = 'eip';
    protected $name               = 'permission';
    protected $autoWriteTimestamp = false;
}
