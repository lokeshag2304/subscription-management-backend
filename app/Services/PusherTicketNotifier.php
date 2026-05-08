<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Services\AgentActivityService;

class PusherTicketNotifier
{
    /**
     * Notify agents for a new ticket.
     *
     * @param int $ticketId
     * @param int $excludeAgentId Agent who should be excluded from the notification
     * @param array $extraAgents Additional agents to be notified
     */
    public static function notifyAssignedAgents($ticketId, $excludeAgentId = null,$tags = null)
    {
        $extraAgents = [6, 291];
        // Get all agents assigned to this ticket
        $currentAssignments = DB::table('agent_assign_history')
            ->where('ticket_id', $ticketId)
            ->pluck('agent_id')
            ->toArray();

        // Merge with extra agents
        $allAgents = array_unique(array_merge($currentAssignments, $extraAgents));

        // Filter out the agent who assigned (if any)
        $filteredAgents = array_filter($allAgents, function ($agentId) use ($excludeAgentId) {
            return $agentId != $excludeAgentId;
        });

        // Send notifications
        foreach ($filteredAgents as $agentId) {
            AgentActivityService::notify($agentId, [
                'event_type' => 'new_ticket',
                'ticket_id' => $ticketId,
                'tags' =>$tags,
                'message' => 'New ticket assigned to you',

            ]);
        }
    }
}
