<?php
declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * EIP 用户表（只读连接 eip，表 inue_user）
 * 仅用于登录校验与档案读取，禁止写入
 */
class EipUser extends Model
{
    use SoftDelete;

    protected $connection         = 'eip';
    protected $name               = 'user';
    protected $autoWriteTimestamp = false;
    protected $deleteTime         = 'deleted_at';
    protected $hidden             = ['password_hash'];

    // 不定义 roles() 关联：跨库 belongsToMany 会把中间表解析到默认连接上，
    // 角色与权限统一由 RbacService 用 Db::connect('eip') 显式查询。
}
