<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class FileScanner
{
    public function scan(string $path): string
    {
        if (! config('dms.scanner')) return 'Pending';
        try {
            $result = Process::timeout(45)->run([config('dms.scanner'), '--no-summary', $path]);
            return match ($result->exitCode()) { 0 => 'Clean', 1 => 'Quarantined', default => 'Failed' };
        } catch (\Throwable) {
            return 'Failed';
        }
    }

    public function requireClean(object $version): void
    {
        abort_unless($version->scan === 'Clean', 403, 'This version is unavailable until its security scan passes.');
    }
}
