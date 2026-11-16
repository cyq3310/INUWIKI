<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\SubjectService;

/**
 * 角色 / 人员目录（WIKI 本地快照 kb_subject）。
 *
 * 选择器面向全员开放，路由已挂 AuthCheck，登录后即可查询；
 * 不再额外绑定写权限码，避免只读用户搜不到名称。
 */
class Subject extends BaseController
{
    public function index()
    {
        $type = (string) $this->request->get('type', 'role');
        if (!in_array($type, ['role', 'user'], true)) {
            return resp_fail('类型不合法');
        }
        $keyword = trim((string) $this->request->get('keyword', ''));
        if (mb_strlen($keyword) > 30) {
            return resp_fail('搜索关键词过长');
        }

        $ids = [];
        $raw = trim((string) $this->request->get('ids', ''));
        if ($raw !== '') {
            foreach (explode(',', $raw) as $piece) {
                $id = (int) trim($piece);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $ids = array_values(array_unique(array_slice($ids, 0, SubjectService::MAX_IDS)));
        }

        return resp_ok(['subjects' => (new SubjectService())->search($type, $keyword, $ids)]);
    }
}
