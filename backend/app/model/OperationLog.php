<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/** 后台操作日志（按月分区表，created_at 由写入侧显式给出） */
class OperationLog extends Model
{
    protected $name               = 'operation_log';
    protected $autoWriteTimestamp = false;
}
