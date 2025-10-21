<?php

namespace Greatplr\AmemberSso\Http\Middleware;

use Closure;
use Greatplr\AmemberSso\Services\AmemberSsoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckAmemberProduct
{
    public function __construct(
        protected AmemberSsoService $amemberSso
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  int|string  ...$productIds
     */
    public function handle(Request $request, Closure $next, ...$productIds): Response
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

        // Convert product IDs to integers
        $productIds = array_map('intval', $productIds);

        // Check if user has access to any of the specified products
        $hasAccess = $this->amemberSso->hasProductAccess($amemberUserId, $productIds);

        if (!$hasAccess) {
            return $this->unauthorized(
                $request,
                'Access denied. Required product: ' . implode(' or ', $productIds)
            );
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
