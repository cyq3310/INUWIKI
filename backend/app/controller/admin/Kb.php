<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

/**
 * 后台 · 知识库运行统计（只读聚合值）。
 *
 * used_bytes / total_bytes 取 myconfig.upload.root 所在文件系统的容量，
 * 不返回绝对路径；队列统计只返回数量，不暴露附件名或失败原因。
 */
class Kb extends BaseController
{
    public function stats()
    {
        [$totalBytes, $usedBytes] = $this->diskUsage();

        $transcoding = 0;
        $failed = 0;
        try {
            $transcoding = (int) Db::name('kb_attachment')
                ->whereIn('status', ['pending', 'merging', 'transcoding'])
                ->count();
            $failed = (int) Db::name('kb_attachment')
                ->where('status', 'failed')
                ->count();
        } catch (\Throwable $e) {
            // 未迁移或表不可用时只降级队列数字，磁盘容量仍可展示
            Log::warning('知识库队列统计查询失败: ' . $e->getMessage());
        }

        return resp_ok([
            'used_bytes'  => $usedBytes,
            'total_bytes' => $totalBytes,
            'transcoding' => $transcoding,
            'failed'      => $failed,
        ]);
    }

    /** 返回 [total, used]；目录不存在时向上找到已存在祖先，避免磁盘函数失败 */
    private function diskUsage(): array
    {
        $path = $this->existingPath(upload_root());
        if ($path === '') {
            return [0, 0];
        }

        try {
            $total = disk_total_space($path);
            $free  = disk_free_space($path);
            if ($total === false || $free === false) {
                return [0, 0];
            }
            $total = max(0, (int) $total);
            $free  = max(0, (int) $free);
            return [$total, max(0, $total - $free)];
        } catch (\Throwable $e) {
            Log::warning('知识库磁盘容量读取失败: ' . $e->getMessage());
            return [0, 0];
        }
    }

    private function existingPath(string $path): string
    {
        $path = rtrim($path, '/\\');
        while ($path !== '' && !is_dir($path)) {
            $parent = dirname($path);
            if ($parent === $path || $parent === '.') {
                return '';
            }
            $path = $parent;
        }
        $real = realpath($path);
        return $real !== false ? $real : $path;
    }
}
