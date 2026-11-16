<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\DocTypeService;
use app\service\SpaceService;
use think\exception\ValidateException;

/**
 * 后台文档类型管理（与目录相互独立）。
 * 后台增删改需 kb:doc_type:manage；门户文章创建页仅需登录即可读取列表。
 */
class DocType extends BaseController
{
    /** 后台：列出当前空间的文档类型（需 kb:doc_type:manage） */
    public function index()
    {
        $spaceId = (int) $this->request->get('space_id', 0);
        if ($spaceId <= 0) {
            return resp_fail('请指定 space_id');
        }
        (new DocTypeService())->ensureDefaults($spaceId);
        return resp_ok(['doc_types' => (new DocTypeService())->listBySpace($spaceId)]);
    }

    /** 新建文档类型 */
    public function save()
    {
        $data = $this->request->post();
        if (!is_array($data)) {
            $data = [];
        }
        try {
            $this->validate($data, [
                'space_id|所属空间'     => 'require|integer',
                'name|类型名称'        => 'require|max:50',
                'visibility|默认可见范围' => 'in:public,space,restricted',
            ]);
        } catch (ValidateException $e) {
            return resp_fail($e->getError());
        }

        $spaceId = (int) $data['space_id'];
        if (!(new SpaceService())->findVisible($spaceId, kb_user_id(), kb_role_ids(), kb_is_super())) {
            return resp_fail('空间不存在或无权限');
        }

        $visibility = (string) ($data['visibility'] ?? 'space');
        $subjects   = is_array($data['subjects'] ?? null) ? $this->normalizeSubjects($data['subjects']) : [];
        if ($visibility !== 'restricted') {
            $subjects = [];
        }

        $doc = (new DocTypeService())->create(
            $spaceId,
            (string) $data['name'],
            (int) ($data['sort'] ?? 0),
            $visibility,
            $subjects
        );
        return resp_ok(['id' => (int) $doc->id], '创建成功');
    }

    /** 编辑文档类型 */
    public function update()
    {
        $id   = (int) $this->request->param('id', 0);
        $data = $this->request->post();
        if (!is_array($data)) {
            $data = [];
        }
        $allow = ['name', 'sort', 'visibility', 'subjects'];
        $patch = array_intersect_key($data, array_flip($allow));
        if ($patch === []) {
            return resp_fail('没有需要更新的字段');
        }
        if (isset($patch['visibility']) && !in_array($patch['visibility'], ['public', 'space', 'restricted'], true)) {
            return resp_fail('默认可见范围非法');
        }
        if (isset($patch['subjects'])) {
            $patch['subjects'] = $this->normalizeSubjects($patch['subjects']);
            if (($patch['visibility'] ?? 'space') !== 'restricted') {
                $patch['subjects'] = [];
            }
        }
        if (!(new DocTypeService())->update($id, $patch)) {
            return resp_fail('文档类型不存在');
        }
        return resp_ok([], '保存成功');
    }

    /** 删除文档类型 */
    public function delete()
    {
        $id = (int) $this->request->param('id', 0);
        if (!(new DocTypeService())->delete($id)) {
            return resp_fail('文档类型不存在');
        }
        return resp_ok([], '已删除');
    }

    /** 门户：文章创建页读取某空间的文档类型（仅需登录） */
    public function portalList()
    {
        $spaceId = (int) $this->request->param('id', 0);
        if ($spaceId <= 0) {
            return resp_fail('请指定 space_id');
        }
        $space = (new SpaceService())->findVisible($spaceId, kb_user_id(), kb_role_ids(), kb_is_super());
        if (!$space) {
            return resp_fail('空间不存在或无权限');
        }
        (new DocTypeService())->ensureDefaults($spaceId);
        return resp_ok(['doc_types' => (new DocTypeService())->listBySpace($spaceId)]);
    }

    /** 过滤授权对象：仅保留 user/role + 合法 ID */
    private function normalizeSubjects(array $subjects): array
    {
        $out = [];
        foreach ($subjects as $item) {
            $type = (string) ($item['subject_type'] ?? '');
            $sid  = (int) ($item['subject_id'] ?? 0);
            if (!in_array($type, ['user', 'role'], true) || $sid <= 0) {
                continue;
            }
            $out[] = ['subject_type' => $type, 'subject_id' => $sid];
        }
        return $out;
    }
}
