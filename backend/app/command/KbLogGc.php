<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/**
 * 日志定时清理：按保留天数删除旧日志，并按总大小上限删除最旧日志。
 *
 * 用法：
 *   php think kb:log-gc                       # 按 myconfig.log_gc 执行清理
 *   php think kb:log-gc --dry-run            # 只统计不删除
 *   php think kb:log-gc --days=90 --max-mb=256
 *
 * 建议 crontab 每日执行，输出直接丢弃，避免调度日志自身再次膨胀：
 *   0 4 * * * cd /var/www/inuwiki/backend && /usr/bin/php think kb:log-gc >> /dev/null 2>&1
 */
class KbLogGc extends Command
{
    protected function configure(): void
    {
        $this->setName('kb:log-gc')
            ->addOption('dry-run', null, null, '只统计不删除')
            ->addOption('days', null, Option::VALUE_REQUIRED, '覆盖保留天数')
            ->addOption('max-mb', null, Option::VALUE_REQUIRED, '覆盖日志总大小上限（MB，0 表示不限制）')
            ->setDescription('按保留期与总大小清理运行日志、操作日志和调度日志');
    }

    protected function execute(Input $input, Output $output): int
    {
        $dryRun    = (bool) $input->getOption('dry-run');
        $days      = (int) ($input->getOption('days') ?: (config('myconfig.log_gc.retention_days') ?: 180));
        $maxMb     = (float) ($input->getOption('max-mb') ?: (config('myconfig.log_gc.max_total_mb') ?: 512));
        $days      = max(1, $days);
        $maxMb     = max(0.0, $maxMb);
        $cutoff    = time() - $days * 86400;
        $sizeLimit = $maxMb > 0 ? (int) round($maxMb * 1024 * 1024) : 0;

        $files = $this->collectFiles();
        $initialBytes = $this->totalBytes($files);

        $output->writeln(sprintf(
            '开始清理日志（dry-run=%s，保留 %d 天，总大小上限 %s MB，扫描 %d 个文件，初始 %.2f MB）',
            $dryRun ? 'yes' : 'no',
            $days,
            $maxMb > 0 ? rtrim(rtrim(number_format($maxMb, 2, '.', ''), '0'), '.') : '不限',
            count($files),
            $initialBytes / 1024 / 1024
        ));

        // 1) 按时间清理：跳过当天日志，避免删除仍在写入的文件
        $removedByAge = [];
        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime === false || $mtime >= $cutoff || $this->isCurrentDayFile($file)) {
                continue;
            }
            if ($dryRun || @unlink($file)) {
                $removedByAge[] = $file;
            }
        }
        $output->writeln(sprintf(
            '  超期清理：%s %d 个',
            $dryRun ? '将删除' : '已删除',
            count($removedByAge)
        ));

        // 2) 按总大小清理：仍超限时从最旧文件开始删除，同样跳过当天日志
        $remaining = array_values(array_diff($files, $removedByAge));
        $remainingBytes = $this->totalBytes($remaining);
        usort($remaining, static function (string $a, string $b): int {
            return (filemtime($a) ?: 0) <=> (filemtime($b) ?: 0);
        });

        $removedBySize = [];
        foreach ($remaining as $file) {
            if ($sizeLimit <= 0 || $remainingBytes <= $sizeLimit) {
                break;
            }
            if ($this->isCurrentDayFile($file)) {
                continue;
            }
            $size = (int) (filesize($file) ?: 0);
            if ($dryRun || @unlink($file)) {
                $removedBySize[] = $file;
                $remainingBytes = max(0, $remainingBytes - $size);
            }
        }
        $output->writeln(sprintf(
            '  容量清理：%s %d 个，剩余约 %.2f MB',
            $dryRun ? '将删除' : '已删除',
            count($removedBySize),
            $remainingBytes / 1024 / 1024
        ));

        if ($sizeLimit > 0 && $remainingBytes > $sizeLimit) {
            $output->writeln('  注意：当天日志仍在写入，容量上限暂未完全达标；下次调度会继续清理。');
        }
        $output->writeln('日志清理完成');

        return 0;
    }

    /** 收集 runtime/log、runtime/oplog、runtime 根下调度输出日志 */
    private function collectFiles(): array
    {
        $root  = rtrim(root_path(), '/\\');
        $dirs     = [];
        $scan     = config('myconfig.log_gc.scan_dirs');
        $scanDirs = is_array($scan) ? $scan : ['runtime/log', 'runtime/oplog'];
        foreach ($scanDirs as $relative) {
            $dirs[] = $this->absolutePath((string) $relative, $root);
        }
        $dirs[] = kb_oplog_dir();
        $dirs   = array_values(array_unique(array_filter($dirs, 'is_dir')));

        $files = [];
        foreach ($dirs as $dir) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $item) {
                    if ($item->isFile() && strtolower($item->getExtension()) === 'log') {
                        $files[] = $item->getPathname();
                    }
                }
            } catch (\Throwable $e) {
                // 单个目录不可读时跳过，不影响其它日志目录
            }
        }

        $runtime = $root . DIRECTORY_SEPARATOR . 'runtime';
        foreach (glob($runtime . DIRECTORY_SEPARATOR . '*.log') ?: [] as $file) {
            if (is_file($file)) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    private function absolutePath(string $path, string $root): string
    {
        $path = trim($path);
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1) {
            return rtrim($path, '/\\');
        }
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR
            . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    /** 当天日志与 YYYYMM/DD.log 当日文件都视为活跃文件 */
    private function isCurrentDayFile(string $file): bool
    {
        $mtime = filemtime($file);
        if ($mtime !== false && $mtime >= strtotime('today')) {
            return true;
        }
        if (preg_match('#[/\\\\](\d{6})[/\\\\](\d{2})\.log$#', $file, $m) === 1) {
            return $m[1] === date('Ym') && $m[2] === date('d');
        }
        return false;
    }

    private function totalBytes(array $files): int
    {
        $total = 0;
        foreach ($files as $file) {
            $total += (int) (filesize($file) ?: 0);
        }
        return $total;
    }
}
