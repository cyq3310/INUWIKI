<?php
declare(strict_types=1);

namespace app\job;

use app\service\MediaService;
use think\facade\Db;
use think\queue\Job;

/**
 * 媒体处理任务：流式合并分片 → 探测 → 转码 → 封面 → 更新状态
 *
 * 必须异步：200 片合成 1GB 是长时间 IO，同步执行会阻塞请求。
 * 合并一律流式读写，禁止 file_get_contents（否则内存爆炸）。
 */
class KbProcessJob
{
    public function fire(Job $job, array $data): void
    {
        $uploadId     = (string) ($data['upload_id'] ?? '');
        $attachmentId = (int) ($data['attachment_id'] ?? 0);

        try {
            $this->handle($uploadId, $attachmentId);
            $job->delete();
        } catch (\Throwable $e) {
            Db::name('kb_attachment')->where('id', $attachmentId)->update([
                'status'      => 'failed',
                'fail_reason' => mb_substr($e->getMessage(), 0, 500),
            ]);
            // 失败不重投，避免死循环；由后台手动重试
            $job->delete();
        }
    }

    private function handle(string $uploadId, int $attachmentId): void
    {
        $attachment = Db::name('kb_attachment')->where('id', $attachmentId)->find();
        if (!$attachment) {
            throw new \RuntimeException('附件记录不存在');
        }

        $relative = (string) $attachment['path'];
        $finalAbs = upload_abs_path('knowledge', $relative);
        if (!path_within_upload_root(dirname($finalAbs))) {
            throw new \RuntimeException('目标路径越界');
        }

        // 1) 流式合并分片
        Db::name('kb_attachment')->where('id', $attachmentId)->update(['status' => 'merging']);
        $this->mergeChunks($uploadId, $finalAbs, (int) $attachment['size']);

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($finalAbs) ?: null;
        Db::name('kb_attachment')->where('id', $attachmentId)->update(['mime' => $mime]);

        // 2) 图片 / 文件：直接置为就绪
        if ($attachment['type'] !== 'video') {
            Db::name('kb_attachment')->where('id', $attachmentId)->update([
                'status' => 'ready',
                'size'   => (int) filesize($finalAbs),
            ]);
            $this->cleanChunks($uploadId);
            return;
        }

        // 3) 视频：探测 → 转码 → 封面
        Db::name('kb_attachment')->where('id', $attachmentId)->update(['status' => 'transcoding']);

        $media   = new MediaService();
        $probe   = $media->probe($finalAbs);
        $srcAbs  = $finalAbs;
        $dstAbs  = preg_replace('/\.[^.]+$/', '', $finalAbs) . '_720p.mp4';
        $coverAbs = preg_replace('/\.[^.]+$/', '', $finalAbs) . '_cover.jpg';

        [$ok, $err] = $media->transcode($srcAbs, $dstAbs);
        if (!$ok) {
            throw new \RuntimeException('转码失败：' . $err);
        }

        // 转码产物替换原文件（原片移入回收站）
        move_to_trash('knowledge', $relative, (string) $attachmentId);
        @rename($dstAbs, $finalAbs);

        $coverRelative = null;
        if ($media->extractCover($finalAbs, $coverAbs)) {
            // 与 path 同基准（相对 knowledge 前缀），前端 resolveUploadUrl 才能取到封面
            $coverRelative = preg_replace('/\.[^.]+$/', '', $relative) . '_cover.jpg';
        }

        $probe2 = $media->probe($finalAbs);

        Db::name('kb_attachment')->where('id', $attachmentId)->update([
            'status'      => 'ready',
            'path'        => $relative,
            'thumb_path'  => $coverRelative,
            'size'        => (int) filesize($finalAbs),
            'duration'    => $probe2['duration'] ?: $probe['duration'],
            'width'       => $probe2['width'],
            'height'      => $probe2['height'],
            'fail_reason' => null,
        ]);

        $this->cleanChunks($uploadId);
    }

    /**
     * 流式合并：fopen/fread/fwrite 循环，峰值内存仅数 MB
     */
    private function mergeChunks(string $uploadId, string $finalAbs, int $expectSize): void
    {
        $dir = (new \app\service\UploadService())->chunkDir($uploadId);
        if (!is_dir($dir)) {
            throw new \RuntimeException('分片目录不存在');
        }

        $parts = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match('/^(\d+)\.part$/', $name, $m)) {
                $parts[(int) $m[1]] = $dir . DIRECTORY_SEPARATOR . $name;
            }
        }
        if ($parts === []) {
            throw new \RuntimeException('没有可合并的分片');
        }
        ksort($parts);

        $out = fopen($finalAbs, 'wb');
        if ($out === false) {
            throw new \RuntimeException('无法写入目标文件');
        }

        $written = 0;
        foreach ($parts as $part) {
            $in = fopen($part, 'rb');
            if ($in === false) {
                fclose($out);
                throw new \RuntimeException('分片读取失败');
            }
            while (!feof($in)) {
                $buf = fread($in, 8192);
                if ($buf === false) {
                    break;
                }
                $written += fwrite($out, $buf);
            }
            fclose($in);
        }
        fclose($out);

        if ($expectSize > 0 && $written !== $expectSize) {
            @unlink($finalAbs);
            throw new \RuntimeException(sprintf('合并后大小不一致（期望 %d，实际 %d）', $expectSize, $written));
        }
    }

    private function cleanChunks(string $uploadId): void
    {
        $dir = (new \app\service\UploadService())->chunkDir($uploadId);
        if (is_dir($dir)) {
            remove_dir($dir);
        }
    }
}
