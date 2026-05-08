<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class CheckRouteAccessToken
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                // FALLBACK BYPASS FOR DEV/LOCAL (no token = superadmin dev mode)
                if (config('app.env') === 'local') {
                    // Inject default superadmin attributes so scope service works
                    $request->attributes->add([
                        'auth_user_id' => null,
                        'auth_login_type' => 1, // default = SuperAdmin (sees everything)
                        'auth_email'    => null,
                    ]);
                    return $next($request);
                }

                return response()->json([
                    'status'  => false,
                    'message' => 'Route access token missing'
                ], 401);
            }

            // Decode JWT and extract claims
            $payload = JWTAuth::setToken($token)->getPayload();

            $userId     = $payload->get('sub');
            $subadminId = $payload->get('subadmin_id');
            $email      = $payload->get('email');
            $loginType  = $payload->get('login_type');

            // ─── Inject auth context into every request ───────────────────────
            // auth_user_id     → the logged-in user's own ID (from JWT sub)
            // auth_login_type  → 1=SuperAdmin, 2=User, 3=Client
            // auth_email       → their email
            // subadmin_id      → only meaningful for login_type=2 (sub-admin parent)
            $request->attributes->add([
                'auth_user_id'    => (int) $userId,
                'auth_login_type' => (int) $loginType,
                'auth_email'      => $email,
                'subadmin_id'     => $subadminId,
            ]);

            // Also merge so controllers can read via $request->input()
            $request->merge([
                'auth_user_id'    => (int) $userId,
                'auth_login_type' => (int) $loginType,
            ]);

            // Legacy: keep subadmin_id merge for UserAdmin (login_type=2)
            if ($loginType == 2) {
                $request->merge(['subadmin_id' => $subadminId]);
            }

        } catch (JWTException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid or expired token',
                'error'   => $e->getMessage()
            ], 401);
        }

        return $next($request);
    }
}
