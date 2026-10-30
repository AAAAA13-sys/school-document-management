<?php

use App\Exceptions\DuplicateFileException;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'dms_test') {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require __DIR__.'/require-isolated-mysql.php';
// Align both workers on one instant so their checksum locks actually overlap.
$releaseAt = (float) ($input['release_at'] ?? 0);
if ($releaseAt > 0) {
    $wait = (int) (($releaseAt - microtime(true)) * 1000000);
    if ($wait > 0) {
        usleep($wait);
    }
}
$user = User::findOrFail($input['user']);
$path = tempnam(sys_get_temp_dir(), 'dms-upload-');
file_put_contents($path, $input['content']);
$file = new UploadedFile($path, $input['filename'] ?? 'concurrent.txt', 'text/plain', null, true);
try {
    [$body,$status] = app(DocumentService::class)->upload($user, $input['meta'], $file, null, $input['key']);
    echo json_encode(['status' => $status, 'body' => $body]);
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode(),
        'code' => $e instanceof DuplicateFileException ? 'duplicate_file' : null,
        'message' => $e->getMessage()]);
} finally {
    if (is_file($path)) {
        @unlink($path);
    }
}
