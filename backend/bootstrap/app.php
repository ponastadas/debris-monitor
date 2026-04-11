<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Normalize validation errors to standard envelope
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'error'   => [
                        'code'    => 'VALIDATION_ERROR',
                        'message' => 'The given data was invalid.',
                        'details' => $e->errors(),
                    ],
                ], 422);
            }
        });

        // Normalize unauthenticated errors
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'error'   => [
                        'code'    => 'UNAUTHENTICATED',
                        'message' => 'Unauthenticated.',
                        'details' => [],
                    ],
                ], 401);
            }
        });

        // Normalize all other JSON exceptions
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->expectsJson()) {
                $status  = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                $message = app()->isProduction() ? 'An unexpected error occurred.' : $e->getMessage();

                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'error'   => [
                        'code'    => 'SERVER_ERROR',
                        'message' => $message,
                        'details' => [],
                    ],
                ], $status >= 400 ? $status : 500);
            }
        });
    })->create();
