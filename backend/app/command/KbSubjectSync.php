<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 角色 / 人员目录同步：EIP 主库 → WIKI 本地快照 kb_subject
 *
 * 这是请求链路之外唯一访问 EIP 主库的地方，低频、只读、只取展示字段。
 * 每次全量读取后 upsert；本次快照中不存在的记录标记为停用并保留行，
 * 让历史授权仍能解析出真实名称。
 *
 * 用法：
 *   php think kb:subject-sync            # 执行同步
 *   php think kb:subject-sync --dry-run  # 只读取统计，不写入
 *
 * 建议 crontab 每 10 分钟执行一次（首次部署需先手动同步一次），
 * 具体调度见 deploy/README.md 的定时任务段。
 */
class KbSubjectSync extends Command
{
    /** 单次 upsert 行数，避免单条 SQL 过大 */
    private const BATCH = 500;

    protected function configure(): void
    {
        $this->setName('kb:subject-sync')
            ->addOption('dry-run', null, null, '只统计不写入')
            ->setDescription('把 EIP 角色 / 人员目录同步到 WIKI 本地快照表');
    }

    protected function execute(Input $input, Output $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $now    = date('Y-m-d H:i:s');

        try {
            // 只取展示所需字段，绝不读取 password_hash 等敏感列
            $roles = Db::connect('eip')->name('role')
                ->field('id, name, code, status')->order('id', 'asc')->select()->toArray();
            $users = Db::connect('eip')->name('user')
                ->field('id, username, nickname, status, deleted_at')->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            // EIP 主库不可用时不影响 WIKI：本地快照仍是上一次成功同步的数据
            $output->writeln('同步失败（EIP 不可达，本地快照保持不变）：' . $e->getMessage());
            return 1;
        }

        $rows = [];
        foreach ($roles as $row) {
            $id   = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $code = $this->clip((string) ($row['code'] ?? ''));
            $name = $this->clip(trim((string) ($row['name'] ?? '')));
            $rows[] = [
                'subject_type' => 'role',
                'subject_id'   => $id,
                'name'         => $name !== '' ? $name : $code,
                'code'         => $code,
                'status'       => (int) ($row['status'] ?? 1),
                'deleted_at'   => null,
                'synced_at'    => $now,
            ];
        }
        foreach ($users as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $code = $this->clip((string) ($row['username'] ?? ''));
            $name = $this->clip(trim((string) ($row['nickname'] ?? '')));
            $rows[] = [
                'subject_type' => 'user',
                'subject_id'   => $id,
                'name'         => $name !== '' ? $name : $code,
                'code'         => $code,
                'status'       => (int) ($row['status'] ?? 1),
                'deleted_at'   => $this->normalizeDeletedAt($row['deleted_at'] ?? null),
                'synced_at'    => $now,
            ];
        }

        $output->writeln(sprintf(
            'EIP 读取：角色 %d 条，人员 %d 条（dry-run=%s）',
            count($roles),
            count($users),
            $dryRun ? 'yes' : 'no'
        ));

        if ($dryRun) {
            $output->writeln('未写入本地快照');
            return 0;
        }

        Db::startTrans();
        try {
            foreach (array_chunk($rows, self::BATCH) as $chunk) {
                Db::name('kb_subject')
                    ->duplicate(['name', 'code', 'status', 'deleted_at', 'synced_at'])
                    ->insertAll($chunk);
            }
            // 本次未同步到 = EIP 中已不存在：标记停用并保留行，供历史授权解析名称。
            // 只处理尚未失效的行，避免每次同步反复 UPDATE 历史记录；
            // 对象重新出现在 EIP 时，上面的 upsert 会把 deleted_at 置回 null 使其复活。
            $stale = Db::name('kb_subject')
                ->where('synced_at', '<', $now)
                ->whereNull('deleted_at')
                ->update(['status' => 0, 'deleted_at' => $now, 'synced_at' => $now]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $output->writeln('同步失败（本地快照已回滚）：' . $e->getMessage());
            return 1;
        }

        $output->writeln(sprintf(
            '同步完成：upsert %d 条，标记失效 %d 条',
            count($rows),
            (int) $stale
        ));
        return 0;
    }

    private function clip(string $value): string
    {
        return mb_substr($value, 0, 128);
    }

    private function normalizeDeletedAt($value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        $time = strtotime((string) $value);
        return $time > 0 ? date('Y-m-d H:i:s', $time) : null;
    }
}
