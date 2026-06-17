<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            return redirect()->route('login')->with('status', 'Votre session a expiré. Veuillez vous reconnecter.');
        });
    })->create()
    ->tap(function ($app) {
        // On Vercel, the filesystem is read-only except /tmp.
        // The VERCEL env variable is automatically set to '1' by Vercel.
        if (getenv('VERCEL')) {
            $app->useStoragePath('/tmp/storage');
        }
    });

