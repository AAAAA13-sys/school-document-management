<?php

namespace App\Console\Commands;

use App\Enums\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateConnector extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dms:connector
                            {source : The ERP source code (ES, OAS, EMS, PMS)}
                            {--name= : Friendly name for the connector}
                            {--school=PLP : School identifier}
                            {--campus=Main : Campus identifier}
                            {--token= : Predefined secret token (auto-generated if omitted)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision or rotate an API connector token for an external ERP system (ES, OAS, EMS, PMS)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawSource = strtoupper(trim((string) $this->argument('source')));
        $validSources = array_map(fn (Source $s) => $s->value, Source::cases());

        if (! in_array($rawSource, $validSources, true)) {
            $this->error("Invalid source '{$rawSource}'. Valid options: " . implode(', ', $validSources));
            return self::FAILURE;
        }

        $school = (string) $this->option('school');
        $campus = (string) $this->option('campus');
        $name = (string) ($this->option('name') ?: "{$school} {$rawSource} System Connector");
        $token = (string) ($this->option('token') ?: ('dms_' . strtolower($rawSource) . '_' . Str::random(40)));
        $tokenHash = hash('sha256', $token);

        $existing = DB::table('connectors')
            ->where('source', $rawSource)
            ->where('school_id', $school)
            ->where('campus', $campus)
            ->first();

        if ($existing) {
            DB::table('connectors')->where('id', $existing->id)->update([
                'name' => $name,
                'token_hash' => $tokenHash,
                'active' => true,
                'updated_at' => now(),
            ]);
            $connectorId = $existing->id;
            $this->info("Updated existing connector (ID: {$connectorId}) for {$rawSource}.");
        } else {
            $connectorId = DB::table('connectors')->insertGetId([
                'name' => $name,
                'source' => $rawSource,
                'school_id' => $school,
                'campus' => $campus,
                'token_hash' => $tokenHash,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Provisioned new connector (ID: {$connectorId}) for {$rawSource}.");
        }

        $this->newLine();
        $this->table(
            ['Property', 'Value'],
            [
                ['Connector ID', $connectorId],
                ['Name', $name],
                ['Source', $rawSource],
                ['School / Campus', "{$school} / {$campus}"],
                ['Secret Token (Plain)', $token],
                ['Token Hash (SHA-256)', $tokenHash],
                ['Status', 'Active'],
            ]
        );

        $this->newLine();
        $this->line('<fg=yellow;options=bold>How to configure in the external system (' . $rawSource . '):</>');
        $this->line('Pass either the HTTP Bearer header or the X-API-Key header in API requests:');
        $this->line("  <fg=cyan>Authorization: Bearer {$token}</>");
        $this->line("  <fg=cyan>X-API-Key: {$token}</>");

        if ($rawSource === 'ES') {
            $this->newLine();
            $this->line('<fg=green;options=bold>ES Flow (3) Integration Endpoints:</>');
            $this->line('  1. Read Admitted Applicant Packet:');
            $this->line("     <fg=gray>curl -H \"X-API-Key: {$token}\" http://localhost:8000/api/v1/applicant-packets/{packet_id}</>");
            $this->line('  2. Download Applicant Requirement File (e.g. PSA / Form 137):');
            $this->line("     <fg=gray>curl -H \"X-API-Key: {$token}\" http://localhost:8000/api/v1/applicant-packets/{packet_id}/files/{version_id} -o document.pdf</>");
            $this->line('  3. Store Student COR or Archived Record in DMS:');
            $this->line("     <fg=gray>curl -X POST -H \"X-API-Key: {$token}\" -F \"file=@cor.pdf\" -F \"title=COR Student 2026-0001\" -F \"source=ES\" http://localhost:8000/api/v1/documents</>");
        }

        return self::SUCCESS;
    }
}

