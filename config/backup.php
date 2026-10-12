<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database backup (first-party)
    |--------------------------------------------------------------------------
    |
    | Artifacts live on a private disk. Never under public/ or storage/app/public.
    | Documented hot retention: docs/operations/production-runbook.md (14 days).
    |
    */

    'connection' => env('BACKUP_CONNECTION', 'mysql'),

    'disk' => env('BACKUP_DISK', 'backups'),

    'path' => env('BACKUP_PATH', 'database'),

    'files_path' => env('BACKUP_FILES_PATH', 'files'),

    'retain_days' => (int) env('BACKUP_RETAIN_DAYS', 14),

    'prefix' => env('BACKUP_PREFIX', 'hasem-db'),

    'files_prefix' => env('BACKUP_FILES_PREFIX', 'hasem-files'),

    'dump_binary' => env('BACKUP_DUMP_BINARY'),

    'mysql_binary' => env('BACKUP_MYSQL_BINARY'),

    'timeout' => (int) env('BACKUP_TIMEOUT', 600),

    /*
    | Databases that restore must never target. Production name from .env.example
    | is `hasem`. Isolated drill names are not listed here.
    */
    'production_database' => env('BACKUP_PRODUCTION_DATABASE', 'hasem'),

    'forbidden_restore_databases' => array_values(array_filter(array_unique([
        'hasem',
        'mysql',
        'sys',
        'information_schema',
        'performance_schema',
        env('BACKUP_PRODUCTION_DATABASE', 'hasem'),
    ]))),

    'source_database' => env('BACKUP_SOURCE_DATABASE', 'hasem_p0_backup_source'),

    'restore_database' => env('BACKUP_RESTORE_DATABASE', 'hasem_p0_restore_drill'),

];
