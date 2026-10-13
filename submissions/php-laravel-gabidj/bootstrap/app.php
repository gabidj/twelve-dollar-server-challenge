<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AuthenticateJwt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Octane bootstraps the app once per worker, so this is the worker's start time (for /health).
$GLOBALS['feed_started_at'] = time();

return Application::configure(basePath: dirname(__DIR__))
    // No `web` or `api` group: no sessions, cookies, CSRF, throttling or `/api` prefix.
    ->withRouting(using: fn () => Route::middleware([])->group(base_path('routes/api.php')))
    ->withMiddleware(function (Middleware $middleware) {
        // Drop the default global stack (trusted proxies, CORS, maintenance mode, string trimming, ...).
        $middleware->use([]);
        $middleware->alias(['jwt' => AuthenticateJwt::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReport(ApiException::class);
        $exceptions->render(function (Throwable $e, Request $request) {
            [$status, $message] = match (true) {
                $e instanceof ApiException => [$e->status, $e->getMessage()],
                $e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), [404, 405], true) => [404, 'not found'],
                default => [500, 'internal server error'],
            };

            return new JsonResponse(['error' => $message], $status);
        });
    })
    ->create();
