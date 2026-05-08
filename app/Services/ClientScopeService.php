<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * ClientScopeService
 * ==================
 * Central service for client-based data isolation.
 *
 * ARCHITECTURE:
 *   - SuperAdmin (login_type=1): sees ALL records, no filter applied
 *   - UserAdmin  (login_type=2): sees ALL records (same as SuperAdmin)
 *   - Client     (login_type=3): sees ONLY their own records (filtered by client_id)
 *
 * When a Client adds/updates a record, their client_id is auto-injected.
 * SuperAdmin sees the record instantly — it was never separate, just tagged.
 *
 * Usage in controllers:
 *   // Listing (read)
 *   $query = Subscription::with([...]);
 *   ClientScopeService::applyScope($query, $request);
 *   $records = $query->get();
 *
 *   // Creating (write)
 *   ClientScopeService::enforceClientId($request);
 *   Subscription::create([..., 'client_id' => $request->client_id]);
 *
 *   // Ownership check (update/delete)
 *   ClientScopeService::assertOwnership($record, $request);
 */
class ClientScopeService
{
    /**
     * Returns the client_id if the logged-in user is a Client (login_type=3).
     * Returns null for SuperAdmin / UserAdmin (no filter needed).
     */
    public static function getClientId(Request $request): ?int
    {
        $loginType = (int) $request->attributes->get('auth_login_type', 1);
        $userId    = (int) $request->attributes->get('auth_user_id', 0);

        if ($loginType === 3 && $userId > 0) {
            return $userId;
        }

        return null;
    }

    /**
     * Returns true if the current requester is a Client.
     */
    public static function isClient(Request $request): bool
    {
        return self::getClientId($request) !== null;
    }

    /**
     * Apply client_id scope to an Eloquent Builder or Query Builder.
     * Supports both Eloquent (with()) and raw DB queries.
     *
     * @param Builder|QueryBuilder $query
     * @param Request              $request
     * @param string               $column  Column name to filter on (default: 'client_id')
     */
    public static function applyScope($query, Request $request, string $column = 'client_id'): void
    {
        $clientId = self::getClientId($request);
        if ($clientId !== null) {
            $query->where($column, $clientId);
        }
    }

    /**
     * When a Client is creating a record, force their client_id into the request.
     * For SuperAdmin/UserAdmin the client_id is passed by the form as usual.
     */
    public static function enforceClientId(Request $request): void
    {
        $clientId = self::getClientId($request);
        if ($clientId !== null) {
            $request->merge(['client_id' => $clientId]);
        }
    }

    /**
     * Assert that a record belongs to the requester (Client-only guard).
     * SuperAdmin/UserAdmin always pass.
     * Throws 403 if a Client tries to access another client's record.
     *
     * @param mixed  $record   Eloquent model or stdClass with client_id
     * @param Request $request
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    public static function assertOwnership($record, Request $request): void
    {
        $clientId = self::getClientId($request);
        if ($clientId === null) {
            return; // SuperAdmin/UserAdmin — unrestricted
        }

        $recordClientId = is_array($record) ? ($record['client_id'] ?? null) : ($record->client_id ?? null);

        if ((int) $recordClientId !== $clientId) {
            abort(response()->json([
                'success' => false,
                'message' => 'Access denied: This record does not belong to your account.'
            ], 403));
        }
    }

    /**
     * Get a human-readable description of the current requester.
     */
    public static function getRequesterLabel(Request $request): string
    {
        $loginType = (int) $request->attributes->get('auth_login_type', 1);
        $userId    = (int) $request->attributes->get('auth_user_id', 0);
        return match($loginType) {
            1 => "SuperAdmin",
            2 => "UserAdmin (ID:{$userId})",
            3 => "Client (ID:{$userId})",
            default => "Unknown"
        };
    }
}
