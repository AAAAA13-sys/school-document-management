<?php

use App\Http\Controllers\InvitationRegistrationController;
use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\ConnectorAuth;
use App\Http\Middleware\InvitationPrivacy;
use App\Http\Middleware\InvitationSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(InvitationPrivacy::class);
        $middleware->replaceInGroup('web', StartSession::class, InvitationSession::class);
        $middleware->alias(['active' => ActiveAccount::class, 'connector' => ConnectorAuth::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/*')]);
        $middleware->prependToPriorityList(ThrottleRequests::class, ConnectorAuth::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Exception URLs/stack contexts may contain bearer tokens. Token routes
        // receive only safe HTML, even for JSON requests or APP_DEBUG=true.
        $exceptions->dontReportWhen(fn (Throwable $error) => app()->bound('request') && request()->is('invitations/*'));
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! preg_match('~^invitations/[^/]+$~', $request->path())) {
                return null;
            }
            $http = $error instanceof HttpExceptionInterface;
            $code = $http ? $error->getStatusCode() : 503;
            $state = match ($code) {
                404 => 'invalid', 419 => 'session_expired', 429 => 'limited', default => 'unavailable',
            };
            $response = InvitationRegistrationController::unavailable($state, in_array($code, [404, 419, 429], true) ? $code : 503);
            if ($http) {
                // Preserve rate-limit timing without copying arbitrary exception headers.
                $headers = $error->getHeaders();
                if (isset($headers['Retry-After'])) {
                    $response->headers->set('Retry-After', $headers['Retry-After']);
                }
            }

            return InvitationPrivacy::protect($response);
        });
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
