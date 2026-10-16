<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class UpdateSignatures extends Command
{
    protected $signature = 'dms:signatures-update';

    protected $description = 'Update official local ClamAV signatures with FreshClam';

    public function handle(): int
    {
        $configured = config('dms.freshclam_binary');
        $binary = is_file($configured) ? realpath($configured) : (new ExecutableFinder)->find($configured);
        if (! $binary) {
            $this->error('FreshClam is unavailable. Configure FRESHCLAM_BINARY.');

            return 1;
        }
        $args = [$binary, '--quiet'];
        if (config('dms.clamav_database')) {
            $args[] = '--datadir='.(realpath(config('dms.clamav_database')) ?: config('dms.clamav_database'));
        }
        if (config('dms.freshclam_config')) {
            $args[] = '--config-file='.(realpath(config('dms.freshclam_config')) ?: config('dms.freshclam_config'));
        }
        $process = new Process($args);
        $process->setTimeout(300);
        try {
            $process->run();
        } catch (\Throwable) {
            $this->error('Signature update timed out or could not start.');

            return 1;
        }
        if (! $process->isSuccessful()) {
            $this->error('Signature update failed. Check FreshClam configuration, permissions and connectivity.');

            return 1;
        }
        $this->info('ClamAV signatures are current.');

        return 0;
    }
}
