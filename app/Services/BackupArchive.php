<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/** NFR-06: one encrypted archive of a consistent MySQL snapshot and immutable bytes. */
class BackupArchive
{
    public function create(): string
    {
        $password = $this->password();
        if (DB::connection()->getDriverName() !== 'mysql') throw new RuntimeException('Coordinated backups require the MySQL connection.');
        $defaults = realpath((string) config('operations.mysql_defaults_file'));
        if (! $defaults || ! is_file($defaults) || ! is_readable($defaults)) throw new RuntimeException('Configure a readable private MySQL client defaults file.');
        $directory = (string) config('operations.backup_directory');
        File::ensureDirectoryExists($directory, 0700);
        $public = strtolower(str_replace('\\', '/', realpath(public_path())));
        foreach ([realpath($directory), $defaults] as $privatePath) {
            $privatePath = strtolower(str_replace('\\', '/', $privatePath));
            if ($privatePath === $public || str_starts_with($privatePath, $public.'/')) throw new RuntimeException('Backup data and client configuration must be outside the public directory.');
        }
        $work = storage_path('app/private/backup-work/'.Str::uuid());
        File::ensureDirectoryExists($work, 0700);
        $archivePath = $directory.DIRECTORY_SEPARATOR.'dms-'.now()->utc()->format('Ymd-His').'-'.Str::uuid().'.zip';
        $partial = $archivePath.'.partial';
        $zip = new ZipArchive;
        $opened = false;
        try {
            $dump = $work.DIRECTORY_SEPARATOR.'database.sql';
            $connection = DB::connection()->getConfig();
            $process = new Process([(string) config('operations.mysqldump_binary'), '--defaults-extra-file='.$defaults,
                '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--hex-blob', '--result-file='.$dump,
                '--host='.$connection['host'], '--port='.(string) $connection['port'], (string) $connection['database']]);
            $process->setTimeout((int) config('operations.backup_timeout_seconds'));
            $process->run();
            // Never print mysqldump output: a client error may include private configuration.
            if (! $process->isSuccessful() || ! is_file($dump) || filesize($dump) === 0) throw new RuntimeException('MySQL snapshot failed; no backup was published.');
            @chmod($dump, 0600);
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Could not create encrypted backup archive.');
            $opened = true;
            $zip->setPassword($password);
            $checksums = ['database.sql' => hash_file('sha256', $dump)];
            $this->add($zip, $dump, 'database.sql', $password);
            // Versions are immutable and never hard-deleted. Reading references AFTER
            // the snapshot includes every snapshot version, plus harmless later files.
            foreach (DB::table('document_versions')->orderBy('cursor')->cursor() as $version) {
                if (! preg_match('#^documents/[0-9a-f-]{36}$#i', $version->storage_key)) throw new RuntimeException('A version storage path is invalid.');
                if (! Storage::disk('local')->exists($version->storage_key)) throw new RuntimeException('A referenced version file is missing; backup was not published.');
                $path = Storage::disk('local')->path($version->storage_key);
                $checksum = hash_file('sha256', $path);
                if (! hash_equals($version->checksum, $checksum)) throw new RuntimeException('Version checksum verification failed; backup was not published.');
                $name = 'private/'.$version->storage_key;
                $checksums[$name] = $checksum;
                $this->add($zip, $path, $name, $password);
            }
            $manifest = json_encode(['format' => 1, 'created_at' => now()->utc()->toIso8601String(), 'database' => $connection['database'], 'checksums' => $checksums], JSON_THROW_ON_ERROR);
            if (! $zip->addFromString('manifest.json', $manifest) || ! $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password)) throw new RuntimeException('Archive encryption failed.');
            if (! $zip->close()) throw new RuntimeException('Archive could not be finalized.');
            $opened = false;
            $this->verify($partial);
            if (! rename($partial, $archivePath)) throw new RuntimeException('Could not publish the completed backup.');
            @chmod($archivePath, 0600);
            $this->record(['backup_completed_at' => now()->utc()->toIso8601String(), 'backup_file' => basename($archivePath)]);
            return $archivePath;
        } finally {
            if ($opened) $zip->close();
            if (is_file($partial)) unlink($partial);
            // This fresh UUID directory is owned exclusively by this invocation.
            File::deleteDirectory($work);
        }
    }

    public function verify(string $path): array
    {
        $password = $this->password();
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('Backup archive could not be opened.');
        try {
            $zip->setPassword($password);
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false) throw new RuntimeException('Backup password or manifest is invalid.');
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 1 || empty($manifest['checksums']['database.sql'])) throw new RuntimeException('Unsupported backup manifest.');
            if ($zip->numFiles !== count($manifest['checksums']) + 1) throw new RuntimeException('Archive entries do not match the manifest.');
            foreach ($manifest['checksums'] as $name => $expected) {
                if ($name !== 'database.sql' && ! preg_match('#^private/documents/[0-9a-f-]{36}$#i', $name)) throw new RuntimeException('Unsafe backup entry.');
                $stat = $zip->statName($name);
                if (! $stat || ($stat['encryption_method'] ?? 0) !== ZipArchive::EM_AES_256) throw new RuntimeException('Backup contains an unencrypted entry.');
                $stream = $zip->getStream($name);
                if (! is_resource($stream)) throw new RuntimeException('Backup entry could not be decrypted.');
                try { $hash = hash_init('sha256'); hash_update_stream($hash, $stream); $actual = hash_final($hash); }
                finally { fclose($stream); }
                if (! hash_equals($expected, $actual)) throw new RuntimeException('Backup checksum verification failed.');
            }
            if (($zip->statName('manifest.json')['encryption_method'] ?? 0) !== ZipArchive::EM_AES_256) throw new RuntimeException('Backup manifest is not encrypted.');
            return $manifest;
        } finally { $zip->close(); }
    }

    public function record(array $values): void
    {
        $disk = Storage::disk('local');
        $current = $disk->exists('operations/status.json') ? json_decode($disk->get('operations/status.json'), true) : [];
        $disk->put('operations/status.json', json_encode(array_replace($current ?? [], $values), JSON_THROW_ON_ERROR));
    }

    private function password(): string
    {
        if (! class_exists(ZipArchive::class) || ! ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) throw new RuntimeException('ZIP AES-256 encryption support is required.');
        $password = (string) config('operations.backup_password');
        if (strlen($password) < 32) throw new RuntimeException('Configure a dedicated backup password of at least 32 characters.');
        return $password;
    }

    private function add(ZipArchive $zip, string $path, string $name, string $password): void
    {
        if (! $zip->addFile($path, $name) || ! $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password)) throw new RuntimeException('Could not encrypt a backup entry.');
    }
}
