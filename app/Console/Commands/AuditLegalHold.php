<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Access;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditLegalHold extends Command
{
    protected $signature = 'dms:audit-hold {administrator : Active Super Admin email} {--reason= : Required legal basis or release reason} {--release= : Existing hold ID to release}';

    protected $description = 'Record or release a scoped audit legal hold; never deletes audit evidence';

    public function handle(): int
    {
        $actor = User::where('email', $this->argument('administrator'))->where('active', true)->where('role', 'super_admin')->first();
        $reason = trim((string) $this->option('reason'));
        if (! $actor || $reason === '' || mb_strlen($reason) > 2000) {
            $this->error('An active Super Admin and a reason of 1–2000 characters are required.');

            return self::FAILURE;
        }
        $id = DB::transaction(function () use ($actor, $reason) {
            if ($id = $this->option('release')) {
                $hold = Access::scope(DB::table('audit_legal_holds'), $actor)->where('id', $id)->whereNull('released_at')->lockForUpdate()->first();
                if (! $hold) {
                    return null;
                }
                DB::table('audit_legal_holds')->where('id', $id)->update(['released_at' => now(), 'updated_at' => now()]);
                Access::audit($actor, 'audit.legal_hold.released', null, ['hold_id' => $id, 'reason' => $reason]);

                return $id;
            }
            $id = DB::table('audit_legal_holds')->insertGetId(['school_id' => $actor->school_id, 'campus' => $actor->campus, 'actor_id' => $actor->id, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            Access::audit($actor, 'audit.legal_hold.created', null, ['hold_id' => $id, 'reason' => $reason]);

            return $id;
        });
        if (! $id) {
            $this->error('No active legal hold exists in this administrator’s scope.');

            return self::FAILURE;
        }
        $this->info('Legal hold '.$id.' '.($this->option('release') ? 'released' : 'recorded').'. Audit history is retained; no evidence was deleted.');

        return self::SUCCESS;
    }
}
