<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 知识分类（kb_category，隶属于空间）
 */
class Category extends Model
{
    protected $name               = 'kb_category';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime         = 'created_at';
    protected $updateTime         = 'updated_at';
}
