<?php

namespace App\Jobs;

use App\Services\Access;
use App\Services\Scanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ScanDocumentVersion implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 75;

    public function __construct(public string $versionId) {}

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('scan:'.$this->versionId))->releaseAfter(90)->expireAfter(120)];
    }

    public function handle(Scanner $scanner): void
    {
        $version = DB::table('document_versions')->where('id', $this->versionId)->first();
        if (! $version || ! in_array($version->scan_status, ['Pending', 'Failed'])) {
            return;
        }
        DB::table('document_versions')->where('id', $version->id)->update(['scan_attempted_at' => now()]);
        $path = Storage::disk('local')->path($version->storage_key);
        $intact = is_file($path) && hash_equals($version->checksum, hash_file('sha256', $path));
        $status = $intact ? $scanner->scan($path) : 'Failed';
        // A file must still match the accepted upload after the scanner exits.
        if ($status === 'Clean' && (! is_file($path) || ! hash_equals($version->checksum, hash_file('sha256', $path)))) {
            $status = 'Failed';
        }
        DB::transaction(function () use ($version, $status) {
            $row = DB::table('document_versions')->where('id', $version->id)->lockForUpdate()->first();
            if (! in_array($row->scan_status, ['Pending', 'Failed'])) {
                return;
            }
            DB::table('document_versions')->where('id', $version->id)->update(['scan_status' => $status]);
            $doc = DB::table('documents')->where('id', $version->document_id)->first();
            $system = (object) ['school_id' => $doc->school_id, 'campus' => $doc->campus, 'source' => $doc->source];
            Access::audit($system, 'document.scanned', $doc, ['version_id' => $version->id, 'scan_status' => $status]);
            Access::event($doc, 'document.scan.completed');
        });
        if ($status === 'Failed') {
            throw new \RuntimeException('Scan failed; the version remains blocked. Check ClamAV, signatures and private storage.');
        }
    }
}
