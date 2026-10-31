<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class Scanner
{
    public function scan(string $path): string
    {
        try {
            $realPath = realpath($path) ?: $path;
            if (! is_file($realPath)) {
                return 'Failed';
            }

            // 1. High-performance path: ClamAV Daemon (INSTREAM via TCP socket) -> ~10-20ms
            $daemonResult = $this->scanViaDaemon($realPath);
            if ($daemonResult !== null) {
                return $daemonResult;
            }

            // 2. Fallback CLI path: clamscan
            $configured = config('dms.clamav_binary');
            $binary = is_file($configured) ? realpath($configured) : (new ExecutableFinder)->find($configured);
            if (! $binary) {
                return 'Failed';
            }
            $args = [$binary, '--no-summary', '--alert-exceeds-max=yes', '--alert-encrypted=yes'];
            if (config('dms.clamav_fail_on_old_cvd', false)) {
                $args[] = '--fail-if-cvd-older-than=7';
            }
            if (config('dms.clamav_database')) {
                $args[] = '--database='.(realpath(config('dms.clamav_database')) ?: config('dms.clamav_database'));
            }
            $process = new Process([...$args, '--', $realPath]);
            $process->setTimeout(config('dms.scan_timeout', 60));
            $process->run();

            return match ($process->getExitCode()) {
                0 => 'Clean', 1 => 'Quarantined', default => 'Failed'
            };
        } catch (\Throwable) {
            return 'Failed';
        }
    }

    private function scanViaDaemon(string $path): ?string
    {
        $host = config('dms.clamav_host', '127.0.0.1');
        $port = (int) config('dms.clamav_port', 3310);

        $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if (! $socket) {
            return null;
        }

        stream_set_timeout($socket, 10);
        fwrite($socket, "zINSTREAM\0");

        $handle = @fopen($path, 'rb');
        if (! $handle) {
            fclose($socket);

            return null;
        }

        while (! feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }
        fclose($handle);

        fwrite($socket, pack('N', 0));

        $response = fgets($socket);
        fclose($socket);

        if (! $response) {
            return null;
        }

        $response = trim($response);
        if (str_ends_with($response, 'OK')) {
            return 'Clean';
        }
        if (str_contains($response, 'FOUND')) {
            return 'Quarantined';
        }

        return 'Failed';
    }
}
