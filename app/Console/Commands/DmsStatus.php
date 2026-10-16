<?php

namespace App\Console\Commands;

use App\Services\Scanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DmsStatus extends Command
{
    protected $signature = 'dms:status';

    protected $description = 'Check the standalone database, private storage, queue and actual ClamAV scan';

    public function handle(Scanner $scanner): int
    {
        $ok = true;
        try {
            DB::connection()->getPdo();
            $this->line('Database: connected ('.config('database.default').')');
            $this->line('Active administrators: '.DB::table('users')->whereIn('role', ['super_admin', 'admin'])->where('active', true)->count());
            $this->line('Blocked versions: '.DB::table('document_versions')->where('scan_status', '!=', 'Clean')->count());
            $this->line('Queued scans: '.DB::table('jobs')->where('queue', 'scans')->count());
            $this->line('Failed jobs: '.DB::table('failed_jobs')->count());
        } catch (\Throwable) {
            $this->error('Database: unavailable or migrations incomplete.');
            $ok = false;
        }
        $path = tempnam(storage_path('app/private'), 'scan-check-');
        if (! $path) {
            $this->error('Private storage: not writable.');

            return 1;
        }
        try {
            file_put_contents($path, 'DMS scanner readiness check');
            $status = $scanner->scan($path);
            $this->line('ClamAV benign-file check: '.$status);
            if ($status !== 'Clean') {
                $ok = false;
            }
        } finally {
            @unlink($path);
        }
        $this->line('Scan workers must run on the database connection and scans queue.');

        return $ok ? 0 : 1;
    }
}
