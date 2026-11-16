<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 知识空间（kb_space）
 */
class Space extends Model
{
    protected $name               = 'kb_space';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime         = 'created_at';
    protected $updateTime         = 'updated_at';

    /** 公开空间 */
    public const PUBLIC_SPACE     = 0;
    /** 受限空间 */
    public const RESTRICTED_SPACE = 1;

    public function members()
    {
        return $this->hasMany(SpaceMember::class, 'space_id', 'id');
    }
}
