<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\model\Category;
use app\model\Space as SpaceModel;
use app\model\SpaceMember;
use app\service\CategoryService;
use app\service\SpaceService;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 后台空间管理：空间 CRUD + 成员授权（角色 / 个人双粒度）
 */
class Space extends BaseController
{
    /** 空间列表（含文章数与成员数） */
    public function index()
    {
        $spaces = (new SpaceService())->adminSpaces(kb_user_id(), kb_role_ids(), kb_is_super());
        return resp_ok(['spaces' => $spaces]);
    }

    /**
     * 空间名称显示宽度：汉字 / 全角标点按 1.5，其余按 1。
     * 上限 12，对应「最多 8 个汉字或 12 个英文字符」，与前端 utils/space-code.ts 一致。
     */
    private function nameWidth(string $name): float
    {
        $width = 0.0;
        $chars = preg_split('//u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            $width += preg_match('/[\x{3400}-\x{9FFF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', $char) ? 1.5 : 1.0;
        }
        return $width;
    }

    /** 新建空间 */
    public function save()
    {
        $data = $this->postData();
        try {
            $this->validate($data, [
                'name|空间名称' => 'require|max:50',
                'code|空间编码' => 'require|alphaDash|max:16',
            ]);
        } catch (ValidateException $e) {
            return resp_fail($e->getError());
        }

        if ($this->nameWidth((string) $data['name']) > 12.0) {
            return resp_fail('空间名称最多 8 个汉字或 12 个英文字符');
        }

        if (SpaceModel::where('code', $data['code'])->count() > 0) {
            return resp_fail('空间编码已存在');
        }

        $space = SpaceModel::create([
            'name'          => $data['name'],
            'code'          => $data['code'],
            'description'   => $data['description'] ?? null,
            'cover'         => $data['cover'] ?? null,
            'is_restricted' => empty($data['is_restricted']) ? SpaceModel::PUBLIC_SPACE : SpaceModel::RESTRICTED_SPACE,
            'status'        => 1,
            'sort'          => (int) ($data['sort'] ?? 0),
            'created_by'    => kb_user_id(),
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        return resp_ok(['id' => (int) $space->id], '创建成功');
    }

    /** 编辑空间 */
    public function update()
    {
        $id   = (int) $this->request->param('id', 0);
        $data = $this->postData();

        $space = SpaceModel::find($id);
        if (!$space) {
            return resp_fail('空间不存在');
        }

        $allow = ['name', 'description', 'cover', 'is_restricted', 'status', 'sort'];
        $patch = array_intersect_key($data, array_flip($allow));
        if ($patch === []) {
            return resp_fail('没有需要更新的字段');
        }

        if (isset($patch['is_restricted'])) {
            $patch['is_restricted'] = empty($patch['is_restricted']) ? SpaceModel::PUBLIC_SPACE : SpaceModel::RESTRICTED_SPACE;
        }
        $patch['updated_at'] = date('Y-m-d H:i:s');

        $space->save($patch);
        return resp_ok([], '保存成功');
    }

    /** 删除空间（有文章时拒绝，避免孤儿数据） */
    public function delete()
    {
        $id    = (int) $this->request->param('id', 0);
        $space = SpaceModel::find($id);
        if (!$space) {
            return resp_fail('空间不存在');
        }

        $articleCount = Db::name('kb_article')->where('space_id', $id)->count();
        if ($articleCount > 0) {
            return resp_fail('该空间下仍有文章，请先移动或删除');
        }

        SpaceMember::where('space_id', $id)->delete();
        Category::where('space_id', $id)->delete();
        $space->delete();

        return resp_ok([], '删除成功');
    }

    /** 成员列表 */
    public function members()
    {
        $id = (int) $this->request->param('id', 0);
        if (!SpaceModel::find($id)) {
            return resp_fail('空间不存在');
        }
        return resp_ok(['members' => (new SpaceService())->members($id)]);
    }

    /** 设置成员授权（按角色或按个人，已存在则更新） */
    public function setMember()
    {
        $id    = (int) $this->request->param('id', 0);
        $data  = $this->postData();
        $type  = (string) ($data['subject_type'] ?? '');
        $subId = (int) ($data['subject_id'] ?? 0);

        if (!SpaceModel::find($id)) {
            return resp_fail('空间不存在');
        }
        if (!in_array($type, [SpaceMember::TYPE_USER, SpaceMember::TYPE_ROLE], true)) {
            return resp_fail('授权类型不合法，仅支持 user 或 role');
        }
        if ($subId <= 0) {
            return resp_fail('授权对象ID不合法');
        }

        (new SpaceService())->setMember(
            $id,
            $type,
            $subId,
            empty($data['can_view']) ? 0 : 1,
            empty($data['can_edit']) ? 0 : 1,
            empty($data['can_manage']) ? 0 : 1
        );

        return resp_ok([], '授权已保存');
    }

    /** 移除成员授权 */
    public function removeMember()
    {
        $id    = (int) $this->request->param('id', 0);
        $type  = (string) $this->request->param('subject_type', '');
        $subId = (int) $this->request->param('subject_id', 0);

        if (!in_array($type, [SpaceMember::TYPE_USER, SpaceMember::TYPE_ROLE], true) || $subId <= 0) {
            return resp_fail('参数不合法');
        }

        (new SpaceService())->removeMember($id, $type, $subId);
        return resp_ok([], '授权已移除');
    }

    /**
     * 取提交参数（兼容 JSON 与表单）
     */
    private function postData(): array
    {
        $data = $this->request->post();
        return is_array($data) ? $data : [];
    }
}
