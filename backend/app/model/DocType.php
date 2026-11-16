<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 文档类型（kb_doc_type，隶属于空间；与目录相互独立）
 * subjects 存为 JSON：[{"subject_type":"role"|"user","subject_id":123}, ...]
 */
class DocType extends Model
{
    protected $name               = 'kb_doc_type';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime         = 'created_at';
    protected $updateTime         = 'updated_at';

    /**
     * 当前 think-orm 已弃用 $json/$jsonAssoc，改用 $type 声明字段类型转换。
     * 'array' ⇒ 写入自动 json_encode，读取自动 json_decode(true)。
     * 若不声明，ORM 会把 text 列推断为 string，写入数组时触发
     * "Array to string conversion"(Attribute.php) 导致新增/编辑接口 500。
     */
    protected $type = [
        'is_default' => 'integer',
        'subjects'   => 'array',
    ];
}
