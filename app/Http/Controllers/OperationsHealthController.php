<?php

namespace App\Http\Controllers;

use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OperationsHealthController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()?->active && $request->user()->isSuperAdmin(), 403);
        try {
            DB::select('SELECT 1');
            $versions = DB::table('document_versions')->whereIn('document_id', Access::documents($request->user())->select('id'));
            $scans = [
                'pending' => (clone $versions)->where('scan_status', 'Pending')->count(),
                'stuck' => (clone $versions)->where('scan_status', 'Pending')->where('created_at', '<', now()->subMinutes((int) config('operations.stuck_scan_minutes')))->count(),
                'failed' => (clone $versions)->where('scan_status', 'Failed')->count(),
                'quarantined' => (clone $versions)->where('scan_status', 'Quarantined')->count(),
            ];
            $queue = ['pending_jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count(), 'worker_liveness' => 'not_measured'];
            $disk = Storage::disk('local');
            $operations = $disk->exists('operations/status.json') ? json_decode($disk->get('operations/status.json'), true) : [];
            $state = $scans['stuck'] || $scans['failed'] || $queue['failed_jobs'] ? 'degraded' : 'ok';
            return response()->json(['status' => $state, 'database' => 'ok', 'scans' => $scans, 'queue' => $queue,
                'backup_enabled' => config('operations.backup_enabled'), 'operations' => $operations,
                'targets' => ['monthly_availability' => '99.5%', 'rpo_hours' => 24, 'rto_hours' => 8, 'audit_retention_years' => 3]], 200)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable) {
            return response()->json(['status' => 'unavailable', 'database' => 'unavailable'], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
