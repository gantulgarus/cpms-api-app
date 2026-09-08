<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Алдааг гэрээнд заасан дугтуйгаар буцаана:
         *
         *     { "error": { "name": …, "message": …, "details": … } }
         *
         * Laravel-ийн анхдагч хэлбэр (`{ message, errors }`) нь frontend-ийн
         * `client.ts`-д ойлгогдохгүй тул хэрэглэгчид "Request failed (422)"
         * гэсэн утгагүй мессеж харагддаг байсан. Mock ч ижил дугтуй буцаадаг —
         * хоёр эх сурвалж ялгаагүй байх ёстой.
         *
         * Laravel-ийн `message`/`errors` түлхүүрийг мөн үлдээв: `assertJson-
         * ValidationErrorFor` зэрэг тестийн туслахууд түүнийг хүлээдэг.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            [$status, $name, $message, $details] = match (true) {
                $e instanceof ValidationException => [
                    422,
                    'ValidationError',
                    $e->validator->errors()->first(),
                    $e->errors(),
                ],
                $e instanceof AuthenticationException => [
                    401,
                    'Unauthenticated',
                    'Нэвтэрч орно уу.',
                    null,
                ],
                $e instanceof AuthorizationException => [
                    403,
                    'Forbidden',
                    $e->getMessage() ?: 'Танд эрх байхгүй.',
                    null,
                ],
                $e instanceof ModelNotFoundException => [
                    404,
                    'NotFound',
                    'Хүсэлт хийсэн бичлэг олдсонгүй.',
                    null,
                ],
                $e instanceof NotFoundHttpException => [
                    404,
                    'NotFound',
                    $e->getMessage() ?: 'Хаяг олдсонгүй.',
                    null,
                ],
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    'HttpError',
                    $e->getMessage() ?: 'Хүсэлт биелсэнгүй.',
                    null,
                ],
                default => [
                    500,
                    'ServerError',
                    // Production-д дотоод алдааны текстийг гаргахгүй.
                    config('app.debug') ? $e->getMessage() : 'Дотоод алдаа гарлаа.',
                    null,
                ],
            };

            $body = ['error' => array_filter([
                'name' => $name,
                'message' => $message,
                'details' => $details,
            ], fn ($v) => $v !== null)];

            // Laravel-ийн ердийн түлхүүрүүд — тестийн туслахуудад шаардлагатай.
            $body['message'] = $message;
            if ($details !== null) {
                $body['errors'] = $details;
            }

            return response()->json($body, $status);
        });
    })->create();
