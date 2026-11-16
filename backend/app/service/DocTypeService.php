<?php
declare(strict_types=1);

namespace app\service;

use app\model\DocType;

/**
 * 文档类型服务：按空间维护，与目录相互独立。
 * 每个空间初次访问时惰性补齐默认四项（职能文档 / 项目文档 / 会议纪要 / 其它）。
 */
class DocTypeService
{
    public const DEFAULTS = [
        ['name' => '职能文档', 'visibility' => 'public'],
        ['name' => '项目文档', 'visibility' => 'space'],
        ['name' => '会议纪要', 'visibility' => 'space'],
        ['name' => '其它',     'visibility' => 'public'],
    ];

    /** 按空间列出文档类型（sort asc, id asc） */
    public function listBySpace(int $spaceId): array
    {
        return DocType::where('space_id', $spaceId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /** 惰性补齐某空间的默认四项（并发安全：先查后插，唯一冲突忽略） */
    public function ensureDefaults(int $spaceId): void
    {
        if ($spaceId <= 0) {
            return;
        }
        if (DocType::where('space_id', $spaceId)->count() > 0) {
            return;
        }

        $rows = [];
        $now  = date('Y-m-d H:i:s');
        foreach (self::DEFAULTS as $i => $d) {
            $rows[] = [
                'space_id'   => $spaceId,
                'name'       => $d['name'],
                'sort'       => $i,
                'visibility' => $d['visibility'],
                'subjects'   => json_encode([], JSON_UNESCAPED_UNICODE),
                'is_default' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        try {
            DocType::insertAll($rows);
        } catch (\Throwable $e) {
            // 并发下另一请求已插入，忽略
        }
    }

    public function create(int $spaceId, string $name, int $sort, string $visibility, array $subjects): DocType
    {
        return DocType::create([
            'space_id'   => $spaceId,
            'name'       => $name,
            'sort'       => $sort,
            'visibility' => $visibility,
            'subjects'   => $subjects,
            'is_default' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $row = DocType::find($id);
        if (!$row) {
            return false;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $row->save($data) !== false;
    }

    public function delete(int $id): bool
    {
        $row = DocType::find($id);
        if (!$row) {
            return false;
        }
        return $row->delete() !== false;
    }
}
