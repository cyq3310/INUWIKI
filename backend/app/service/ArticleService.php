<?php
declare(strict_types=1);

namespace app\service;

use app\model\Article;
use app\model\ArticleEditor;
use app\model\ArticleViewer;
use app\model\SpaceMember;
use think\facade\Db;

/**
 * 文章服务：CRUD、状态流转、可见/可编辑权限判定、浏览量、统计
 *
 * 权限判定顺序（与方案一致）：
 *   超管 → 空间管理员(can_manage) → 空间成员(can_edit)
 *        → edit_mode=open 且具 kb:article:update
 *        → 命中 kb_article_editor（user 直配 或 用户所属 role）
 *
 * 统计口径 A：全站统计只统计公开空间，受限空间不进全站统计；
 * 但受限空间内部仍保留自身浏览量，授权成员可见。
 */
class ArticleService
{
    /**
     * 目录自身 + 全部后代目录 ID。
     * kb_category 是邻接表：限定同一空间一次性取全量节点，BFS 下钻并用 seen 防止脏数据成环。
     * 目录不存在时退回原 id，保持旧行为。
     */
    private function categoryWithDescendants(int $categoryId): array
    {
        $root = Db::name('kb_category')->where('id', $categoryId)->field('space_id')->find();
        if (!$root) {
            return [$categoryId];
        }

        $rows = Db::name('kb_category')
            ->where('space_id', (int) $root['space_id'])
            ->field('id, parent_id')
            ->select()
            ->toArray();

        $children = [];
        foreach ($rows as $row) {
            $children[(int) $row['parent_id']][] = (int) $row['id'];
        }

        $seen  = [];
        $queue = [$categoryId];
        for ($i = 0; $i < count($queue); $i++) {
            $id = $queue[$i];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }
        return array_keys($seen);
    }

    /** 列表批量补「文档类型名称」：一次查询避免前端按空间逐个 join */
    private function attachDocTypeNames(array $list): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn(array $row): int => (int) ($row['doc_type_id'] ?? 0), $list),
            static fn(int $id): bool => $id > 0
        )));

        $names = $ids === []
            ? []
            : Db::name('kb_doc_type')->where('id', 'in', $ids)->column('name', 'id');

        foreach ($list as &$row) {
            $id = (int) ($row['doc_type_id'] ?? 0);
            $row['doc_type_name'] = $id > 0 ? (string) ($names[$id] ?? '') : '';
        }
        unset($row);
        return $list;
    }

    /**
     * 门户列表：只返回可见空间内、已发布且对当前用户可见的文章。
     * 可见范围在 SQL 分页前过滤，保证 total 与 list 口径一致。
     *
     * @param array $visibleSpaceIds 当前用户可见空间集合
     * @param int   $userId 当前用户 ID（作者本人默认可见）
     * @param array $roleIds 当前用户角色 ID（按角色的可见名单）
     * @param bool  $isSuperAdmin 超管全放行
     */
    public function portalList(
        array $params,
        array $visibleSpaceIds,
        int $userId = 0,
        array $roleIds = [],
        bool $isSuperAdmin = false
    ): array
    {
        if ($visibleSpaceIds === []) {
            return ['total' => 0, 'page' => 1, 'size' => (int) ($params['size'] ?? 10), 'list' => []];
        }

        $page = max(1, (int) ($params['page'] ?? 1));
        $size = min(50, max(1, (int) ($params['size'] ?? 10)));

        $query = Article::where('status', Article::STATUS_PUBLISHED)
            ->where('space_id', 'in', $visibleSpaceIds);

        // 可见范围必须在分页前过滤：否则 total 虚高，且不可见文章会占掉分页名额，
        // 把本该可见的文章挤出当前页。规则与 canViewArticle 保持一致。
        if (!$isSuperAdmin) {
            $grantedIds = Db::name('kb_article_viewer')
                ->where('subject_type', 'user')
                ->where('subject_id', $userId)
                ->column('article_id');
            if ($roleIds !== []) {
                $grantedIds = array_merge($grantedIds, Db::name('kb_article_viewer')
                    ->where('subject_type', 'role')
                    ->where('subject_id', 'in', $roleIds)
                    ->column('article_id'));
            }
            $grantedIds = array_values(array_unique(array_map('intval', $grantedIds)));
            $query->where(function ($q) use ($userId, $grantedIds) {
                $q->where('view_scope', '<>', Article::SCOPE_RESTRICTED)
                    ->whereOr('author_id', $userId)
                    ->whereOr('id', 'in', $grantedIds === [] ? [0] : $grantedIds);
            });
        }

        if (!empty($params['space_id'])) {
            $query->where('space_id', (int) $params['space_id']);
        }
        if (!empty($params['category_id'])) {
            // 选中目录时同时返回其所有子目录的文章，与左侧目录树的计数口径一致
            $query->where('category_id', 'in', $this->categoryWithDescendants((int) $params['category_id']));
        }
        if (!empty($params['doc_type_id'])) {
            $query->where('doc_type_id', (int) $params['doc_type_id']);
        }
        if (!empty($params['keyword'])) {
            $query->where('title|summary', 'like', '%' . $params['keyword'] . '%');
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('published_at', 'desc')
            ->order('id', 'desc')
            ->page($page, $size)
            ->select()
            ->toArray();

        return [
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'list'  => $this->attachDocTypeNames($list),
        ];
    }

    /**
     * 门户详情：不可见时返回 null（不泄露存在性）
     */
    public function portalDetail(
        int $id,
        int $userId,
        array $roleIds,
        bool $isSuperAdmin,
        array $visibleSpaceIds
    ): ?array {
        $row = $this->portalVisibleArticle($id, $userId, $roleIds, $isSuperAdmin, $visibleSpaceIds);
        if (!$row) {
            return null;
        }

        // 浏览量自增（受限空间内部也统计，只是不进全站统计）
        Db::name('kb_article')->where('id', $id)->inc('view_count')->update();

        $row['attachments'] = Db::name('kb_attachment')
            ->where('article_id', $id)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $row;
    }

    /**
     * 门户可见文章：只做可见性判定，不产生浏览量等副作用。
     * 历史版本等只读接口复用此方法，避免每打开一次抽屉就计一次浏览。
     */
    public function portalVisibleArticle(
        int $id,
        int $userId,
        array $roleIds,
        bool $isSuperAdmin,
        array $visibleSpaceIds
    ): ?array {
        $article = Article::find($id);
        if (!$article) {
            return null;
        }

        $row = $article->toArray();
        if ($row['status'] !== Article::STATUS_PUBLISHED) {
            return null;
        }
        if (!in_array((int) $row['space_id'], $visibleSpaceIds, true)) {
            return null;
        }
        if (!$this->canViewArticle($row, $userId, $roleIds, $isSuperAdmin)) {
            return null;
        }

        return $row;
    }

    /**
     * 单篇可见判定
     */
    public function canViewArticle(array $article, int $userId, array $roleIds, bool $isSuperAdmin): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        // 作者本人默认可查看自己的文章，与后台 ArticlePolicy 的本人放行口径一致：
        // 「指定范围」授权名单漏配作者时，作者仍应能打开自己刚发布的文章、图片与视频。
        if ((int) ($article['author_id'] ?? 0) === $userId) {
            return true;
        }

        switch ($article['view_scope']) {
            case Article::SCOPE_RESTRICTED:
                return $this->hitGrant(ArticleViewer::class, (int) $article['id'], $userId, $roleIds);
            case Article::SCOPE_SPACE:
            case Article::SCOPE_PUBLIC:
            default:
                // 空间可见性由调用方用 visibleSpaceIds 前置过滤
                return true;
        }
    }

    /**
     * 单篇可编辑判定：
     * - 超管 / 持 kb:article:update → 无视文章可编辑范围；
     * - 空间 can_manage / can_edit 授权 → 放行；
     * - 未持权限码时按 edit_mode：restricted 看 editor 名单，open（含历史空值）放行；
     * - $honorOpenEdit=false 时关闭「所有人可改」放行，供后台删除 / 发布复用。
     */
    public function canEditArticle(
        array $article,
        int $userId,
        array $roleIds,
        bool $isSuperAdmin,
        array $permissions,
        bool $honorOpenEdit = true
    ): bool {
        if ($isSuperAdmin) {
            return true;
        }

        // a. 勾选「知识库编辑」：无视文章可编辑范围，均可编辑
        if (in_array('kb:article:update', $permissions, true)) {
            return true;
        }

        // 空间级授权（can_manage / can_edit）优先于文章级范围
        $spaceId   = (int) $article['space_id'];
        $canManage = (new SpaceService())->grantedSpaceIds($userId, $roleIds, 'can_manage');
        if (in_array($spaceId, $canManage, true)) {
            return true;
        }
        $canEditSpace = (new SpaceService())->grantedSpaceIds($userId, $roleIds, 'can_edit');
        if (in_array($spaceId, $canEditSpace, true)) {
            return true;
        }

        // b. 未勾选时按文章可编辑范围判定
        $editMode = (string) ($article['edit_mode'] ?? '');
        if ($editMode === Article::EDIT_RESTRICTED) {
            return $this->hitGrant(ArticleEditor::class, (int) $article['id'], $userId, $roleIds);
        }

        // 「所有人可改」无需权限码即可编辑；后台删除 / 发布下架复用本方法时传 false，
        // 避免把「可编辑」误当成可删除、可发布的通用许可。
        return $honorOpenEdit;
    }

    /**
     * 命中授权名单（按个人 或 按角色，取并集）
     *
     * @param string $model ArticleViewer::class | ArticleEditor::class
     */
    private function hitGrant(string $model, int $articleId, int $userId, array $roleIds): bool
    {
        $byUser = $model::where('article_id', $articleId)
            ->where('subject_type', 'user')
            ->where('subject_id', $userId)
            ->count();
        if ($byUser > 0) {
            return true;
        }

        if ($roleIds === []) {
            return false;
        }

        return $model::where('article_id', $articleId)
            ->where('subject_type', 'role')
            ->where('subject_id', 'in', $roleIds)
            ->count() > 0;
    }

    /**
     * 后台列表（含草稿与下架）
     */
    public function adminList(array $params, array $visibleSpaceIds, ?int $onlyAuthorId = null): array
    {
        if ($visibleSpaceIds === []) {
            return ['total' => 0, 'page' => 1, 'size' => (int) ($params['size'] ?? 10), 'list' => []];
        }

        $page = max(1, (int) ($params['page'] ?? 1));
        $size = min(100, max(1, (int) ($params['size'] ?? 10)));

        $status        = (string) ($params['status'] ?? '');
        $allowedStatus = [
            Article::STATUS_DRAFT,
            Article::STATUS_PUBLISHED,
            Article::STATUS_OFFLINE,
            Article::STATUS_DELETED,
        ];
        if ($status !== '' && !in_array($status, $allowedStatus, true)) {
            return ['total' => 0, 'page' => $page, 'size' => $size, 'list' => []];
        }

        $query = Article::where('space_id', 'in', $visibleSpaceIds);
        // 无「知识库查看」权限时只能看到自己创建的文章
        if ($onlyAuthorId !== null && $onlyAuthorId > 0) {
            $query->where('author_id', $onlyAuthorId);
        }
        // 「全部状态」默认排除回收站；只有显式选择「已删除」才展示删除态
        if ($status === Article::STATUS_DELETED) {
            $query->where('status', Article::STATUS_DELETED);
        } else {
            $query->where('status', '<>', Article::STATUS_DELETED);
            if ($status !== '') {
                $query->where('status', $status);
            }
        }

        if (!empty($params['space_id'])) {
            $query->where('space_id', (int) $params['space_id']);
        }
        if (!empty($params['category_id'])) {
            $query->where('category_id', 'in', $this->categoryWithDescendants((int) $params['category_id']));
        }
        if (!empty($params['doc_type_id'])) {
            $query->where('doc_type_id', (int) $params['doc_type_id']);
        }
        if (!empty($params['keyword'])) {
            $query->where('title', 'like', '%' . $params['keyword'] . '%');
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('updated_at', 'desc')
            ->order('id', 'desc')
            ->page($page, $size)
            ->select()
            ->toArray();

        return ['total' => $total, 'page' => $page, 'size' => $size, 'list' => $this->attachDocTypeNames($list)];
    }

    /**
     * 新建文章（正文过 XSS 白名单）
     */
    public function create(array $data, int $userId, ?array $attachmentIds = null): Article
    {
        $data['content']     = PurifierService::purify((string) ($data['content'] ?? ''));
        if ($attachmentIds !== null) {
            $data['content'] = $this->stripRemovedAttachmentNodes((string) $data['content'], $attachmentIds);
        }
        $data['author_id']   = $userId;
        $data['view_count']  = 0;
        $data['created_at']  = date('Y-m-d H:i:s');
        $data['updated_at']  = date('Y-m-d H:i:s');
        if (($data['status'] ?? '') === Article::STATUS_PUBLISHED) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }
        $article = Article::create($data);
        $this->bindContentAssets((int) $article->id, (string) $data['content']);
        if ($attachmentIds !== null) {
            $this->syncListedAttachments((int) $article->id, (string) $data['content'], $attachmentIds, $userId, kb_is_super());
        }
        $this->snapshotVersion($article->toArray(), $userId);
        return $article;
    }

    /**
     * 把正文引用的上传资源归属到本文。
     *
     * 正文粘贴/插入的图片走 admin.Upload/image，前端只把视频、文件附件收集进
     * attachments 调用 attach 接口，正文图片不在该列表里。若不按正文回填，
     * 受限文章的图片会一直是 article_id=0 的游离附件，/kbapi/assets 只能校验登录，
     * 无法按文章可见性拦截。兼容历史正文里的 /uploads/knowledge/... 直接 URL。
     * 同时供 php think kb:asset-bind 回填历史文章，返回本次绑定的附件数。
     */
    public function bindContentAssets(int $articleId, string $content): int
    {
        if ($articleId <= 0 || $content === ''
            || !preg_match_all('#(?:src|href)\s*=\s*["\']([^"\']+)["\']#i', $content, $matches)) {
            return 0;
        }

        $prefix = rtrim(upload_prefix('knowledge'), '/');
        $paths  = [];
        foreach ($matches[1] as $url) {
            $url  = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $path = '';
            if (preg_match('~[?&]path=([^&#"\']+)~', $url, $m)) {
                $path = rawurldecode($m[1]);
            } elseif ($prefix !== '' && ($pos = strpos($url, $prefix . '/')) !== false) {
                $path = substr($url, $pos + strlen($prefix) + 1);
            }
            $path = ltrim($path, '/');
            if ($path !== '' && preg_match('#^(image|video|file)/[A-Za-z0-9_./-]+$#', $path)
                && !str_contains($path, '..')) {
                $paths[$path] = true;
            }
        }

        if ($paths === []) {
            return 0;
        }
        return (int) Db::name('kb_attachment')
            ->whereIn('path', array_keys($paths))
            ->where('article_id', 0)
            ->update(['article_id' => $articleId]);
    }

    /**
     * 清理正文里不在当前附件清单中的视频 / 文件占位节点。
     * 前端移除按钮已做同样处理，这里兜底历史正文和旧客户端，避免阅读页残留卡片。
     */
    private function stripRemovedAttachmentNodes(string $content, array $attachmentIds): string
    {
        if ($content === '') {
            return $content;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $attachmentIds))));

        $patterns = [
            '#<div\b[^>]*\bdata-kb-(?:video|file)="(\d+)"[^>]*>.*?</div>#is',
            '#<a\b[^>]*\bdata-kb-file="(\d+)"[^>]*>.*?</a>#is',
        ];
        foreach ($patterns as $pattern) {
            $content = preg_replace_callback(
                $pattern,
                static function (array $match) use ($ids): string {
                    return in_array((int) $match[1], $ids, true) ? $match[0] : '';
                },
                $content
            ) ?? $content;
        }
        return $content;
    }

    /**
     * 按编辑页当前清单全量同步视频 / 文件附件归属。
     *
     * 正文占位节点与 attachment_ids 取并集：移除按钮会删掉正文占位并从清单去掉 ID，
     * 保存后服务端把不再出现的附件 article_id 清零，阅读页就不会再返回或渲染它。
     * 图片仍由 bindContentAssets 按正文 URL 单独绑定，不参与这里的解绑。
     */
    private function syncListedAttachments(
        int $articleId,
        string $content,
        array $attachmentIds,
        int $userId,
        bool $isSuperAdmin
    ): void {
        if ($articleId <= 0) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $attachmentIds))));
        if (preg_match_all('/data-kb-(?:video|file)\s*=\s*["\'](\d+)["\']/i', $content, $matches)) {
            $ids = array_values(array_unique(array_filter(array_merge(
                $ids,
                array_map('intval', $matches[1])
            ))));
        }

        if ($ids !== []) {
            $query = Db::name('kb_attachment')
                ->whereIn('id', $ids)
                ->whereIn('article_id', [0, $articleId])
                ->whereIn('type', ['video', 'file']);
            if (!$isSuperAdmin && $userId > 0) {
                $query->where('created_by', $userId);
            }
            $query->update(['article_id' => $articleId]);
        }

        // 仅解绑视频 / 文件；正文图片的归属由 URL 绑定逻辑维护
        $stale = Db::name('kb_attachment')
            ->where('article_id', $articleId)
            ->whereIn('type', ['video', 'file']);
        if ($ids !== []) {
            $stale->whereNotIn('id', $ids);
        }
        $stale->update(['article_id' => 0]);
    }

    /**
     * 更新文章（正文过 XSS 白名单）
     */
    public function update(int $id, array $data, ?array $attachmentIds = null): bool
    {
        $article = Article::where('id', $id)
            ->where('status', '<>', Article::STATUS_DELETED)
            ->find();
        if (!$article) {
            return false;
        }
        $before = $article->toArray();

        if (isset($data['content'])) {
            $data['content'] = PurifierService::purify((string) $data['content']);
            if ($attachmentIds !== null) {
                $data['content'] = $this->stripRemovedAttachmentNodes((string) $data['content'], $attachmentIds);
            }
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        if (($data['status'] ?? '') === Article::STATUS_PUBLISHED && !$article->published_at) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }

        $saved = $article->save($data) !== false;
        if ($saved) {
            // 保存「本次保存后」的状态作为一条版本；内容/标题未变的空保存由 snapshotVersion 去重
            $this->snapshotVersion(array_merge($before, $data), kb_user_id());
            if (isset($data['content'])) {
                $this->bindContentAssets($id, (string) $data['content']);
            }
            if ($attachmentIds !== null) {
                $content = (string) ($data['content'] ?? $article->content ?? '');
                $this->syncListedAttachments($id, $content, $attachmentIds, kb_user_id(), kb_is_super());
            }
        }
        return $saved;
    }

    /**
     * 上架 / 下架（回收站中的文章不允许改状态）
     */
    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, [
            Article::STATUS_DRAFT,
            Article::STATUS_PUBLISHED,
            Article::STATUS_OFFLINE,
        ], true)) {
            return false;
        }

        $article = Article::where('id', $id)
            ->where('status', '<>', Article::STATUS_DELETED)
            ->find();
        if (!$article) {
            return false;
        }

        $patch = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if ($status === Article::STATUS_PUBLISHED && !$article->published_at) {
            $patch['published_at'] = date('Y-m-d H:i:s');
        }

        return $article->save($patch) !== false;
    }

    /**
     * 移入回收站：只打标记，文章行 / 附件记录 / 授权 / 磁盘文件一律保留。
     * 磁盘零增长，恢复时目录、空间、附件原样回来。
     */
    public function softDelete(int $id, int $userId): bool
    {
        $article = Article::where('id', $id)
            ->where('status', '<>', Article::STATUS_DELETED)
            ->find();
        if (!$article) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        return $article->save([
            'prev_status' => (string) $article->status,
            'status'      => Article::STATUS_DELETED,
            'deleted_at'  => $now,
            'deleted_by'  => $userId,
            'updated_at'  => $now,
        ]) !== false;
    }

    /** 从回收站恢复：回到删除前状态，目录 / 空间 / 附件均未改动 */
    public function restore(int $id): bool
    {
        $article = Article::where('id', $id)
            ->where('status', Article::STATUS_DELETED)
            ->find();
        if (!$article) {
            return false;
        }

        $prev = (string) ($article->prev_status ?? '');
        if (!in_array($prev, [
            Article::STATUS_DRAFT,
            Article::STATUS_PUBLISHED,
            Article::STATUS_OFFLINE,
        ], true)) {
            $prev = Article::STATUS_DRAFT;
        }

        // 用 Db 直写：ORM 在 fields_strict 下会把 null 落成空串，回收站标记清不干净
        return Db::name('kb_article')
            ->where('id', $id)
            ->where('status', Article::STATUS_DELETED)
            ->update([
                'status'      => $prev,
                'prev_status' => null,
                'deleted_at'  => null,
                'deleted_by'  => 0,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]) > 0;
    }

    /**
     * 清除：唯一真正删除的入口。立即物理删除附件文件（不进 30 天回收站），
     * 并清掉文章行、附件记录、授权名单与历史版本。DB 操作放在事务里。
     */
    public function purge(int $id): bool
    {
        $article = Article::where('id', $id)
            ->where('status', Article::STATUS_DELETED)
            ->find();
        if (!$article) {
            return false;
        }

        // 正文里引用但未绑定的游离图片先归属到本文，清除时一并回收
        $this->bindContentAssets($id, (string) $article->content);
        $attachments = Db::name('kb_attachment')->where('article_id', $id)->select()->toArray();

        $files = [];
        $cover = trim((string) ($article->cover ?? ''));
        if ($cover !== '' && !str_contains($cover, '..')) {
            $files[$cover] = true;
        }
        foreach ($attachments as $att) {
            foreach (['path', 'thumb_path'] as $key) {
                $rel = trim((string) ($att[$key] ?? ''));
                if ($rel !== '' && !str_contains($rel, '..')) {
                    $files[$rel] = true;
                }
            }
        }

        Db::transaction(function () use ($id): void {
            Db::name('kb_attachment')->where('article_id', $id)->delete();
            Db::name('kb_article_version')->where('article_id', $id)->delete();
            ArticleEditor::where('article_id', $id)->delete();
            ArticleViewer::where('article_id', $id)->delete();
            Db::name('kb_article')->where('id', $id)->delete();
        });

        // 立即释放磁盘；单文件失败只记日志，不回滚已提交的 DB 清理
        foreach (array_keys($files) as $rel) {
            $abs = upload_abs_path('knowledge', $rel);
            if (!is_file($abs)) {
                $legacy = upload_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $abs = is_file($legacy) ? $legacy : $abs;
            }
            if (is_file($abs) && !@unlink($abs)) {
                \think\facade\Log::warning('[purge] 文件删除失败: ' . $abs);
            }
        }
        return true;
    }

    /** 每篇文章保留的历史版本数（超出由 kb:gc 清理） */
    private function versionKeep(): int
    {
        return max(1, (int) config('myconfig.knowledge.version_keep', 20));
    }

    /**
     * 写入一份历史版本快照：只存文本与基础属性，不复制任何附件二进制。
     * best-effort：任何异常只记日志，绝不影响文章保存（用户确认的稳定优先策略）。
     */
    public function snapshotVersion(array $row, int $userId): void
    {
        try {
            $articleId = (int) ($row['id'] ?? 0);
            if ($articleId <= 0) {
                return;
            }
            $content = (string) ($row['content'] ?? '');
            $hash    = hash('sha256', $content);
            $last    = Db::name('kb_article_version')
                ->where('article_id', $articleId)
                ->order('version_no', 'desc')
                ->find();
            // 正文与标题都没变就不重复留版本，避免空保存刷屏
            if ($last && (string) $last['content_hash'] === $hash
                && (string) $last['title'] === (string) ($row['title'] ?? '')) {
                return;
            }

            Db::name('kb_article_version')->insert([
                'article_id'   => $articleId,
                'version_no'   => $last ? (int) $last['version_no'] + 1 : 1,
                'title'        => (string) ($row['title'] ?? ''),
                'summary'      => (string) ($row['summary'] ?? ''),
                'content'      => base64_encode(gzcompress($content, 6)),
                'status'       => (string) ($row['status'] ?? Article::STATUS_DRAFT),
                'view_scope'   => (string) ($row['view_scope'] ?? Article::SCOPE_PUBLIC),
                'edit_mode'    => (string) ($row['edit_mode'] ?? Article::EDIT_OPEN),
                'category_id'  => (int) ($row['category_id'] ?? 0),
                'doc_type_id'  => (int) ($row['doc_type_id'] ?? 0),
                'band_color'   => (string) ($row['band_color'] ?? ''),
                'content_hash' => $hash,
                'created_by'   => $userId,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('[version] 快照写入失败: ' . $e->getMessage());
        }
    }

    /** 版本列表（不含正文，避免列表接口传输大文本）；可指定状态用于门户只读过滤 */
    public function versionList(int $articleId, ?string $status = null): array
    {
        $query = Db::name('kb_article_version')->where('article_id', $articleId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        return $query->order('version_no', 'desc')
            ->field('id, article_id, version_no, title, status, created_by, created_at')
            ->select()
            ->toArray();
    }

    /** 版本详情：解压正文；可指定状态用于门户只读过滤 */
    public function versionDetail(int $articleId, int $versionId, ?string $status = null): ?array
    {
        $query = Db::name('kb_article_version')
            ->where('id', $versionId)
            ->where('article_id', $articleId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        $row = $query->find();
        if (!$row) {
            return null;
        }
        $raw   = base64_decode((string) ($row['content'] ?? ''), true);
        $plain = $raw === false ? '' : @gzuncompress($raw);
        $row['content'] = is_string($plain) ? $plain : '';
        return $row;
    }

    /** 回滚：走标准 update（再次净化 + 重新绑定附件），回滚动作本身也会留一条版本 */
    public function restoreVersion(int $articleId, int $versionId): bool
    {
        $version = $this->versionDetail($articleId, $versionId);
        if (!$version) {
            return false;
        }
        return $this->update($articleId, [
            'title'       => (string) $version['title'],
            'summary'     => (string) ($version['summary'] ?? ''),
            'content'     => (string) $version['content'],
            'view_scope'  => (string) ($version['view_scope'] ?? Article::SCOPE_PUBLIC),
            'edit_mode'   => (string) ($version['edit_mode'] ?? Article::EDIT_OPEN),
            'category_id' => (int) ($version['category_id'] ?? 0),
            'doc_type_id' => (int) ($version['doc_type_id'] ?? 0),
            'band_color'  => (string) ($version['band_color'] ?? ''),
        ]);
    }

    /** 供 kb:gc 使用：每篇只保留最近 N 条版本 */
    public function pruneVersions(): int
    {
        $keep = $this->versionKeep();
        $rows = Db::name('kb_article_version')
            ->field('article_id, MAX(version_no) AS max_no')
            ->group('article_id')
            ->select()
            ->toArray();
        $removed = 0;
        foreach ($rows as $row) {
            $cutoff = (int) $row['max_no'] - $keep;
            if ($cutoff > 0) {
                $removed += Db::name('kb_article_version')
                    ->where('article_id', (int) $row['article_id'])
                    ->where('version_no', '<=', $cutoff)
                    ->delete();
            }
        }
        // 文章已清除时留下的孤儿版本
        $removed += Db::execute(
            'DELETE v FROM kb_article_version v LEFT JOIN kb_article a ON a.id = v.article_id WHERE a.id IS NULL'
        );
        return $removed;
    }

    /**
     * 设置授权名单（编辑者 / 查看者），全量替换
     *
     * @param string $kind editor | viewer
     * @param array  $subjects [['subject_type'=>'user','subject_id'=>1], ...]
     */
    public function setGrants(int $articleId, string $kind, array $subjects): void
    {
        $model = $kind === 'viewer' ? ArticleViewer::class : ArticleEditor::class;

        $model::where('article_id', $articleId)->delete();

        $rows = [];
        foreach ($subjects as $item) {
            $type = (string) ($item['subject_type'] ?? '');
            $sid  = (int) ($item['subject_id'] ?? 0);
            if (!in_array($type, ['user', 'role'], true) || $sid <= 0) {
                continue;
            }
            $rows[] = [
                'article_id'   => $articleId,
                'subject_type' => $type,
                'subject_id'   => $sid,
            ];
        }

        if ($rows !== []) {
            $model::insertAll($rows);
        }
    }

    /**
     * 统计（口径 A）：全站数字只统计公开空间
     */
    public function stats(): array
    {
        $spaceIds = (new SpaceService())->countableSpaceIds();

        if ($spaceIds === []) {
            return ['article_count' => 0, 'video_count' => 0, 'view_count' => 0];
        }

        $articleCount = (int) Db::name('kb_article')
            ->where('space_id', 'in', $spaceIds)
            ->where('status', Article::STATUS_PUBLISHED)
            ->count();

        $viewCount = (int) Db::name('kb_article')
            ->where('space_id', 'in', $spaceIds)
            ->where('status', '<>', Article::STATUS_DELETED)
            ->sum('view_count');

        $videoCount = (int) Db::name('kb_attachment')
            ->alias('a')
            ->join('kb_article t', 'a.article_id = t.id')
            ->where('t.space_id', 'in', $spaceIds)
            ->where('t.status', '<>', Article::STATUS_DELETED)
            ->where('a.type', 'video')
            ->count();

        return [
            'article_count' => $articleCount,
            'video_count'   => $videoCount,
            'view_count'    => $viewCount,
        ];
    }
}
