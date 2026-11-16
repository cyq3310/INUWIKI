<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 文章可见授权（kb_article_viewer，view_scope=restricted 时生效）
 */
class ArticleViewer extends Model
{
    protected $name               = 'kb_article_viewer';
    protected $autoWriteTimestamp = false;
    protected $createTime         = false;
    protected $updateTime         = false;

    public const TYPE_USER = 'user';
    public const TYPE_ROLE = 'role';
}
