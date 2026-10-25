<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConnectorAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ?: $request->header('X-API-KEY') ?: $request->header('X-Connector-Token');
        if (! $token && auth()->check()) {
            $user = auth()->user();
            abort_unless($user->active, 403, 'Your account is inactive.');
            $connector = (object) [
                'id' => 999000 + $user->id,
                'name' => 'Session: '.$user->name,
                'source' => $user->sourceCode() ?? 'EMS',
                'school_id' => $user->school_id,
                'campus' => $user->campus,
                'active' => true,
            ];
            $request->attributes->set('connector', $connector);

            return $next($request);
        }
        abort_unless($token && strlen($token) <= 256, 401, 'A connector bearer token is required.');
        $connector = DB::table('connectors')->where('token_hash', hash('sha256', $token))->where('active', true)->first();
        abort_unless($connector, 401, 'Invalid or inactive connector.');
        \App\Services\WorkspaceRegistry::assertErp($connector);
        $request->attributes->set('connector', $connector);
        DB::table('connectors')->where('id', $connector->id)->update(['last_seen_at' => now()]);

        return $next($request);
    }
}
