<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 文章可编辑授权（kb_article_editor，edit_mode=restricted 时生效）
 */
class ArticleEditor extends Model
{
    protected $name               = 'kb_article_editor';
    protected $autoWriteTimestamp = false;
    protected $createTime         = false;
    protected $updateTime         = false;

    public const TYPE_USER = 'user';
    public const TYPE_ROLE = 'role';
}
