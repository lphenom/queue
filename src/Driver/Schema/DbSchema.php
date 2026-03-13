<?php

declare(strict_types=1);

namespace LPhenom\Queue\Driver\Schema;

/**
 * SQL schema helper for the queue jobs table.
 *
 * Provides DDL statements for creating and dropping the jobs table.
 * Used during application bootstrap or migrations.
 *
 * KPHP-compatible: pure string operations, no reflection.
 *
 * @lphenom-build shared,kphp
 */
final class DbSchema
{
    /**
     * Returns CREATE TABLE DDL for the jobs table.
     *
     * Columns:
     *   id           VARCHAR(36)  — UUID v4 job identifier
     *   name         VARCHAR(255) — job type name
     *   payload_json TEXT         — serialized job payload
     *   attempts     INT          — number of attempts made so far
     *   available_at INT          — Unix timestamp: when the job becomes available
     *   reserved_at  INT          — Unix timestamp: when the job was reserved (NULL = not reserved)
     */
    public static function createTable(string $table = 'jobs'): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . $table . '` ('
            . ' `id`           VARCHAR(36)  NOT NULL,'
            . ' `name`         VARCHAR(255) NOT NULL,'
            . ' `payload_json` TEXT         NOT NULL,'
            . ' `attempts`     INT          NOT NULL DEFAULT 0,'
            . ' `available_at` INT          NOT NULL,'
            . ' `reserved_at`  INT          DEFAULT NULL,'
            . ' PRIMARY KEY (`id`),'
            . ' INDEX `idx_queue_available` (`available_at`, `reserved_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /**
     * Returns DROP TABLE DDL for the jobs table.
     */
    public static function dropTable(string $table = 'jobs'): string
    {
        return 'DROP TABLE IF EXISTS `' . $table . '`';
    }
}
