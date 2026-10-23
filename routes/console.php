<?php

use App\Jobs\ScanDocumentVersion;
use App\Models\User;
use App\Services\Access;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('dms:admin {email} {--school=PLP} {--campus=Main}', function () {
    $email = mb_strtolower(trim($this->argument('email')));
    foreach (['school', 'campus'] as $field) {
        if (! trim($this->option($field)) || mb_strlen($this->option($field)) > 64) {
            $this->error('School and campus must be 1 to 64 characters.');

            return 1;
        }
    }
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Account already exists. Use the admin account screen.');

        return 1;
    }
    $name = $this->ask('Administrator name');
    $password = $this->secret('Password (12+ characters, upper/lowercase, number and symbol)');
    if (! $name || strlen($name) > 255 || strlen($password ?? '') < 12 || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[a-z]/', $password) || ! preg_match('/[0-9]/', $password) || ! preg_match('/[^A-Za-z0-9]/', $password)) {
        $this->error('Name or password does not meet requirements.');

        return 1;
    }
    DB::transaction(function () use ($email, $name, $password) {
        $user = User::create(['email' => $email, 'name' => $name, 'password' => $password, 'role' => 'super_admin', 'source' => 'DMS', 'school_id' => $this->option('school'), 'campus' => $this->option('campus'), 'active' => true]);
        Access::audit($user, 'account.bootstrapped');
    });
    $this->info('Administrator created.');

    return 0;
})->purpose('Create the initial scoped administrator without default credentials');

Artisan::command('dms:scan-retry {--limit=50}', function () {
    $limit = (int) $this->option('limit');
    if ($limit < 1 || $limit > 500) {
        $this->error('Limit must be 1 to 500.');

        return 1;
    }
    $rows = DB::table('document_versions')->whereIn('scan_status', ['Pending', 'Failed'])
        ->orderBy('scan_attempted_at')->orderBy('created_at')->limit($limit)->pluck('id');
    foreach ($rows as $id) {
        ScanDocumentVersion::dispatch($id)->onConnection('database')->onQueue('scans');
    }
    $this->info($rows->count().' blocked versions queued for background scanning.');

    return 0;
})->purpose('Queue pending/failed scans; never release quarantined files');
Schedule::command('dms:scan-retry')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('dms:signatures-update')->hourly()->withoutOverlapping();

Schedule::command('dms:backup')->dailyAt('02:00')->when(fn () => config('operations.backup_enabled'))->withoutOverlapping();
