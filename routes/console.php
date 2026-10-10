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
