<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use think\file\UploadedFile;

/**
 * 分片上传服务：init / check / chunk / complete
 *
 * 设计要点：
 * 1. 单片 5MB，规避 PHP upload_max_filesize / post_max_size 限制，
 *    同时消除"超限导致整个请求体被丢弃"的隐患
 * 2. 续传键由服务端下发 uploadId，前端以「文件名+大小+lastModified」存 localStorage
 * 3. 上传校验：扩展名 + 大小 + finfo MIME 嗅探（三重）
 * 4. 路径一律 realpath 校验，杜绝目录穿越
 * 5. 数据库只存相对路径（与 INUEIP 约定一致）
 */
class UploadService
{
    /**
     * 初始化上传任务
     */
    public function init(int $userId, string $filename, int $size, string $type = 'video'): array
    {
        $this->assertType($type);

        $maxSize = upload_max_size($type);
        if ($size <= 0) {
            throw new \RuntimeException('文件大小不合法');
        }
        if ($size > $maxSize) {
            throw new \RuntimeException(sprintf('文件超过上限（%s），当前上限 %.0f MB', $filename, $maxSize / 1048576));
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!$this->extAllowed($ext, $type)) {
            throw new \RuntimeException('不支持的文件类型：' . $ext);
        }

        $chunkSize  = (int) config('myconfig.knowledge.chunk_size') ?: 5 * 1024 * 1024;
        $chunkTotal = (int) ceil($size / $chunkSize);
        $uploadId   = bin2hex(random_bytes(16));

        Db::name('kb_upload')->insert([
            'upload_id'    => $uploadId,
            'user_id'      => $userId,
            'filename'     => mb_substr($filename, 0, 255),
            'size'         => $size,
            'chunk_size'   => $chunkSize,
            'chunk_total'  => $chunkTotal,
            'status'       => 'uploading',
            'attachment_id' => 0,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        @mkdir($this->chunkDir($uploadId), 0755, true);

        return [
            'upload_id'   => $uploadId,
            'chunk_size'  => $chunkSize,
            'chunk_total' => $chunkTotal,
        ];
    }

    /**
     * 已上传的分片索引（用于断点续传与秒传判断）
     */
    public function check(string $uploadId): array
    {
        $task = $this->task($uploadId);
        $dir  = $this->chunkDir($uploadId);

        $uploaded = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $name) {
                if (preg_match('/^(\d+)\.part$/', $name, $m)) {
                    $uploaded[] = (int) $m[1];
                }
            }
        }
        sort($uploaded);

        return [
            'upload_id'   => $uploadId,
            'chunk_total' => (int) $task['chunk_total'],
            'uploaded'    => $uploaded,
            'status'      => $task['status'],
        ];
    }

    /**
     * 接收单片
     */
    public function chunk(string $uploadId, int $index, UploadedFile $file, string $type = 'video'): void
    {
        $task = $this->task($uploadId);
        if ($task['status'] !== 'uploading') {
            throw new \RuntimeException('上传任务已结束');
        }
        if ($index < 0 || $index >= (int) $task['chunk_total']) {
            throw new \RuntimeException('分片序号不合法');
        }
        if (!$file->isValid()) {
            throw new \RuntimeException('分片上传失败，请检查 php.ini 的 upload_max_filesize 与 post_max_size');
        }

        // 单片大小校验（最后一片可小于 chunk_size）
        $maxChunk = (int) $task['chunk_size'];
        if ($file->getSize() > $maxChunk) {
            throw new \RuntimeException('分片大小超过约定值');
        }

        // MIME 嗅探：仅在首片做（后续片是同一文件的切片）
        if ($index === 0 && (bool) config('myconfig.knowledge.mime_sniff', true)) {
            $this->assertMime($file->getRealPath(), $type);
        }

        $dir = $this->chunkDir($uploadId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('分片目录创建失败');
        }

        $target = $dir . DIRECTORY_SEPARATOR . $index . '.part';
        if (!$file->move($dir, $index . '.part', false)) {
            throw new \RuntimeException('分片写入失败');
        }
    }

    /**
     * 完成上传：校验分片齐全 → 建附件记录 → 入队处理
     */
    public function complete(string $uploadId, string $type = 'video'): array
    {
        $task   = $this->task($uploadId);
        $status = $this->check($uploadId);

        $missing = array_diff(range(0, (int) $task['chunk_total'] - 1), $status['uploaded']);
        if ($missing !== []) {
            throw new \RuntimeException('分片尚未上传完整，仍缺 ' . count($missing) . ' 片');
        }

        $ext      = strtolower(pathinfo($task['filename'], PATHINFO_EXTENSION));
        // 按 年/月 分层；库里存完整相对路径，历史 Ymd 路径照常可读
        $relative = $type . '/' . date('Y/m') . '/' . $uploadId . '.' . $ext;
        // 与 knowledge.upload_prefix 对齐：物理落在 {upload_root}/knowledge/{type}/{Y}/{m}/
        $finalAbs = upload_abs_path('knowledge', $relative);

        $finalDir = dirname($finalAbs);
        if (!is_dir($finalDir) && !mkdir($finalDir, 0755, true) && !is_dir($finalDir)) {
            throw new \RuntimeException('目标目录创建失败');
        }
        if (!path_within_upload_root($finalDir)) {
            throw new \RuntimeException('目标路径越界');
        }

        $attachmentId = Db::name('kb_attachment')->insertGetId([
            'article_id'    => 0,
            'type'          => $type,
            'original_name' => mb_substr($task['filename'], 0, 255),
            'path'          => $relative,
            'thumb_path'    => null,
            'mime'          => null,
            'size'          => (int) $task['size'],
            'status'        => 'pending',
            'created_by'    => (int) $task['user_id'],
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        Db::name('kb_upload')->where('upload_id', $uploadId)->update([
            'status'        => 'completed',
            'attachment_id' => $attachmentId,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        // 入队：流式合并 + 转码 + 封面（禁止同步执行）
        \think\facade\Queue::push(\app\job\KbProcessJob::class, [
            'upload_id'     => $uploadId,
            'attachment_id' => $attachmentId,
        ]);

        return [
            'attachment_id' => $attachmentId,
            'status'        => 'pending',
            'type'          => $type,
        ];
    }

    /**
     * 分片暂存目录：upload_dir('knowledge', '_tmp')/{uploadId}
     */
    public function chunkDir(string $uploadId): string
    {
        $base = temp_dir('knowledge');
        if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
            throw new \RuntimeException('暂存目录创建失败');
        }

        $dir = $base . DIRECTORY_SEPARATOR . preg_replace('/[^\w\-]/', '', $uploadId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('分片目录创建失败');
        }

        // realpath 要求路径已存在，故先建目录再校验
        if (!path_within_upload_root($dir)) {
            throw new \RuntimeException('分片目录越界');
        }

        return $dir;
    }

    private function task(string $uploadId): array
    {
        $task = Db::name('kb_upload')->where('upload_id', $uploadId)->find();
        if (!$task) {
            throw new \RuntimeException('上传任务不存在或已过期');
        }
        return $task;
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, ['image', 'video', 'file'], true)) {
            throw new \RuntimeException('上传类型不合法');
        }
    }

    private function extAllowed(string $ext, string $type): bool
    {
        $key  = ['image' => 'image_ext', 'video' => 'video_ext', 'file' => 'file_ext'][$type] ?? 'image_ext';
        $list = (array) config('myconfig.knowledge.' . $key, []);
        return in_array($ext, $list, true);
    }

    /**
     * finfo MIME 嗅探：真实类型必须与扩展名白名单对应的类型族一致
     */
    private function assertMime(string $realPath, string $type): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($realPath);

        $rules = [
            'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'video' => ['video/mp4', 'video/quicktime', 'video/x-matroska', 'video/x-msvideo', 'video/webm'],
            'file'  => ['application/pdf', 'application/zip', 'text/plain',
                        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        ];

        $allow = $rules[$type] ?? [];
        if ($mime === '' || !in_array($mime, $allow, true)) {
            throw new \RuntimeException('文件真实类型不合法（' . $mime . '），拒绝上传');
        }
    }
}
