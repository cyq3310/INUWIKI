<?php
declare(strict_types=1);

namespace app\command;

use app\service\ArticleService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 知识库垃圾回收：清理超期暂存分片与回收站
 *
 * 用法：
 *   php think kb:gc            # 执行清理
 *   php think kb:gc --dry-run  # 只统计不删除
 *
 * 建议 crontab 每日执行：
 *   0 3 * * * cd /var/www/inuwiki/backend && /usr/bin/php think kb:gc
 */
class KbGc extends Command
{
    protected function configure(): void
    {
        $this->setName('kb:gc')
            ->addOption('dry-run', null, null, '只统计不删除')
            ->setDescription('清理知识库超期暂存分片与回收站');
    }

    protected function execute(Input $input, Output $output): int
    {
        $dryRun        = (bool) $input->getOption('dry-run');
        $retentionDays = (int) (config('myconfig.trash.retention_days') ?: 30);
        $tempHours     = (int) (config('myconfig.trash.temp_retention_hours') ?: 24);

        $output->writeln(sprintf(
            '开始清理（dry-run=%s，回收站保留 %d 天，暂存保留 %d 小时）',
            $dryRun ? 'yes' : 'no',
            $retentionDays,
            $tempHours
        ));

        // 1) 超期暂存分片目录
        $tempStats = $this->gcTemp($tempHours, $dryRun);
        $output->writeln(sprintf(
            '  暂存分片：扫描 %d 个，%s %d 个',
            $tempStats['scanned'],
            $dryRun ? '将清理' : '已清理',
            $tempStats['removed']
        ));

        // 2) 回收站
        $trashStats = gc_trash($retentionDays, $dryRun);
        $output->writeln(sprintf(
            '  回收站：扫描 %d 个，%s %d 个',
            $trashStats['scanned'],
            $dryRun ? '将清理' : '已清理',
            $trashStats['removed']
        ));

        // 3) 历史版本：每篇只保留 knowledge.version_keep 条，顺带清理孤儿版本
        $keep = max(1, (int) (config('myconfig.knowledge.version_keep') ?: 20));
        if ($dryRun) {
            $output->writeln(sprintf('  历史版本：跳过（dry-run，实际执行时每篇保留 %d 条）', $keep));
        } else {
            $removed = (new ArticleService())->pruneVersions();
            $output->writeln(sprintf('  历史版本：已清理 %d 条（每篇保留 %d 条）', $removed, $keep));
        }

        $output->writeln('清理完成');

        return 0;
    }

    /**
     * 清理 _tmp 下超过保留时长的上传任务目录（按目录 mtime 判断）
     */
    private function gcTemp(int $tempHours, bool $dryRun): array
    {
        $dir = temp_dir('knowledge');
        if (!is_dir($dir)) {
            return ['scanned' => 0, 'removed' => 0];
        }

        $threshold = time() - $tempHours * 3600;
        $scanned   = 0;
        $removed   = 0;

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($full)) {
                continue;
            }
            $scanned++;
            if (filemtime($full) < $threshold) {
                $removed++;
                if (!$dryRun) {
                    remove_dir($full);
                }
            }
        }

        return ['scanned' => $scanned, 'removed' => $dryRun ? 0 : $removed];
    }
}
