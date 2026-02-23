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
            // Extract token from Authorization header
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'status' => false,
                    'message' => 'Route access token missing'
                ], 401);
            }

            // Decode token and get claims
            $payload = JWTAuth::setToken($token)->getPayload();
            // dd($payload);

            // Access custom claims
            $userId     = $payload->get('sub');  
            $subadmin_id     = $payload->get('subadmin_id');        // user id
            $email      = $payload->get('email');
            $loginType  = $payload->get('login_type');
            $issuedAt   = $payload->get('iat');
            $expiresAt  = $payload->get('exp');
            
            if (in_array($loginType, [2])) {
                $request->attributes->add([
                    'subadmin_id' => $subadmin_id
                ]);
                $request->merge(['subadmin_id' => $subadmin_id]);

            }



                $allAttributes = $request->attributes->all();
                // dd($allAttributes);
        } catch (JWTException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token',
                'error'   => $e->getMessage()
            ], 401);
        }

        return $next($request);
    }
}
