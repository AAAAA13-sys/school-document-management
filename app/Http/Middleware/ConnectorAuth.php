<?php

namespace App\Http\Middleware;

use App\Services\WorkspaceRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConnectorAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        abort_unless($token && strlen($token) <= 256, 401, 'A connector bearer token is required.');
        $connector = DB::table('connectors')->where('token_hash', hash('sha256', $token))->where('active', true)->first();
        abort_unless($connector, 401, 'Invalid or inactive connector.');
        WorkspaceRegistry::assertErp($connector);
        $request->attributes->set('connector', $connector);
        DB::table('connectors')->where('id', $connector->id)->update(['last_seen_at' => now()]);

        return $next($request);
    }
}
