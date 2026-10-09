<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class DocumentService
{
    public function actor(Request $r): object
    {
        $a = $r->attributes->get('integration') ?? $r->user();
        abort_unless($a && $a->active, 403, 'Account is inactive.');

        return $a;
    }

    public function sources(object $a): array
    {
        if (isset($a->source)) {
            return [$a->source];
        }

        return match ($a->role) {
            'student' => ['Online Admission', 'Enrollment'],
            'teacher', 'employee' => ['Employee Management'],
            'admin' => config('dms.systems'), 'registrar' => ['Online Admission', 'Enrollment'],
            'hr' => ['Employee Management'], 'payroll' => ['Payroll Management'], default => [],
        };
    }

    public function scope($q, object $a)
    {
        if (in_array($a->role ?? null, ['student', 'teacher', 'employee'])) {
            if (! in_array($q->from, ['documents', 'folders'])) {
                abort(403, 'This role cannot access institutional history or integration feeds.');
            }
            $q->where('owner_user_id', $a->id);
        }
        return $q->where('school_id', $a->school_id)->where('campus', $a->campus)->whereIn('source', $this->sources($a));
    }

    public function document(string $id, object $a): object
    {
        $d = $this->scope(DB::table('documents'), $a)->whereNull('trashed_at')->where('id', $id)->first();
        abort_unless($d, 404, 'Document not found.');

        return $d;
    }

    public function log(object $a, string $action, ?string $doc = null, ?string $detail = null): void
    {
        DB::table('audit_entries')->insert(['school_id' => $a->school_id, 'campus' => $a->campus, 'actor' => $a->name, 'action' => $action, 'document_id' => $doc, 'detail' => $detail, 'created_at' => now()]);
    }

    public function event(object $d, string $type, string $v): void
    {
        DB::table('integration_events')->insert(['event_id' => (string) Str::uuid(), 'school_id' => $d->school_id, 'campus' => $d->campus, 'source' => $d->source, 'type' => $type, 'document_id' => $d->id, 'version_id' => $v, 'revision' => $d->revision, 'created_at' => now()]);
    }

    public function latest(string $id): object
    {
        return DB::table('document_versions')->where('document_id', $id)->orderByDesc('number')->first();
    }

    public function upload(Request $r, object $a, bool $sharedEditor = false): array
    {
        abort_unless(isset($a->source) || in_array($a->role, ['admin', 'registrar', 'hr', 'payroll', 'student', 'teacher', 'employee']), 403);
        $p = $r->validate([
            'title' => 'required|string|max:200', 'subject' => 'required|string|max:200', 'reference' => 'required|string|max:200',
            'source' => 'required|in:'.implode(',', config('dms.systems')), 'category' => 'required|in:'.implode(',', config('dms.categories')),
            'classification' => 'required|in:Internal,Confidential,Restricted', 'expires_at' => 'nullable|date_format:Y-m-d',
            'document_id' => 'nullable|uuid', 'revision' => 'nullable|integer|min:1', 'file' => 'required|file|max:10240|mimes:pdf,png,jpg,jpeg,txt',
        ]);
        abort_unless($sharedEditor || in_array($p['source'], $this->sources($a)), 403, 'Source system outside your permissions.');
        abort_if($p['category'] === 'Payslip' && ($p['source'] !== 'Payroll Management' || $p['classification'] !== 'Restricted'), 422, 'Payslips require payroll source and Restricted classification.');
        $file = $r->file('file');
        $name = $file->getClientOriginalName();
        abort_if(preg_match('/[\x00-\x1f\\\\\/:]/', $name) || strlen($name) > 200, 422, 'Invalid filename.');
        $bytes = file_get_contents($file->getRealPath());
        abort_if(strlen($bytes) === 0, 422, 'Choose a nonempty file.');
        $ext = strtolower($file->getClientOriginalExtension());
        $types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'txt' => 'text/plain'];
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(isset($types[$ext]) && $detected === $types[$ext], 422, 'File content must match its extension.');
        $signature = match ($ext) {
            'pdf' => str_starts_with($bytes, '%PDF-'),'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),'jpg','jpeg' => str_starts_with($bytes, "\xff\xd8\xff"),'txt' => mb_check_encoding($bytes, 'UTF-8') && ! str_contains($bytes, "\0"),default => false
        };
        abort_unless($signature, 422, 'Invalid file signature or text encoding.');
        $hash = hash('sha256', $bytes);
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 200, 422, 'Provide an Idempotency-Key of 8–200 characters.');
        unset($p['file']);
        $requestHash = hash('sha256', json_encode([$p, $name, $hash]));
        $key = hash('sha256', ($a->role ?? 'integration').':'.$a->id.':'.$key);
        $stored = null;
        try {
            return DB::transaction(function () use ($p, $a, $name, $bytes, $ext, $types, $hash, $key, $requestHash, $sharedEditor, &$stored) {
                $prior = DB::table('idempotency_requests')->where('key', $key)->first();
                if ($prior) {
                    abort_unless(hash_equals($prior->hash, $requestHash), 409, 'Key already used for a different submission.');

                    return json_decode($prior->response, true);
                }
                $id = $p['document_id'] ?? (string) Str::uuid();
                $number = 1;
                if (isset($p['document_id'])) {
                    $d = $sharedEditor ? DB::table('documents')->where('school_id', $a->school_id)->where('campus', $a->campus)->whereNull('trashed_at')->where('id', $id)->first() : $this->document($id, $a);
                    abort_unless($d, 404);
                    abort_unless(($p['revision'] ?? null) == $d->revision, 409, 'Document changed. Refresh and retry.');
                    foreach (['subject', 'reference', 'source', 'category', 'classification'] as $f) {
                        abort_unless($p[$f] === $d->$f, 422, 'Replacement must preserve ownership and classification.');
                    }
                    $number = $this->latest($id)->number + 1;
                    $changed = DB::table('documents')->where('id', $id)->where('revision', $d->revision)->update(['revision' => $d->revision + 1, 'updated_at' => now()]);
                    abort_unless($changed, 409, 'Document changed. Refresh and retry.');
                } else {
                    DB::table('documents')->insert(['id' => $id, 'owner_user_id' => isset($a->source) ? null : $a->id, 'title' => $p['title'], 'subject' => $p['subject'], 'reference' => $p['reference'], 'source' => $p['source'], 'category' => $p['category'], 'classification' => $p['classification'], 'school_id' => $a->school_id, 'campus' => $a->campus, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
                $version = (string) Str::uuid();
                $stored = 'documents/'.$version;
                abort_unless(Storage::disk('local')->put($stored, $bytes), 503, 'Private file storage is unavailable.');
                $scan = 'Pending';
                if ($scanner = config('dms.scanner')) {
                    try {
                        $proc = new Process([$scanner, '--no-summary', Storage::disk('local')->path($stored)]);
                        $proc->setTimeout(45)->run();
                        $scan = match ($proc->getExitCode()) {
                            0 => 'Clean',1 => 'Quarantined',default => 'Failed'
                        };
                    } catch (\Throwable) {
                        $scan = 'Failed';
                    }
                }
                $status = $scan === 'Clean' ? 'Available' : 'Awaiting scan';
                DB::table('document_versions')->insert(['id' => $version, 'document_id' => $id, 'number' => $number, 'filename' => $name, 'media_type' => $types[$ext], 'size' => strlen($bytes), 'checksum' => $hash, 'storage_key' => $stored, 'scan' => $scan, 'status' => $status, 'actor' => $a->name, 'actor_key' => (isset($a->source) ? 'connector:' : 'user:').$a->id, 'expires_at' => $p['expires_at'] ?? null, 'created_at' => now()]);
                $d = $sharedEditor ? DB::table('documents')->where('school_id', $a->school_id)->where('campus', $a->campus)->whereNull('trashed_at')->where('id', $id)->first() : $this->document($id, $a);
                    abort_unless($d, 404);
                $this->log($a, $number === 1 ? 'Document uploaded' : 'Version added', $id, 'Version '.$number.'; scan '.$scan);
                $this->event($d, $number === 1 ? 'document.received' : 'document.version_added', $version);
                $result = ['document_id' => $id, 'version_id' => $version, 'version' => $number, 'scan' => $scan, 'status' => $status];
                DB::table('idempotency_requests')->insert(['key' => $key, 'hash' => $requestHash, 'response' => json_encode($result), 'created_at' => now()]);

                return $result;
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }throw $e;
        }
    }
}
