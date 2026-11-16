<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 空间成员授权（kb_space_member）
 * subject_type: user 按个人 / role 按角色；与角色授权并存，权限取并集
 */
class SpaceMember extends Model
{
    protected $name               = 'kb_space_member';
    protected $autoWriteTimestamp = false;
    protected $createTime         = 'created_at';
    protected $updateTime         = false;

    public const TYPE_USER = 'user';
    public const TYPE_ROLE = 'role';
}
