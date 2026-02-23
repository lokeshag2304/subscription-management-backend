<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AgentPermission
{
    /**
     * Check if an agent has permission for a module
     *
     * @param int $agentId
     * @param string $moduleName  (case insensitive)
     * @return array [
     *    'status' => true/false,
     *    'subadmin_id' => int|null
     * ]
     */
    public static function check($agentId, $moduleName)
    {
        if (empty($agentId)) {
            return [
                'status' => false,
                'subadmin_id' => null
            ];
        }

        // Get agent info
        $agentInfo = DB::table('superadmins')->where('id', $agentId)->first();

        if (!$agentInfo) {
            return [
                'status' => false,
                'subadmin_id' => null
            ];
        }

        // Get all module IDs assigned to this agent
        $moduleIds = DB::table('modules_permission')
            ->where('agent_id', $agentId)
            ->pluck('modules_id')
            ->toArray();

        if (empty($moduleIds)) {
            return [
                'status' => false,
                'subadmin_id' => null
            ];
        }

        // Check if given module exists in his permission
        $hasModule = DB::table('modules')
            ->whereIn('id', $moduleIds)
            ->whereRaw('LOWER(name) = ?', [strtolower($moduleName)])
            ->exists();

        if (!$hasModule) {
            return [
                'status' => false,
                'subadmin_id' => null
            ];
        }

        // If module exists → Return success + agent's subadmin id
        return [
            'status' => true,
            'subadmin_id' => $agentInfo->subadmin_id
        ];
    }
}
