<?php

namespace Tests\Feature;

use Tests\TestCase;

class BackupConfigurationTest extends TestCase
{
    public function test_backup_configuration_encrypts_application_files_and_keeps_thirty_days(): void
    {
        $this->assertSame(['backup-s3'], config('backup.backup.destination.disks'));
        $this->assertSame('private', config('filesystems.disks.backup-s3.visibility'));
        $this->assertSame('aes256', config('backup.backup.encryption'));
        $this->assertTrue(config('backup.backup.verify_backup'));
        $this->assertContains(base_path('.env'), config('backup.backup.source.files.exclude'));
        $this->assertSame(30, config('backup.cleanup.default_strategy.keep_all_backups_for_days'));
        $this->assertSame(0, config('backup.cleanup.default_strategy.keep_daily_backups_for_days'));
        $this->assertNull(config('backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than'));
    }

    public function test_secure_backup_command_refuses_to_run_outside_production(): void
    {
        $this->artisan('backup:run-secure')
            ->assertExitCode(1);
    }
}
