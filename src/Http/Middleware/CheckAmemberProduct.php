<?php

namespace Greatplr\AmemberSso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckAmemberProduct
{
    /**
     * Handle an incoming request.
     * Checks LOCAL database for product access (populated by webhooks).
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
        $amemberUserId = $user->amember_user_id;

        if (!$amemberUserId) {
            return $this->unauthorized($request, 'User not linked to aMember account');
        }

        // Convert product IDs to integers
        $productIds = array_map('intval', $productIds);

        // Check LOCAL database for active subscriptions
        $hasAccess = $this->checkLocalProductAccess($amemberUserId, $productIds);

        if (!$hasAccess) {
            return $this->unauthorized(
                $request,
                'Access denied. Required product: ' . implode(' or ', $productIds)
            );
        }

        return $next($request);
    }

    /**
     * Check if user has access to products in local database.
     */
    protected function checkLocalProductAccess(int $amemberUserId, array $productIds): bool
    {
        $tableName = config('amember-sso.tables.subscriptions');

        $activeSubscription = DB::table($tableName)
            ->where('user_id', $amemberUserId)
            ->whereIn('product_id', $productIds)
            ->where(function ($query) {
                $query->where('expire_date', '>', now())
                    ->orWhereNull('expire_date'); // Lifetime subscriptions
            })
            ->where('begin_date', '<=', now())
            ->exists();

        return $activeSubscription;
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
