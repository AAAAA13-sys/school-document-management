<?php

use App\Services\HistoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'dms_test' || config('database.default') !== 'mysql') {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$actor = DB::table('connectors')->where('id', $input['connector'])->first();
try {
    [$body,$status] = app(HistoryService::class)->receive($actor, $input['event']);
    echo json_encode(['status' => $status, 'body' => $body]);
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
