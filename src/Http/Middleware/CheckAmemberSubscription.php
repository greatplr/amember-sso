<?php

namespace Greatplr\AmemberSso\Http\Middleware;

use Closure;
use Greatplr\AmemberSso\Services\AmemberSsoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckAmemberSubscription
{
    public function __construct(
        protected AmemberSsoService $amemberSso
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = config('amember-sso.guard');
        $user = Auth::guard($guard)->user();

        if (!$user) {
            return $this->unauthorized($request, 'User not authenticated');
        }

        // Get the user's email or login for check-access API
        $loginOrEmail = $user->email ?? $user->login ?? null;

        if (!$loginOrEmail) {
            return $this->unauthorized($request, 'User email/login not found');
        }

        // Check if user has any active subscription using check-access API
        $hasActiveSubscription = $this->amemberSso->hasActiveSubscription($loginOrEmail, true);

        if (!$hasActiveSubscription) {
            return $this->unauthorized($request, 'Access denied. No active subscription found.');
        }

        return $next($request);
    }

    /**
     * Handle unauthorized access.
     */
    protected function unauthorized(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => $message,
            ], 403);
        }

        abort(403, $message);
    }
}
