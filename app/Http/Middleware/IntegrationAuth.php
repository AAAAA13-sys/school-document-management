<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationAuth
{
    public function handle(Request $r, Closure $next)
    {
        $token = $r->bearerToken();
        abort_unless($token, 401, 'Connector token required.');
        $a = DB::table('integration_accounts')->where('token_hash', hash('sha256', $token))->where('active', true)->first();
        abort_unless($a, 401, 'Invalid connector token.');
        $r->attributes->set('integration', $a);

        return $next($r);
    }
}
