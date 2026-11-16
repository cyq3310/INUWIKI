<?php

use think\facade\Cache;
use think\facade\Db;
use think\migration\Migrator;

/**
 * WIKI 后台操作日志表：与 EIP 一样按月 RANGE 分区，由 oplog:maintain 滚动补分区与清理。
 *
 * WIKI 默认连接为独立的 kb 业务库，prefix 默认为空，因此实际表名是 operation_log；
 * 绝不写入只读的 EIP 库。字段在 EIP 基础上补齐文件日志已有的上下文列，便于查询页显示。
 */
class CreateOperationLog extends Migrator
{
    /** 除当月外额外预建的未来月数 */
    private const FUTURE_MONTHS = 6;

    public function up(): void
    {
        $table = $this->tableName();

        if (!$this->hasTable($table)) {
            Db::execute(
                "CREATE TABLE `{$table}` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'EIP 用户 ID',
                    `username` VARCHAR(50) NULL DEFAULT NULL COMMENT '登录名快照',
                    `action` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '模块.动作',
                    `detail` TEXT NULL COMMENT '操作描述，不含敏感请求体',
                    `ip` VARCHAR(45) NULL DEFAULT NULL COMMENT '来源 IP',
                    `method` VARCHAR(12) NULL DEFAULT NULL COMMENT 'HTTP 方法',
                    `path` VARCHAR(200) NULL DEFAULT NULL COMMENT '请求路径',
                    `result` VARCHAR(16) NOT NULL DEFAULT 'success' COMMENT 'success / fail',
                    `object_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作对象 ID',
                    `space_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当前空间 ID',
                    `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '接口耗时毫秒',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                    PRIMARY KEY (`id`, `created_at`),
                    INDEX `idx_oplog_user_created` (`user_id`, `created_at`),
                    INDEX `idx_oplog_created` (`created_at`),
                    INDEX `idx_oplog_action` (`action`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台操作日志表'"
            );
        }

        if (!$this->isPartitioned($table)) {
            $this->ensurePartitionKey($table);
            $definitions = [];
            foreach ($this->monthsToCreate() as $month) {
                $key      = str_replace('-', '', $month);
                $boundary = (new DateTimeImmutable($month . '-01'))
                    ->modify('first day of next month')
                    ->format('Y-m-d');
                $definitions[] = "PARTITION `p{$key}` VALUES LESS THAN (TO_DAYS('{$boundary}'))";
            }
            $definitions[] = 'PARTITION `pmax` VALUES LESS THAN MAXVALUE';

            Db::execute(
                "ALTER TABLE `{$table}` PARTITION BY RANGE (TO_DAYS(`created_at`)) ("
                . implode(', ', $definitions) . ')'
            );
        }

        Cache::clear();
    }

    public function down(): void
    {
        $table = $this->tableName();
        if ($this->hasTable($table)) {
            if ($this->isPartitioned($table)) {
                Db::execute("ALTER TABLE `{$table}` REMOVE PARTITIONING");
            }
            Db::execute("DROP TABLE `{$table}`");
        }
        Cache::clear();
    }

    /** WIKI 日志表使用默认 kb 连接，与 EIP 的 mysql 连接不同 */
    private function tableName(): string
    {
        return (string) config('database.connections.kb.prefix', '') . 'operation_log';
    }

    private function isPartitioned(string $table): bool
    {
        $rows = Db::query(
            "SELECT COUNT(DISTINCT PARTITION_NAME) AS c
               FROM information_schema.PARTITIONS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL",
            [$table]
        );
        return (int) ($rows[0]['c'] ?? 0) > 1;
    }

    /** 兼容可能的旧表：分区字段必须出现在所有唯一键中，且 created_at 不能为 NULL */
    private function ensurePartitionKey(string $table): void
    {
        Db::execute("UPDATE `{$table}` SET `created_at` = NOW() WHERE `created_at` IS NULL");

        $primary = Db::query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        $columns = array_map(static fn (array $row): string => (string) $row['Column_name'], $primary);
        if (!in_array('created_at', $columns, true)) {
            Db::execute(
                "ALTER TABLE `{$table}`
                    MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                    DROP PRIMARY KEY,
                    ADD PRIMARY KEY (`id`, `created_at`)"
            );
        } else {
            Db::execute("ALTER TABLE `{$table}` MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'");
        }

        if (!$this->hasIndex($table, 'idx_oplog_created')) {
            Db::execute("ALTER TABLE `{$table}` ADD INDEX `idx_oplog_created` (`created_at`)");
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $rows = Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
        return $rows !== [];
    }

    /** 需创建的月份：从最早日志所在月（无数据则当月）到未来第 N 个月 */
    private function monthsToCreate(): array
    {
        $first = Db::name('operation_log')->min('created_at');
        $start = new DateTimeImmutable($first ? date('Y-m-01', strtotime((string) $first)) : date('Y-m-01'));
        $end   = new DateTimeImmutable(date('Y-m-01', strtotime('+' . self::FUTURE_MONTHS . ' months')));

        $months = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('first day of next month')) {
            $months[] = $cursor->format('Y-m');
        }
        return $months;
    }
}
