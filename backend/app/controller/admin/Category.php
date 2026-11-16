<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\CategoryService;
use app\service\SpaceService;
use think\exception\ValidateException;

/**
 * 后台分类管理：按空间维护分类树
 */
class Category extends BaseController
{
    /** 分类树（?space_id=1） */
    public function index()
    {
        $spaceId = (int) $this->request->get('space_id', 0);
        if ($spaceId <= 0) {
            return resp_fail('请指定 space_id');
        }

        return resp_ok(['categories' => (new CategoryService())->treeBySpace($spaceId)]);
    }

    /** 新建分类 */
    public function save()
    {
        $data = $this->postData();
        try {
            $this->validate($data, [
                'space_id|所属空间' => 'require|integer',
                'name|分类名称'    => 'require|max:' . CategoryService::NAME_MAX_LENGTH,
            ]);
        } catch (ValidateException $e) {
            return resp_fail($e->getError());
        }

        // 系统默认目录每空间唯一，禁止再建同名目录造成结构歧义
        if (trim((string) $data['name']) === CategoryService::DEFAULT_NAME) {
            return resp_fail('「未指定目录」是系统默认目录，无需重复创建');
        }

        $spaceId    = (int) $data['space_id'];
        $parentId   = (int) ($data['parent_id'] ?? 0);
        if (!(new SpaceService())->findVisible($spaceId, kb_user_id(), kb_role_ids(), kb_is_super())) {
            return resp_fail('空间不存在或无权限');
        }

        // 前端弹层已禁用 L5 父级，后端仍需独立校验，避免绕过界面直接调接口建出 L6
        $service    = new CategoryService();
        $levelError = $service->validateParentLevel($spaceId, $parentId);
        if ($levelError !== null) {
            return resp_fail($levelError);
        }

        $cat = $service->create(
            $spaceId,
            $parentId,
            (string) $data['name'],
            (int) ($data['sort'] ?? 0)
        );

        return resp_ok(['id' => (int) $cat->id], '创建成功');
    }

    /** 编辑分类 */
    public function update()
    {
        $id   = (int) $this->request->param('id', 0);
        $data = $this->postData();

        $allow = ['name', 'parent_id', 'sort', 'status'];
        $patch = array_intersect_key($data, array_flip($allow));
        if ($patch === []) {
            return resp_fail('没有需要更新的字段');
        }

        $service = new CategoryService();
        if ($service->isSystem($id)) {
            return resp_fail('「未指定目录」是系统默认目录，不允许调整位置、改名或删除');
        }

        // 重命名同样受 15 字上限约束，避免绕过新增弹层直接调接口写入超长名称
        if (isset($patch['name'])) {
            try {
                $this->validate(
                    ['name' => (string) $patch['name']],
                    ['name|分类名称' => 'require|max:' . CategoryService::NAME_MAX_LENGTH]
                );
            } catch (ValidateException $e) {
                return resp_fail($e->getError());
            }
        }

        // 改父目录同样不能突破 L5（含被移动目录自身的子树高度）
        if (isset($patch['parent_id'])) {
            $levelError = $service->validateMove($id, (int) $patch['parent_id']);
            if ($levelError !== null) {
                return resp_fail($levelError);
            }
        }

        if (!$service->update($id, $patch)) {
            return resp_fail('分类不存在');
        }

        return resp_ok([], '保存成功');
    }

    /** 删除分类 */
    public function delete()
    {
        $id      = (int) $this->request->param('id', 0);
        $service = new CategoryService();
        if ($service->isSystem($id)) {
            return resp_fail('「未指定目录」是系统默认目录，不允许删除');
        }
        [$ok, $msg] = $service->delete($id);
        return $ok ? resp_ok([], $msg) : resp_fail($msg);
    }

    private function postData(): array
    {
        $data = $this->request->post();
        return is_array($data) ? $data : [];
    }
}
