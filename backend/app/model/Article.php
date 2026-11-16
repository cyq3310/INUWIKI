<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 知识文章（kb_article）
 */
class Article extends Model
{
    protected $name               = 'kb_article';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime         = 'created_at';
    protected $updateTime         = 'updated_at';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_OFFLINE   = 'offline';
    public const STATUS_DELETED   = 'deleted';

    public const SCOPE_PUBLIC     = 'public';
    public const SCOPE_SPACE      = 'space';
    public const SCOPE_RESTRICTED = 'restricted';

    public const EDIT_OPEN       = 'open';
    public const EDIT_RESTRICTED = 'restricted';
}
