<?php
declare(strict_types=1);

namespace app\service;

use app\model\Space;
use app\model\SpaceMember;
use think\facade\Db;

/**
 * 空间服务：空间 CRUD、成员授权（角色 + 个人）、可见空间集合、统计口径 A
 *
 * 权限模型：
 * - 公开空间（is_restricted=0）：所有登录用户可见
 * - 受限空间（is_restricted=1）：仅 kb_space_member 中授权的角色或个人可见
 * - 未授权 = 完全隐藏（空间、分类、文章、搜索、推荐均不返回）
 * - 超管绕过全部限制
 *
 * 统计口径 A：
 * - 全站统计（总数 / 热门 / 首页数字 / 推荐）只统计公开空间，受限空间不进全站统计
 * - 受限空间内部仍保留自身浏览量，授权成员可见
 */
class SpaceService
{
    /**
     * 当前用户可见的空间 ID 集合（浏览用，含已授权给他的受限空间）
     */
    public function visibleSpaceIds(int $userId, array $roleIds, bool $isSuperAdmin): array
    {
        if ($isSuperAdmin) {
            return array_map('intval', Space::where('status', 1)->column('id'));
        }

        $public  = array_map('intval', Space::where('status', 1)->where('is_restricted', Space::PUBLIC_SPACE)->column('id'));
        $granted = $this->grantedSpaceIds($userId, $roleIds, 'can_view');

        return array_values(array_unique(array_merge($public, $granted)));
    }

    /**
     * 统计口径 A：纳入全站统计的空间 ID 集合（仅公开空间）
     */
    public function countableSpaceIds(): array
    {
        return array_map('intval', Space::where('status', 1)->where('is_restricted', Space::PUBLIC_SPACE)->column('id'));
    }

    /**
     * 按授权项取到空间 ID 集合（角色与个人取并集）
     *
     * @param string $perm can_view | can_edit | can_manage
     */
    public function grantedSpaceIds(int $userId, array $roleIds, string $perm): array
    {
        $ids = [];

        $byUser = SpaceMember::where('subject_type', SpaceMember::TYPE_USER)
            ->where('subject_id', $userId)
            ->where($perm, 1)
            ->column('space_id');
        $ids = array_merge($ids, $byUser);

        if ($roleIds !== []) {
            $byRole = SpaceMember::where('subject_type', SpaceMember::TYPE_ROLE)
                ->where('subject_id', 'in', $roleIds)
                ->where($perm, 1)
                ->column('space_id');
            $ids = array_merge($ids, $byRole);
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        // 仅保留启用中的空间
        return array_map('intval', Space::where('id', 'in', $ids)->where('status', 1)->column('id'));
    }

    /**
     * 可见空间列表（门户侧边栏用）
     */
    public function visibleSpaces(int $userId, array $roleIds, bool $isSuperAdmin): array
    {
        $ids = $this->visibleSpaceIds($userId, $roleIds, $isSuperAdmin);
        if ($ids === []) {
            return [];
        }

        $spaces = Space::where('id', 'in', $ids)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // 附带文章数（用于后台展示；门户可按需裁剪）
        foreach ($spaces as &$space) {
            $space['article_count'] = (int) Db::name('kb_article')
                ->where('space_id', $space['id'])
                ->where('status', 'published')
                ->count();
        }
        unset($space);

        return $spaces;
    }

    /**
     * 是否可在该空间内创建内容
     * 公开空间默认人人可创建（wiki 共建）；受限空间需 can_edit
     */
    public function canCreateInSpace(int $spaceId, int $userId, array $roleIds, bool $isSuperAdmin): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        $space = Space::find($spaceId);
        if (!$space || (int) $space->status !== 1) {
            return false;
        }

        if ((int) $space->is_restricted === Space::PUBLIC_SPACE) {
            return true;
        }

        return $this->hasSpacePermission($spaceId, $userId, $roleIds, 'can_edit');
    }

    /**
     * 是否可管理该空间（成员授权、分类维护）
     */
    public function canManageSpace(int $spaceId, int $userId, array $roleIds, bool $isSuperAdmin): bool
    {
        if ($isSuperAdmin) {
            return true;
        }
        return $this->hasSpacePermission($spaceId, $userId, $roleIds, 'can_manage');
    }

    /**
     * 是否可编辑该空间内内容
     */
    public function canEditInSpace(int $spaceId, int $userId, array $roleIds, bool $isSuperAdmin): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        $space = Space::find($spaceId);
        if (!$space || (int) $space->status !== 1) {
            return false;
        }
        if ((int) $space->is_restricted === Space::PUBLIC_SPACE) {
            return true;
        }

        return $this->hasSpacePermission($spaceId, $userId, $roleIds, 'can_edit');
    }

    private function hasSpacePermission(int $spaceId, int $userId, array $roleIds, string $perm): bool
    {
        $granted = $this->grantedSpaceIds($userId, $roleIds, $perm);
        return in_array($spaceId, $granted, true);
    }

    /**
     * 空间列表（后台管理用：超管看全部；其余看自己可管理的 + 公开空间）
     */
    public function adminSpaces(int $userId, array $roleIds, bool $isSuperAdmin): array
    {
        $ids = $this->visibleSpaceIds($userId, $roleIds, $isSuperAdmin);
        if ($ids === []) {
            return [];
        }

        $spaces = Space::where('id', 'in', $ids)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($spaces as &$space) {
            $space['article_count'] = (int) Db::name('kb_article')
                ->where('space_id', $space['id'])
                ->where('status', '<>', \app\model\Article::STATUS_DELETED)
                ->count();
            $space['member_count']  = (int) SpaceMember::where('space_id', $space['id'])->count();
        }
        unset($space);

        return $spaces;
    }

    /**
     * 取单个空间（未授权返回 null，不泄露存在性）
     */
    public function findVisible(int $spaceId, int $userId, array $roleIds, bool $isSuperAdmin): ?Space
    {
        $ids = $this->visibleSpaceIds($userId, $roleIds, $isSuperAdmin);
        if (!in_array($spaceId, $ids, true)) {
            return null;
        }
        return Space::find($spaceId);
    }

    /**
     * 成员列表（按角色 + 个人）
     */
    public function members(int $spaceId): array
    {
        return SpaceMember::where('space_id', $spaceId)
            ->order('subject_type', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 设置成员授权（已存在则更新，实现幂等）
     */
    public function setMember(
        int $spaceId,
        string $subjectType,
        int $subjectId,
        int $canView,
        int $canEdit,
        int $canManage
    ): void {
        $exists = SpaceMember::where('space_id', $spaceId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->find();

        if ($exists) {
            $exists->can_view   = $canView;
            $exists->can_edit   = $canEdit;
            $exists->can_manage = $canManage;
            $exists->save();
            return;
        }

        SpaceMember::create([
            'space_id'     => $spaceId,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'can_view'     => $canView,
            'can_edit'     => $canEdit,
            'can_manage'   => $canManage,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 移除成员授权
     */
    public function removeMember(int $spaceId, string $subjectType, int $subjectId): void
    {
        SpaceMember::where('space_id', $spaceId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->delete();
    }
}
