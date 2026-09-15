<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminOnly::class,
        ]);
        // API-only backend: never redirect unauthenticated requests to a missing
        // "login" route (that caused a 500 instead of 401).
        $middleware->redirectGuestsTo(fn (Request $request) => null);
        $middleware->append(\App\Http\Middleware\SetLocaleFromRequest::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'message' => 'Resource not found', 'errors' => null], 404);
            }
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                $errors = [];
                foreach ($exception->errors() as $path => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = ['location' => 'body', 'path' => $path, 'msg' => $message];
                    }
                }
                return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $errors], 400);
            }
        });
    })->create();
