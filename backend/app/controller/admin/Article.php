<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\model\Article as ArticleModel;
use app\model\ArticleEditor;
use app\model\ArticleViewer;
use app\service\ArticlePolicy;
use app\service\ArticleService;
use app\service\CategoryService;
use app\service\SpaceService;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 后台文章管理：CRUD、上架下架、授权名单
 *
 * 权限判定统一走 ArticlePolicy（对象级）：本人默认可查看 / 编辑 / 删除 / 发布下架
 * 自己创建的文章，操作他人文章才需要对应权限码；故这些接口不再用路由中间件一刀切拦截。
 */
class Article extends BaseController
{
    /** 文章列表：无「知识库查看」权限时只返回本人文章 */
    public function index()
    {
        $visible = (new SpaceService())->visibleSpaceIds(kb_user_id(), kb_role_ids(), kb_is_super());

        $result = (new ArticleService())->adminList([
            'space_id'    => $this->request->get('space_id', 0),
            'category_id' => $this->request->get('category_id', 0),
            'doc_type_id' => $this->request->get('doc_type_id', 0),
            'status'      => $this->request->get('status', ''),
            'keyword'     => $this->request->get('keyword', ''),
            'page'        => $this->request->get('page', 1),
            'size'        => $this->request->get('size', 10),
        ], $visible, ArticlePolicy::listScope());

        return resp_ok($result);
    }

    /** 新建文章 */
    public function save()
    {
        $data = $this->postData();
        try {
            $this->validate($data, [
                'title|标题'      => 'require|max:16',
                'space_id|空间'   => 'require|integer',
                'category_id|分类' => 'integer',
            ]);
        } catch (ValidateException $e) {
            return resp_fail($e->getError());
        }

        $spaceId = (int) $data['space_id'];
        if (!(new SpaceService())->canCreateInSpace($spaceId, kb_user_id(), kb_role_ids(), kb_is_super())) {
            return resp_fail('该空间不允许你创建内容');
        }

        // 编辑页当前附件清单；旧客户端未带该字段时不触发全量解绑
        $attachmentIds = array_key_exists('attachment_ids', $data) && is_array($data['attachment_ids'])
            ? $data['attachment_ids']
            : null;

        // 未指定目录的文章统一归入该空间的「未指定目录」，不再散落为 category_id=0
        $categoryId = (int) ($data['category_id'] ?? 0);
        if ($categoryId <= 0) {
            $categoryId = (new CategoryService())->ensureDefaultCategory($spaceId);
        }

        $article = (new ArticleService())->create([
            'space_id'    => $spaceId,
            'category_id' => $categoryId,
            'title'       => (string) $data['title'],
            'summary'     => $data['summary'] ?? null,
            'band_color'  => $this->normalizeBandColor($data['band_color'] ?? null),
            'doc_type_id' => (int) ($data['doc_type_id'] ?? 0),
            'content'     => $data['content'] ?? '',
            'status'      => $data['status'] ?? ArticleModel::STATUS_DRAFT,
            'view_scope'  => $data['view_scope'] ?? ArticleModel::SCOPE_PUBLIC,
            'edit_mode'   => $data['edit_mode'] ?? ArticleModel::EDIT_OPEN,
        ], kb_user_id(), $attachmentIds);

        return resp_ok(['id' => (int) $article->id], '创建成功');
    }

    /** 编辑文章 */
    public function update()
    {
        $id   = (int) $this->request->param('id', 0);
        $data = $this->postData();
        // 编辑页当前附件清单；旧客户端未带该字段时不触发全量解绑
        $attachmentIds = array_key_exists('attachment_ids', $data) && is_array($data['attachment_ids'])
            ? $data['attachment_ids']
            : null;

        $article = $this->activeArticle($id);
        if (!$article) {
            return resp_fail('文章不存在');
        }

        if (!$this->canManage($article, 'kb:article:update')) {
            return resp_fail('你没有编辑该知识的权限', 403);
        }

        $row = $article->toArray();
        $allow = ['title', 'summary', 'content', 'status', 'category_id', 'doc_type_id', 'view_scope', 'edit_mode', 'band_color'];
        $patch = array_intersect_key($data, array_flip($allow));
        if ($patch === []) {
            return resp_fail('没有需要更新的字段');
        }
        if (array_key_exists('band_color', $patch)) {
            $patch['band_color'] = $this->normalizeBandColor($patch['band_color']);
        }

        if (isset($patch['category_id']) && (int) $patch['category_id'] <= 0) {
            $patch['category_id'] = (new CategoryService())->ensureDefaultCategory((int) $row['space_id']);
        }
        (new ArticleService())->update($id, $patch, $attachmentIds);
        return resp_ok([], '保存成功');
    }

    /**
     * 把已上传的游离附件挂到本文。
     *
     * 上传发生在文章创建之前，kb_attachment.article_id 初值为 0；
     * 前端在 save/update 成功后带 attachment_ids 调本接口补写归属。
     * 只允许绑定「尚未归属其他文章」的附件，且非超管仅限本人上传，避免跨文章抢资源。
     */
    public function attach()
    {
        $id  = (int) $this->request->param('id', 0);
        $ids = $this->request->post('attachment_ids', []);

        $article = $this->activeArticle($id);
        if (!$article) {
            return resp_fail('文章不存在', 404);
        }
        if (!$this->canManage($article, 'kb:article:update')) {
            return resp_fail('你没有编辑该知识的权限', 403);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        if ($ids === []) {
            return resp_ok(['attached' => 0]);
        }

        $query = Db::name('kb_attachment')
            ->whereIn('id', $ids)
            ->whereIn('article_id', [0, $id]);
        if (!kb_is_super()) {
            $query->where('created_by', kb_user_id());
        }
        $attached = $query->update(['article_id' => $id]);

        return resp_ok(['attached' => $attached]);
    }

    /** 上架 / 下架 */
    public function setStatus()
    {
        $id     = (int) $this->request->param('id', 0);
        $status = (string) $this->request->post('status', '');

        if (!in_array($status, [ArticleModel::STATUS_PUBLISHED, ArticleModel::STATUS_OFFLINE], true)) {
            return resp_fail('状态不合法');
        }

        $article = $this->activeArticle($id);
        if (!$article) {
            return resp_fail('文章不存在');
        }
        if (!$this->canManage($article, 'kb:article:publish')) {
            return resp_fail('你没有发布或下架该知识的权限', 403);
        }

        if (!(new ArticleService())->setStatus($id, $status)) {
            return resp_fail('文章不存在');
        }

        return resp_ok([], $status === ArticleModel::STATUS_PUBLISHED ? '已发布' : '已下架');
    }

    /** 删除文章：只移入回收站，文章行 / 附件 / 文件全部保留 */
    public function delete()
    {
        $id = (int) $this->request->param('id', 0);
        $article = $this->activeArticle($id);
        if (!$article) {
            return resp_fail('文章不存在');
        }
        if (!$this->canManage($article, 'kb:article:delete')) {
            return resp_fail('你没有删除该知识的权限', 403);
        }
        if (!(new ArticleService())->softDelete($id, kb_user_id())) {
            return resp_fail('删除失败');
        }
        return resp_ok([], '已移入回收站');
    }

    /** 恢复：回到删除前状态，目录 / 空间 / 附件原样回来 */
    public function restore()
    {
        $id = (int) $this->request->param('id', 0);
        $article = $this->deletedArticle($id);
        if (!$article) {
            return resp_fail('已删除文章不存在', 404);
        }
        if (!$this->canManage($article, 'kb:article:delete')) {
            return resp_fail('你没有恢复该知识的权限', 403);
        }
        if (!(new ArticleService())->restore($id)) {
            return resp_fail('恢复失败');
        }
        return resp_ok([], '已恢复');
    }

    /** 清除：唯一真正删除的入口，立即物理删除附件 / 图片 / 封面 / 历史版本 */
    public function purge()
    {
        $id = (int) $this->request->param('id', 0);
        $article = $this->deletedArticle($id);
        if (!$article) {
            return resp_fail('已删除文章不存在', 404);
        }
        if (!$this->canPurge($article)) {
            return resp_fail('你没有清除该知识的权限', 403);
        }
        if (!(new ArticleService())->purge($id)) {
            return resp_fail('清除失败');
        }
        return resp_ok([], '已彻底清除');
    }

    /** 历史版本列表（不含正文） */
    public function versions()
    {
        $id = (int) $this->request->param('id', 0);
        $article = $this->activeArticle($id);
        if (!$article) {
            return resp_fail('文章不存在', 404);
        }
        if (!$this->canManage($article, 'kb:article:list')) {
            return resp_fail('你没有查看该知识的权限', 403);
        }
        return resp_ok(['list' => (new ArticleService())->versionList($id)]);
    }

    /** 历史版本详情（含解压后的正文） */
    public function versionDetail()
    {
        $id  = (int) $this->request->param('id', 0);
        $vid = (int) $this->request->param('vid', 0);
        $article = $this->activeArticle($id);
        if (!$article) {
            return resp_fail('文章不存在', 404);
        }
        if (!$this->canManage($article, 'kb:article:list')) {
            return resp_fail('你没有查看该知识的权限', 403);
        }
        $version = (new ArticleService())->versionDetail($id, $vid);
        if (!$version) {
            return resp_fail('版本不存在', 404);
        }
        return resp_ok(['version' => $version]);
    }

    /** 回滚到指定历史版本 */
    public function restoreVersion()
    {
        $id  = (int) $this->request->param('id', 0);
        $vid = (int) $this->request->param('vid', 0);
        $article = $this->activeArticle($id);
        if (!$article) {
            return resp_fail('文章不存在', 404);
        }
        if (!$this->canManage($article, 'kb:article:update')) {
            return resp_fail('你没有编辑该知识的权限', 403);
        }
        if (!(new ArticleService())->restoreVersion($id, $vid)) {
            return resp_fail('版本不存在或恢复失败');
        }
        return resp_ok([], '已恢复到该版本');
    }

    /** 后台详情：编辑页刷新时取回完整数据（草稿 / 下架也能拿到） */
    public function detail()
    {
        $id = (int) $this->request->param('id', 0);
        $article = $this->activeArticle($id);
        if (!$article) {
            return resp_fail('文章不存在', 404);
        }
        if (!$this->canManage($article, 'kb:article:update')) {
            return resp_fail('你没有查看该知识的权限', 403);
        }

        $row = $article->toArray();
        $row['attachments'] = Db::name('kb_attachment')
            ->where('article_id', $id)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $row['grants'] = [
            'editor' => ArticleEditor::where('article_id', $id)->select()->toArray(),
            'viewer' => ArticleViewer::where('article_id', $id)->select()->toArray(),
        ];
        return resp_ok(['article' => $row]);
    }

    /** 取一篇非回收站文章：后台所有编辑类操作的统一入口 */
    private function activeArticle(int $id): ?ArticleModel
    {
        return ArticleModel::where('id', $id)
            ->where('status', '<>', ArticleModel::STATUS_DELETED)
            ->find();
    }

    /** 取一篇回收站中的文章 */
    private function deletedArticle(int $id): ?ArticleModel
    {
        return ArticleModel::where('id', $id)
            ->where('status', ArticleModel::STATUS_DELETED)
            ->find();
    }

    /** 对象级判定：本人默认放行，他人需 $code（权限按文章所属空间计算） */
    private function canManage(ArticleModel $article, string $code): bool
    {
        $row = $article->toArray();
        return (new ArticlePolicy($row))->canModify($row, $code);
    }

    /** 清除必须显式持有 kb:article:purge，作者归属不豁免 */
    private function canPurge(ArticleModel $article): bool
    {
        $row = $article->toArray();
        return (new ArticlePolicy($row))->canPurge($row);
    }

    /** 授权名单（editor 可编辑 / viewer 可见） */
    public function grants()
    {
        $id   = (int) $this->request->param('id', 0);
        $kind = (string) $this->request->get('kind', 'editor');
        $model = $kind === 'viewer' ? ArticleViewer::class : ArticleEditor::class;

        $article = $this->activeArticle($id);
        if (!$article) {
            return resp_fail('文章不存在', 404);
        }
        if (!$this->canManage($article, 'kb:article:update')) {
            return resp_fail('你没有编辑该知识的权限', 403);
        }
        return resp_ok(['grants' => $model::where('article_id', $id)->select()->toArray()]);
    }

    /** 设置授权名单（全量替换） */
    public function setGrants()
    {
        $id     = (int) $this->request->param('id', 0);
        $data   = $this->postData();
        $kind   = (string) ($data['kind'] ?? 'editor');
        $items  = $data['subjects'] ?? [];

        $article = $this->activeArticle($id);
        if (!$article) {
            return resp_fail('文章不存在');
        }
        if (!$this->canManage($article, 'kb:article:update')) {
            return resp_fail('你没有编辑该知识的权限', 403);
        }
        if (!in_array($kind, ['editor', 'viewer'], true)) {
            return resp_fail('授权类型不合法');
        }
        if (!is_array($items)) {
            return resp_fail('授权名单格式不合法');
        }

        (new ArticleService())->setGrants($id, $kind, $items);
        return resp_ok([], '授权已保存');
    }

    /** 色带颜色只接受 8 种预设值，非法值回落为 null（前端按文章 ID 分配） */
    private function normalizeBandColor($value): ?string
    {
        $color = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($color, ['g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8'], true) ? $color : null;
    }

    private function postData(): array
    {
        $data = $this->request->post();
        return is_array($data) ? $data : [];
    }
}
