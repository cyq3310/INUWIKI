<?php
declare(strict_types=1);

namespace app\service;

/**
 * 媒体服务：ffprobe 探测、FFmpeg 转码、封面抽取、X-Accel-Redirect 路径拼装
 *
 * 关键：视频字节绝不经过 PHP。播放时 PHP 只做鉴权，返回 X-Accel-Redirect，
 * 由 Nginx internal location 用 sendfile 直发，原生支持 Range(206) 拖拽。
 */
class MediaService
{
    /**
     * 拼接内部重定向路径（与 nginx internal location 严格对齐）
     *
     * @param string $relative DB 中的相对路径，如 video/20260926/abc.mp4
     */
    public function internalRedirect(string $relative): string
    {
        $prefix = (string) (config('myconfig.media.internal_prefix') ?: '/internal-media');
        return rtrim($prefix, '/') . '/' . $this->rootRelative($relative);
    }

    /**
     * 绝对磁盘路径（含越界校验）
     */
    public function absolutePath(string $relative): ?string
    {
        $abs = upload_root() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $this->rootRelative($relative));
        return path_within_upload_root($abs) ? $abs : null;
    }

    /**
     * 把 DB 相对路径换算成相对 upload_root 的路径。
     * 新文件落在 {groupRel}/ 下；兼容历史直接落在 upload_root 下的老文件。
     */
    private function rootRelative(string $relative): string
    {
        $rel      = ltrim(str_replace('\\', '/', $relative), '/');
        $groupRel = upload_group_rel('knowledge');
        $current  = $groupRel !== '' ? $groupRel . '/' . $rel : $rel;

        if (is_file(upload_root() . '/' . $current)) {
            return $current;
        }
        if (is_file(upload_root() . '/' . $rel)) {
            return $rel;
        }
        return $current;
    }

    /**
     * ffprobe 探测时长与分辨率
     */
    public function probe(string $absPath): array
    {
        $bin = (string) config('myconfig.ffmpeg.ffprobe') ?: 'ffprobe';
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>&1',
            escapeshellarg($bin),
            escapeshellarg($absPath)
        );

        $json = shell_exec($cmd);
        $data = json_decode((string) $json, true);
        $duration = isset($data['format']['duration']) ? (int) round((float) $data['format']['duration']) : 0;
        $width    = 0;
        $height   = 0;

        foreach (($data['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $width  = (int) ($stream['width'] ?? 0);
                $height = (int) ($stream['height'] ?? 0);
                break;
            }
        }

        return ['duration' => $duration, 'width' => $width, 'height' => $height];
    }

    /**
     * 转码为 H.264/AAC + faststart，并按目标高度缩放
     *
     * @return array [bool $ok, string $message]
     */
    public function transcode(string $srcAbs, string $dstAbs, int $targetHeight = 720): array
    {
        $bin    = (string) config('myconfig.ffmpeg.bin') ?: 'ffmpeg';
        $preset = (string) config('myconfig.ffmpeg.preset') ?: 'veryfast';
        if ($targetHeight <= 0) {
            $targetHeight = (int) config('myconfig.ffmpeg.height') ?: 720;
        }

        $scale = sprintf('scale=-2:%d', $targetHeight);
        $cmd   = sprintf(
            '%s -y -i %s -c:v libx264 -preset %s -vf %s -c:a aac -movflags +faststart %s 2>&1',
            escapeshellarg($bin),
            escapeshellarg($srcAbs),
            escapeshellarg($preset),
            escapeshellarg($scale),
            escapeshellarg($dstAbs)
        );

        $output = shell_exec($cmd);
        if (!is_file($dstAbs) || filesize($dstAbs) === 0) {
            return [false, mb_substr((string) $output, 0, 500)];
        }

        return [true, ''];
    }

    /**
     * 抽取封面帧
     */
    public function extractCover(string $srcAbs, string $dstAbs, int $second = 1): bool
    {
        $bin = (string) config('myconfig.ffmpeg.bin') ?: 'ffmpeg';
        $cmd = sprintf(
            '%s -y -ss %d -i %s -frames:v 1 -q:v 2 %s 2>&1',
            escapeshellarg($bin),
            $second,
            escapeshellarg($srcAbs),
            escapeshellarg($dstAbs)
        );

        shell_exec($cmd);
        return is_file($dstAbs) && filesize($dstAbs) > 0;
    }
}
