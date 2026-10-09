<?php

use App\Services\DocumentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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
    $scanner = config('dms.scanner');
    if (! $scanner) {
        $this->error('Configure DMS_CLAMSCAN with a trusted clamscan executable path.');

        return 1;
    }
    $count = 0;
    foreach (DB::table('document_versions')->whereIn('scan', ['Pending', 'Failed'])->get() as $v) {
        try {
            $p = new Process([$scanner, '--no-summary', Storage::disk('local')->path($v->storage_key)]);
            $p->setTimeout(45)->run();
            $scan = match ($p->getExitCode()) {
                0 => 'Clean',1 => 'Quarantined',default => 'Failed'
            };
        } catch (Throwable) {
            $scan = 'Failed';
        }
        DB::transaction(function () use ($v, $scan) {
            $doc = DB::table('documents')->where('id', $v->document_id)->first();
            $changed = DB::table('document_versions')->where('id', $v->id)->whereIn('scan', ['Pending', 'Failed'])->update(['scan' => $scan, 'status' => $scan === 'Clean' ? 'Available' : 'Awaiting scan']);
            if (! $changed) {
                return;
            }
            DB::table('documents')->where('id', $doc->id)->increment('revision');
            $doc->revision++;
            $dms = app(DocumentService::class);
            $a = (object) ['name' => 'Security scanner', 'school_id' => $doc->school_id, 'campus' => $doc->campus];
            $dms->log($a, 'Security scan completed', $doc->id, $scan);
            $dms->event($doc, 'document.scan_completed', $v->id);
        });
        $count++;
    }
    $this->info('Processed '.$count.' versions. Failed and quarantined files remain blocked.');
})->purpose('Retry pending file scans; never bypasses malware scanning');
