<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\CategoryService;
use app\service\SpaceService;

/**
 * 门户端空间接口：只返回当前用户可见的空间与其分类
 */
class Space extends BaseController
{
    /** 可见空间列表 */
    public function index()
    {
        $spaces = (new SpaceService())->visibleSpaces(kb_user_id(), kb_role_ids(), kb_is_super());
        return resp_ok(['spaces' => $spaces]);
    }

    /** 某空间下的分类树（未授权空间返回空，不泄露存在性） */
    public function categories()
    {
        $spaceId = (int) $this->request->param('id', 0);
        if ($spaceId <= 0) {
            return resp_fail('空间ID不合法');
        }

        $service = new SpaceService();
        if (!$service->findVisible($spaceId, kb_user_id(), kb_role_ids(), kb_is_super())) {
            return resp_ok(['categories' => []]);
        }

        return resp_ok(['categories' => (new CategoryService())->treeBySpace($spaceId)]);
    }
}
