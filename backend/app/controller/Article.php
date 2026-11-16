<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Article as ArticleModel;
use app\service\ArticleService;
use app\service\SpaceService;

/**
 * 门户端文章接口：列表 / 详情 / 统计
 * 全部以「可见空间集合」为作用域，未授权内容不返回
 */
class Article extends BaseController
{
    /** 知识列表 */
    public function index()
    {
        $spaceService = new SpaceService();
        $visible      = $spaceService->visibleSpaceIds(kb_user_id(), kb_role_ids(), kb_is_super());

        $result = (new ArticleService())->portalList([
            'space_id'    => $this->request->get('space_id', 0),
            'category_id' => $this->request->get('category_id', 0),
            'doc_type_id' => $this->request->get('doc_type_id', 0),
            'keyword'     => $this->request->get('keyword', ''),
            'page'        => $this->request->get('page', 1),
            'size'        => $this->request->get('size', 10),
        ], $visible, kb_user_id(), kb_role_ids(), kb_is_super());

        // 兜底复核：与 portalList 的 SQL 前置过滤保持同一口径
        $userId    = kb_user_id();
        $roleIds   = kb_role_ids();
        $isSuper   = kb_is_super();
        $service   = new ArticleService();
        $result['list'] = array_values(array_filter(
            $result['list'],
            fn(array $row) => $service->canViewArticle($row, $userId, $roleIds, $isSuper)
        ));

        return resp_ok($result);
    }

    /** 知识详情 */
    public function detail()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return resp_fail('文章ID不合法');
        }

        $visible = (new SpaceService())->visibleSpaceIds(kb_user_id(), kb_role_ids(), kb_is_super());
        $article = (new ArticleService())->portalDetail($id, kb_user_id(), kb_role_ids(), kb_is_super(), $visible);

        if (!$article) {
            // 未授权或不存在统一返回 404 语义，不泄露存在性
            return resp_fail('知识不存在', 404);
        }

        // 编辑按钮可见性以后端 canEditArticle 为准：前端只有权限码，
        // 无法复现空间 can_manage/can_edit 授权与 kb_article_editor 名单。
        // 单篇对象按文章所属空间计算有效权限，避免跨空间阅读时沿用请求头空间配置。
        $article['can_edit'] = (new ArticleService())->canEditArticle(
            $article,
            kb_user_id(),
            kb_role_ids(),
            kb_is_super(),
            kb_permissions_for_space((int) $article['space_id'])
        );

        return resp_ok(['article' => $article]);
    }

    /** 门户只读：当前可见文章的已发布历史版本列表 */
    public function versions()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return resp_fail('文章ID不合法');
        }
        if (!$this->portalVisible($id)) {
            return resp_fail('知识不存在', 404);
        }

        return resp_ok([
            'list' => (new ArticleService())->versionList($id, ArticleModel::STATUS_PUBLISHED),
        ]);
    }

    /** 门户只读：已发布历史版本正文，不含恢复能力 */
    public function versionDetail()
    {
        $id  = (int) $this->request->param('id', 0);
        $vid = (int) $this->request->param('vid', 0);
        if ($id <= 0 || $vid <= 0) {
            return resp_fail('参数不合法');
        }
        if (!$this->portalVisible($id)) {
            return resp_fail('知识不存在', 404);
        }

        $version = (new ArticleService())->versionDetail($id, $vid, ArticleModel::STATUS_PUBLISHED);
        if (!$version) {
            return resp_fail('历史版本不存在', 404);
        }

        return resp_ok(['version' => $version]);
    }

    /** 门户只读接口的统一可见性校验，不增加浏览量 */
    private function portalVisible(int $id): bool
    {
        $visible = (new SpaceService())->visibleSpaceIds(kb_user_id(), kb_role_ids(), kb_is_super());
        return (new ArticleService())->portalVisibleArticle(
            $id,
            kb_user_id(),
            kb_role_ids(),
            kb_is_super(),
            $visible
        ) !== null;
    }

    /** 全站统计（口径 A：只统计公开空间） */
    public function stats()
    {
        return resp_ok((new ArticleService())->stats());
    }
}
