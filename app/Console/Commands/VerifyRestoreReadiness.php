<?php

namespace App\Console\Commands;

use App\Services\BackupArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class VerifyRestoreReadiness extends Command
{
    protected $signature = 'dms:restore-readiness {archive? : Encrypted backup to decrypt and checksum without restoring live data}';
    protected $description = 'Verify backup recoverability and report the quarterly restore drill due date';

    public function handle(BackupArchive $archive): int
    {
        if ($path = $this->argument('archive')) {
            try { $manifest = $archive->verify($path); }
            catch (\Throwable) { $this->error('Archive decryption or integrity verification failed.'); return self::FAILURE; }
            $archive->record(['backup_verified_at' => now()->utc()->toIso8601String(), 'verified_backup_created_at' => $manifest['created_at']]);
            $this->info('Archive decrypts and all '.count($manifest['checksums']).' snapshot and private-file checksums match.');
            $this->warn('This verifies archive integrity. A full restore drill still requires restoring MySQL and files together in an isolated environment.');
        }
        $disk = Storage::disk('local');
        $status = $disk->exists('operations/status.json') ? json_decode($disk->get('operations/status.json'), true) : [];
        $last = $status['restore_drill_completed_at'] ?? null;
        if (! $last || Carbon::parse($last)->lt(now()->subDays((int) config('operations.restore_drill_days')))) {
            $this->warn('Quarterly restore drill is due; no recent completed isolated restore is recorded.');
            return $path ? self::SUCCESS : self::FAILURE;
        }
        $this->info('Quarterly restore drill recorded at '.$last.'.');
        return self::SUCCESS;
    }
}
