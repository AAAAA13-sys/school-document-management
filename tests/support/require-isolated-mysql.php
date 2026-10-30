<?php

use Illuminate\Support\Facades\DB;

// Read-only verification before any subprocess can migrate or write test data.
try {
    if (! app()->environment('testing') || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'dms_test'
        || ! empty(config('database.connections.mysql.url'))
        || DB::connection()->selectOne('SELECT DATABASE() AS selected_schema')->selected_schema !== 'dms_test') {
        exit(2);
    }
} catch (Throwable) {
    // Never include credentials, driver errors or query bindings in worker output.
    exit(2);
}
