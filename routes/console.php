<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('dms:connector {source} {--school=DEMO-SCHOOL} {--campus=Main campus}', function () {
    $source = $this->argument('source');
    if (! in_array($source, config('dms.systems'))) {
        $this->error('Choose one of the four configured source systems.');

        return 1;
    }
    $token = Str::random(64);
    DB::table('integration_accounts')->insert(['name' => $source.' connector', 'source' => $source, 'school_id' => $this->option('school'), 'campus' => $this->option('campus'), 'token_hash' => hash('sha256', $token), 'active' => true, 'created_at' => now()]);
    $this->info('Save this token securely; it is shown once:');
    $this->line($token);
})->purpose('Create a source-scoped integration token');

Artisan::command('dms:scan', function () {
    if (! config('dms.scanner')) {
        $this->error('Configure DMS_CLAMSCAN with the trusted clamscan executable path.');
        return 1;
    }
    $count = 0;
    DB::table('document_versions')->whereIn('scan', ['Pending', 'Failed', 'Not required'])->orderBy('id')->chunkById(50, function ($versions) use (&$count) {
        foreach ($versions as $v) {
            $path = \Illuminate\Support\Facades\Storage::disk('local')->path($v->storage_key);
            $scan = is_file($path) && hash_equals($v->checksum, hash_file('sha256', $path))
                ? app(\App\Services\FileScanner::class)->scan($path) : 'Failed';
            DB::transaction(function () use ($v, $scan, &$count) {
                $d = DB::table('documents')->where('id', $v->document_id)->lockForUpdate()->first();
                if (! $d) return;
                $updated = DB::table('document_versions')->where('id', $v->id)->where('scan', $v->scan)
                    ->update(['scan' => $scan, 'status' => $scan === 'Clean' ? 'Available' : 'Awaiting scan']);
                if (! $updated) return;
                DB::table('documents')->where('id', $d->id)->increment('revision');
                $d->revision++;
                $dms = app(\App\Services\DocumentService::class);
                $actor = (object) ['name' => 'Security scanner', 'school_id' => $d->school_id, 'campus' => $d->campus];
                $dms->log($actor, 'Security scan completed', $d->id, $scan);
                $dms->event($d, 'document.scan_completed', $v->id);
                $count++;
            });
        }
    });
    $this->info('Processed '.$count.' versions. Only clean files are available.');
})->purpose('Retry pending or failed scans without bypassing security');
