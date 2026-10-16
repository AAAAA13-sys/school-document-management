<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class Scanner
{
    public function scan(string $path): string
    {
        try {
            $configured = config('dms.clamav_binary');
            $binary = is_file($configured) ? realpath($configured) : (new ExecutableFinder)->find($configured);
            if (! $binary) {
                return 'Failed';
            }
            $args = [$binary, '--no-summary', '--alert-exceeds-max=yes', '--alert-encrypted=yes', '--fail-if-cvd-older-than=7'];
            if (config('dms.clamav_database')) {
                $args[] = '--database='.(realpath(config('dms.clamav_database')) ?: config('dms.clamav_database'));
            }
            $process = new Process([...$args, '--', realpath($path) ?: $path]);
            $process->setTimeout(config('dms.scan_timeout'));
            $process->run();

            return match ($process->getExitCode()) {
                0 => 'Clean', 1 => 'Quarantined', default => 'Failed'
            };
        } catch (\Throwable) {
            return 'Failed';
        }
    }
}
