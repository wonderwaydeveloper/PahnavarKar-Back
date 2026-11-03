<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RunSecureBackup extends Command
{
    protected $signature = 'backup:run-secure';

    protected $description = 'Run an encrypted backup to the configured remote S3-compatible disk';

    public function handle(): int
    {
        if (! $this->laravel->environment('production')) {
            $this->error('Secure production backups can only run in the production environment.');

            return self::FAILURE;
        }

        $requiredValues = [
            'backup.backup.password' => config('backup.backup.password'),
            'filesystems.disks.backup-s3.key' => config('filesystems.disks.backup-s3.key'),
            'filesystems.disks.backup-s3.secret' => config('filesystems.disks.backup-s3.secret'),
            'filesystems.disks.backup-s3.region' => config('filesystems.disks.backup-s3.region'),
            'filesystems.disks.backup-s3.bucket' => config('filesystems.disks.backup-s3.bucket'),
        ];

        foreach ($requiredValues as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                $this->error("Required backup configuration [{$name}] is missing.");

                return self::FAILURE;
            }
        }

        if (strlen((string) config('backup.backup.password')) < 32) {
            $this->error('BACKUP_ARCHIVE_PASSWORD must contain at least 32 characters.');

            return self::FAILURE;
        }

        if (config('backup.backup.encryption') !== 'aes256') {
            $this->error('Backup archive encryption must use AES-256.');

            return self::FAILURE;
        }

        if (! in_array('backup-s3', config('backup.backup.destination.disks', []), true)) {
            $this->error('The encrypted backup destination must include the backup-s3 disk.');

            return self::FAILURE;
        }

        if (config('filesystems.disks.backup-s3.visibility') !== 'private') {
            $this->error('The backup-s3 disk must remain private.');

            return self::FAILURE;
        }

        $exitCode = Artisan::call('backup:run');
        $this->output->write(Artisan::output());

        return $exitCode === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }
}
