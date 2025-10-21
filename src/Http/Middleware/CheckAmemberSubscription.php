<?php

namespace Greatplr\AmemberSso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckAmemberSubscription
{
    /**
     * Handle an incoming request.
     * Checks LOCAL database for any active subscription (populated by webhooks).
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
        $amemberUserId = $user->amember_user_id;

        if (!$amemberUserId) {
            return $this->unauthorized($request, 'User not linked to aMember account');
        }

        // Check LOCAL database for any active subscription
        $hasActiveSubscription = $this->checkLocalActiveSubscription($amemberUserId);

        if (!$hasActiveSubscription) {
            return $this->unauthorized($request, 'Access denied. No active subscription found.');
        }

        return $next($request);
    }

    /**
     * Check if user has any active subscription in local database.
     */
    protected function checkLocalActiveSubscription(int $amemberUserId): bool
    {
        $tableName = config('amember-sso.tables.subscriptions');

        $activeSubscription = DB::table($tableName)
            ->where('user_id', $amemberUserId)
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
