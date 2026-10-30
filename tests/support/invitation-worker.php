<?php

use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'dms_test') {
    exit(2);
}
// Never allow an inherited connection URL to override the isolated schema.
config(['database.connections.mysql.url' => null]);
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$wait = (int) (((float) $input['release_at'] - microtime(true)) * 1000000);
if ($wait > 0) {
    usleep($wait);
}
// Hold the winning acceptance lock briefly so the competing request must wait.
DB::listen(function ($query) {
    if (str_contains($query->sql, 'employee_invitations') && str_contains($query->sql, 'for update')) {
        usleep(250000);
    }
});
try {
    $service = app(InvitationService::class);
    if ($input['operation'] === 'accept') {
        $user = $service->accept($input['token'], $input['attributes']);
        echo json_encode(['status' => 201, 'user_id' => $user->id]);
    } else {
        $issued = $service->create(User::findOrFail($input['actor']), $input['attributes']);
        echo json_encode(['status' => 201, 'invitation_id' => $issued->invitation->id]);
    }
} catch (ValidationException) {
    echo json_encode(['status' => 422]);
} catch (Throwable) {
    // Never print exception bindings, credentials or worker input.
    fwrite(STDERR, 'Invitation worker failed.');
    exit(1);
}
