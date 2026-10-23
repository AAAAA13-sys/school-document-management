<?php

namespace App\Console\Commands;

use App\Services\BackupArchive;
use Illuminate\Console\Command;

class CoordinatedBackup extends Command
{
    protected $signature = 'dms:backup';
    protected $description = 'Create and verify an AES-256 encrypted MySQL and private-version backup';

    public function handle(BackupArchive $archive): int
    {
        if (! config('operations.backup_enabled')) {
            $this->error('Backups are disabled. Configure the private MySQL defaults file, destination and dedicated password before enabling.');
            return self::FAILURE;
        }
        try { $path = $archive->create(); }
        catch (\Throwable) {
            $this->error('Backup failed. Check private client configuration, MySQL availability, encryption support, disk space and version checksums. No completed archive was published.');
            return self::FAILURE;
        }
        $this->info('Encrypted coordinated backup verified: '.basename($path));
        return self::SUCCESS;
    }
}
