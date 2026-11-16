<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\UploadService;
use think\facade\Db;

/**
 * 后台上传接口：分片四接口（init/check/chunk/complete）+ 图片快捷上传
 */
class Upload extends BaseController
{
    /** 初始化上传任务，返回 uploadId */
    public function init()
    {
        $filename = (string) $this->request->post('filename', '');
        $size     = (int) $this->request->post('size', 0);
        $type     = (string) $this->request->post('type', 'video');

        if ($filename === '') {
            return resp_fail('缺少文件名');
        }

        try {
            $result = (new UploadService())->init(kb_user_id(), $filename, $size, $type);
        } catch (\RuntimeException $e) {
            return resp_fail($e->getMessage());
        }

        return resp_ok($result);
    }

    /** 查询已上传分片（断点续传） */
    public function check()
    {
        $uploadId = (string) $this->request->get('upload_id', '');
        if ($uploadId === '') {
            return resp_fail('缺少 upload_id');
        }

        try {
            return resp_ok((new UploadService())->check($uploadId));
        } catch (\RuntimeException $e) {
            return resp_fail($e->getMessage());
        }
    }

    /** 上传单片 */
    public function chunk()
    {
        $uploadId = (string) $this->request->post('upload_id', '');
        $index    = (int) $this->request->post('index', -1);
        $type     = (string) $this->request->post('type', 'video');

        $file = $this->request->file('file');
        if (!$file) {
            // 超过 post_max_size 时 PHP 会直接丢弃整个请求体，此时拿不到文件
            return resp_fail('未收到分片。若分片较大，请检查 php.ini 的 upload_max_filesize 与 post_max_size');
        }
        if ($uploadId === '' || $index < 0) {
            return resp_fail('参数不合法');
        }

        try {
            (new UploadService())->chunk($uploadId, $index, $file, $type);
        } catch (\RuntimeException $e) {
            return resp_fail($e->getMessage());
        }

        return resp_ok(['index' => $index]);
    }

    /** 完成上传：校验齐全后入队处理，立即返回 */
    public function complete()
    {
        $uploadId = (string) $this->request->post('upload_id', '');
        $type     = (string) $this->request->post('type', 'video');

        if ($uploadId === '') {
            return resp_fail('缺少 upload_id');
        }

        try {
            $result = (new UploadService())->complete($uploadId, $type);
        } catch (\RuntimeException $e) {
            return resp_fail($e->getMessage());
        }

        return resp_ok($result, '已提交处理');
    }

    /** 附件状态查询（前端轮询转码进度） */
    public function attachment()
    {
        $id = (int) $this->request->get('id', 0);
        $row = Db::name('kb_attachment')->where('id', $id)->find();
        if (!$row) {
            return resp_fail('附件不存在');
        }

        return resp_ok([
            'id'         => (int) $row['id'],
            'type'       => $row['type'],
            'status'     => $row['status'],
            'duration'   => (int) $row['duration'],
            'size'       => (int) $row['size'],
            'thumb_path' => $row['thumb_path'],
            'fail_reason' => $row['fail_reason'],
        ]);
    }

    /** 图片快捷上传（封面、正文配图，走同一套校验与相对路径约定） */
    public function image()
    {
        $file = $this->request->file('file');
        if (!$file) {
            return resp_fail('请选择要上传的图片');
        }
        if (!$file->isValid()) {
            return resp_fail('上传被服务器拒绝，多为超过 php.ini 的 upload_max_filesize');
        }

        try {
            $this->validate(
                ['file' => $file],
                ['file' => 'fileExt:' . upload_allowed_ext('image') . '|fileSize:' . upload_max_size('image')],
                [
                    'file.fileExt'  => '仅支持 ' . upload_allowed_ext('image') . ' 格式',
                    'file.fileSize' => '图片不能超过 ' . (int) ceil(upload_max_size('image') / 1048576) . 'MB',
                ]
            );
        } catch (\think\exception\ValidateException $e) {
            return resp_fail($e->getError());
        }

        $ext      = strtolower(pathinfo($file->getOriginalName(), PATHINFO_EXTENSION));
        // 按 年/月 分层，避免单日目录里堆满当月文件；库里存完整相对路径，历史 Ymd 路径照常可读
        $relative = 'image/' . date('Y/m') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
        // 与 knowledge.upload_prefix（/uploads/knowledge）对齐：库里存相对该前缀的路径，
        // 物理落在 upload_root()/knowledge/image/...，前端 resolveUploadUrl 才能取到文件。
        $dir      = upload_dir('knowledge', dirname($relative));

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return resp_fail('目录创建失败');
        }
        if (!path_within_upload_root($dir)) {
            return resp_fail('目标路径越界');
        }

        $name = basename($relative);
        if (!$file->move($dir, $name, false)) {
            return resp_fail('图片保存失败');
        }

        $abs = $dir . DIRECTORY_SEPARATOR . $name;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($abs) ?: null;

        $id = Db::name('kb_attachment')->insertGetId([
            'article_id'    => 0,
            'type'          => 'image',
            'original_name' => mb_substr($file->getOriginalName(), 0, 255),
            'path'          => $relative,
            'mime'          => $mime,
            'size'          => (int) filesize($abs),
            'status'        => 'ready',
            'created_by'    => kb_user_id(),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return resp_ok(['id' => $id, 'path' => $relative], '上传成功');
    }
}
