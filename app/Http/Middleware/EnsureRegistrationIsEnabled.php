<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sign-up does not exist while the admin has registration turned off.
 */
class EnsureRegistrationIsEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        throw_unless(config()->boolean('monoforge.auth.registration.enabled'), NotFoundHttpException::class);

        return $next($request);
    }
}
