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

        // Get the user's aMember ID
        $amemberUserId = $user->amember_user_id ?? null;

        if (!$amemberUserId) {
            return $this->unauthorized($request, 'User not linked to aMember account');
        }

        // Check if user has any active subscription
        $hasActiveSubscription = $this->amemberSso->hasActiveSubscription($amemberUserId);

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
