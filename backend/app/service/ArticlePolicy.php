<?php
declare(strict_types=1);

namespace app\service;

/**
 * 后台文章对象级权限策略。
 *
 * - 所有用户默认可查看 / 编辑 / 删除 / 发布下架「自己创建的」文章；
 * - 编辑他人文章按「知识库编辑权限 + 文章可编辑范围」判定：
 *   持 kb:article:update 无视范围，未持码时「所有人可改」放行、指定范围看名单；
 * - 删除 / 发布下架 / 版本查看仍需对应权限码（或空间授权 / 编辑名单），
 *   不因「所有人可改」而放宽；
 * - kb:article:list 决定后台列表能否看到所有文章，无此码只看自己创建的；
 * - kb:article:purge 独立控制回收站彻底清除，不看作者归属；
 * - 任何操作先过空间可见性（受限空间无 can_view 一律不可见）；
 * - 权限码按「文章所属空间」计算，而非请求头空间，避免跨空间操作用错配置。
 */
class ArticlePolicy
{
    private array $permissions;

    public function __construct(array $article)
    {
        $this->permissions = kb_permissions_for_space((int) ($article['space_id'] ?? 0));
    }

    /** 后台列表范围：null = 全部文章，int = 只看该作者的文章 */
    public static function listScope(): ?int
    {
        if (kb_is_super()) {
            return null;
        }
        return in_array('kb:article:list', kb_profile()['permissions'] ?? [], true)
            ? null
            : kb_user_id();
    }

    /** 本人默认放行；编辑他人文章按编辑范围判定，删除/发布仍需对应权限码 */
    public function canModify(array $article, string $code): bool
    {
        if (kb_is_super()) {
            return true;
        }
        if (!$this->spaceVisible($article)) {
            return false;
        }
        if ($this->isOwner($article)) {
            return true;
        }

        // 编辑类操作（保存 / 详情 / 附件 / 授权名单 / 版本回滚）与门户编辑按钮同口径：
        // 持 kb:article:update 无视文章编辑范围，未持码时「所有人可改」放行、指定范围看名单。
        // 删除 / 发布下架 / 版本查看仍走原有口径（空间授权、编辑名单，或对应权限码），
        // 传空权限集并关闭 open 放行，避免把「可编辑」当成可删除、可发布的通用许可。
        $isEditOperation = $code === 'kb:article:update';
        if ((new ArticleService())->canEditArticle(
            $article,
            kb_user_id(),
            kb_role_ids(),
            false,
            $isEditOperation ? $this->permissions : [],
            $isEditOperation
        )) {
            return true;
        }

        return in_array($code, $this->permissions, true);
    }

    /** 清除：必须显式拥有 kb:article:purge，作者归属不豁免 */
    public function canPurge(array $article): bool
    {
        if (kb_is_super()) {
            return true;
        }
        if (!$this->spaceVisible($article)) {
            return false;
        }
        return in_array('kb:article:purge', $this->permissions, true);
    }

    private function spaceVisible(array $article): bool
    {
        $visible = (new SpaceService())->visibleSpaceIds(kb_user_id(), kb_role_ids(), false);
        return in_array((int) ($article['space_id'] ?? 0), $visible, true);
    }

    private function isOwner(array $article): bool
    {
        return (int) ($article['author_id'] ?? 0) === kb_user_id();
    }
}
