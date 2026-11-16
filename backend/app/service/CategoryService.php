<?php
declare(strict_types=1);

namespace app\service;

use app\model\Category;
use think\facade\Db;

/**
 * 分类服务：按空间维护分类树
 */
class CategoryService
{
    /** 知识目录最多 5 层（L1 ~ L5），与前端 CATEGORY_MAX_LEVEL 保持一致 */
    public const MAX_LEVEL = 5;

    /** 目录名称最多 15 个字，与前端 CATEGORY_NAME_MAX_LENGTH 保持一致（库字段 varchar(50) 无需变更） */
    public const NAME_MAX_LENGTH = 15;

    /**
     * 每个空间默认存在的「未指定目录」。
     * 复用 status 字段做系统标记（业务只用 0/1），避免改表结构与手动跑迁移；
     * sort 取极小值保证永远置底。系统目录不可改名 / 移动 / 删除。
     */
    public const SYSTEM_STATUS = 2;
    public const DEFAULT_NAME  = '未指定目录';
    public const DEFAULT_SORT  = -9999;

    /**
     * 系统默认目录：每个空间按需补齐一条并返回其 ID（并发下复查兜底）。
     * 首次创建时把该空间历史 category_id=0 的文章一并归并进来，
     * 让「未指定目录」始终只有一个真实的归属 ID，前端无需兼容 0 与真实 ID 两套语义。
     */
    public function ensureDefaultCategory(int $spaceId): int
    {
        if ($spaceId <= 0) {
            return 0;
        }

        $find = function () use ($spaceId) {
            return Category::where('space_id', $spaceId)
                ->where('status', self::SYSTEM_STATUS)
                ->order('id', 'asc')
                ->find();
        };

        $existing = $find();
        if ($existing) {
            return (int) $existing->id;
        }

        $defaultId = 0;
        Db::transaction(function () use ($spaceId, $find, &$defaultId) {
            $again = $find();
            if ($again) {
                $defaultId = (int) $again->id;
                return;
            }
            $now  = date('Y-m-d H:i:s');
            $rows = Db::name('kb_category')->insertGetId([
                'space_id'   => $spaceId,
                'parent_id'  => 0,
                'name'       => self::DEFAULT_NAME,
                'sort'       => self::DEFAULT_SORT,
                'status'     => self::SYSTEM_STATUS,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $defaultId = (int) $rows;
            // 存量「未指定」文章自愈：只在首次建目录时执行一次
            Db::name('kb_article')
                ->where('space_id', $spaceId)
                ->where('category_id', 0)
                ->where('status', '<>', \app\model\Article::STATUS_DELETED)
                ->update(['category_id' => $defaultId]);
        });

        return $defaultId;
    }

    /** 是否为系统默认目录（任何用户都不可改名 / 移动 / 删除） */
    public function isSystem(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $row = Category::where('id', $id)->field('id, status')->find();
        return $row && (int) $row->status === self::SYSTEM_STATUS;
    }

    /**
     * 某空间下的分类列表（平铺，前端按 parent_id 组装树）
     */
    public function listBySpace(int $spaceId): array
    {
        return Category::where('space_id', $spaceId)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 组装成树，并附带已发布文章数
     */
    public function treeBySpace(int $spaceId): array
    {
        $this->ensureDefaultCategory($spaceId);
        $rows = $this->listBySpace($spaceId);
        $counts = Db::name('kb_article')
            ->where('space_id', $spaceId)
            ->where('status', 'published')
            ->field('category_id, count(*) as cnt')
            ->group('category_id')
            ->select()
            ->toArray();

        $countMap = [];
        foreach ($counts as $row) {
            $countMap[(int) $row['category_id']] = (int) $row['cnt'];
        }

        $map = [];
        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $isSystem = (int) $row['status'] === self::SYSTEM_STATUS;
            $row['is_system'] = $isSystem ? 1 : 0;
            $row['article_count'] = $countMap[$id] ?? 0;
            // 兼容尚未自愈的 category_id=0 文章，避免这批计数丢失
            if ($isSystem) {
                $row['article_count'] += $countMap[0] ?? 0;
            }
            $map[$id] = &$row;
        }
        unset($row);

        $tree = [];
        foreach ($rows as &$row) {
            $parentId = (int) $row['parent_id'];
            if ($parentId > 0 && isset($map[$parentId])) {
                $map[$parentId]['children'][] = &$row;
            } else {
                $tree[] = &$row;
            }
        }
        unset($row);

        // 系统默认目录永远置底（sort 已是极小值，这里再兜底一次）
        $normal = [];
        $system = [];
        foreach ($tree as $node) {
            if (!empty($node['is_system'])) {
                $system[] = $node;
            } else {
                $normal[] = $node;
            }
        }
        return array_merge($normal, $system);
    }

    /**
     * 目录层级（L1 = 1）。脏数据成环或父链断裂时返回 0，由调用方按「不可创建」处理。
     */
    public function levelOf(int $id): int
    {
        $level  = 0;
        $cursor = $id;
        $seen   = [];
        while ($cursor > 0 && !isset($seen[$cursor]) && $level <= self::MAX_LEVEL + 1) {
            $seen[$cursor] = true;
            $row = Category::where('id', $cursor)->field('id, parent_id')->find();
            if (!$row) {
                return 0;
            }
            $level++;
            $cursor = (int) $row->parent_id;
        }
        return $level;
    }

    /** 以某节点为根的子树高度（节点自身算 1 层），用于移动目录时的总深度校验 */
    public function subtreeHeight(int $id): int
    {
        $children = [];
        foreach (Category::field('id, parent_id')->select()->toArray() as $row) {
            $children[(int) $row['parent_id']][] = (int) $row['id'];
        }
        $walk = function (int $node, int $depth) use (&$walk, $children): int {
            // 成环 / 历史超深数据时直接返回大值，让调用方拒绝本次操作
            if ($depth > self::MAX_LEVEL + 1) {
                return $depth;
            }
            $max = $depth;
            foreach ($children[$node] ?? [] as $child) {
                $max = max($max, $walk($child, $depth + 1));
            }
            return $max;
        };
        return $walk($id, 1);
    }

    /** 新建目录的父层级校验：返回错误文案，null 表示允许创建 */
    public function validateParentLevel(int $spaceId, int $parentId): ?string
    {
        if ($parentId <= 0) {
            return null;
        }
        $parent = Category::find($parentId);
        if (!$parent) {
            return '父目录不存在';
        }
        if ((int) $parent->space_id !== $spaceId) {
            return '父目录不属于该空间';
        }
        $level = $this->levelOf($parentId);
        if ($level <= 0) {
            return '父目录层级异常';
        }
        if ($level >= self::MAX_LEVEL) {
            return '目录最多支持 ' . self::MAX_LEVEL . ' 级，L' . self::MAX_LEVEL . ' 下不能再创建子目录';
        }
        return null;
    }

    /** 改父目录时的校验：防自引用 / 成环，并保证连同子树不超过 L5 */
    public function validateMove(int $id, int $parentId): ?string
    {
        // 移到根目录也要拦：系统目录不接受任何层级调整
        if ($this->isSystem($id)) {
            return '「未指定目录」是系统默认目录，不允许调整位置';
        }
        if ($parentId <= 0) {
            return null;
        }
        if ($parentId === $id) {
            return '父目录不能是自己';
        }
        $cat    = Category::find($id);
        $parent = Category::find($parentId);
        if (!$cat || !$parent) {
            return '分类或父目录不存在';
        }
        if ((int) $cat->space_id !== (int) $parent->space_id) {
            return '父目录不属于同一空间';
        }
        $cursor = $parentId;
        $seen   = [];
        while ($cursor > 0 && !isset($seen[$cursor])) {
            if ($cursor === $id) {
                return '不能把目录挂到自己的子目录下';
            }
            $seen[$cursor] = true;
            $row           = Category::where('id', $cursor)->field('parent_id')->find();
            $cursor        = $row ? (int) $row->parent_id : 0;
        }
        $parentLevel = $this->levelOf($parentId);
        if ($parentLevel <= 0) {
            return '父目录层级异常';
        }
        if ($parentLevel + $this->subtreeHeight($id) > self::MAX_LEVEL) {
            return '移动后目录层级将超过 ' . self::MAX_LEVEL . ' 级';
        }
        return null;
    }

    public function create(int $spaceId, int $parentId, string $name, int $sort = 0): Category
    {
        return Category::create([
            'space_id'   => $spaceId,
            'parent_id'  => $parentId,
            'name'       => $name,
            'sort'       => $sort,
            'status'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $cat = Category::find($id);
        if (!$cat) {
            return false;
        }
        // 系统默认目录不接受任何改名 / 移动 / 排序 / 状态变更（控制器另给明确文案）
        if ((int) $cat->status === self::SYSTEM_STATUS) {
            return false;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $cat->save($data) !== false;
    }

    /**
     * 删除分类：直接子目录连同其整棵子树挂到「未指定目录」，本目录文章归入「未指定目录」。
     * 分类表是纯邻接表、文章只存叶子 category_id，改父指针后结构自然级联，无需逐条改写后代。
     */
    public function delete(int $id): array
    {
        $cat = Category::find($id);
        if (!$cat) {
            return [false, '分类不存在'];
        }
        if ((int) $cat->status === self::SYSTEM_STATUS) {
            return [false, '「未指定目录」是系统默认目录，不允许删除'];
        }

        $spaceId   = (int) $cat->space_id;
        $defaultId = $this->ensureDefaultCategory($spaceId);
        if ($defaultId <= 0) {
            return [false, '默认目录初始化失败，请稍后重试'];
        }

        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use ($id, $spaceId, $defaultId, $now) {
            Db::name('kb_category')
                ->where('space_id', $spaceId)
                ->where('parent_id', $id)
                ->update(['parent_id' => $defaultId, 'updated_at' => $now]);
            Db::name('kb_article')
                ->where('space_id', $spaceId)
                ->where('category_id', $id)
                ->where('status', '<>', \app\model\Article::STATUS_DELETED)
                ->update(['category_id' => $defaultId]);
            Db::name('kb_category')->where('id', $id)->delete();
        });

        return [true, '已删除，子目录与文章已移至「未指定目录」'];
    }
}
