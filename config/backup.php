<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

$backupNotificationEmail = env('BACKUP_NOTIFICATION_EMAIL');
$backupName = env('BACKUP_NAME', env('APP_NAME', 'pahnavarkar'));
$backupDisk = env('BACKUP_DISK', 'backup-s3');

return [
    'backup' => [
        'name' => $backupName,
        'source' => [
            'files' => [
                'include' => [
                    base_path(),
                ],
                'exclude' => [
                    base_path('.env'),
                    base_path('vendor'),
                    base_path('node_modules'),
                    base_path('.git'),
                    public_path('storage'),
                    storage_path('framework'),
                    storage_path('logs'),
                    storage_path('app/backup-temp'),
                ],
                'follow_links' => true,
                'ignore_unreadable_directories' => false,
                'relative_path' => base_path(),
            ],
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],
        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',
        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 6,
            'filename_prefix' => '',
            'disks' => [$backupDisk],
            'continue_on_failure' => false,
        ],
        'temporary_directory' => storage_path('app/backup-temp'),
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'aes256',
        'verify_backup' => true,
        'tries' => 2,
        'retry_delay' => 60,
    ],

    'notifications' => [
        'notifications' => $backupNotificationEmail ? [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
        ] : [],
        'notifiable' => Notifiable::class,
        'mail' => [
            'to' => $backupNotificationEmail ?: 'backup-alerts@example.invalid',
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'PahnavarKar')),
            ],
        ],
    ],

    'monitor_backups' => [
        [
            'name' => $backupName,
            'disks' => [$backupDisk],
            'health_checks' => [
                MaximumAgeInDays::class => 2,
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 30,
            'keep_daily_backups_for_days' => 0,
            'keep_weekly_backups_for_weeks' => 0,
            'keep_monthly_backups_for_months' => 0,
            'keep_yearly_backups_for_years' => 0,
            'delete_oldest_backups_when_using_more_megabytes_than' => null,
        ],
        'tries' => 2,
        'retry_delay' => 60,
    ],
];
