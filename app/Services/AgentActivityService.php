<?php
namespace App\Services;

use App\Events\AgentNotificationEvent;
use Illuminate\Support\Facades\Http;


class AgentActivityService
{
    // public static function notify($agentId, $data)
    // {
    //     \Log::info("Notifying agent {$agentId} via Pusher", $data);
    //     broadcast(new AgentNotificationEvent($agentId, $data))->toOthers();
    // }

     public static function notify($agentId, $data)
    {
        \Log::info("Notifying agent {$agentId} via Socket.IO", $data);

        try {
            Http::post('https://fsiticketportal.testingscrew.com:7600/emit', [
                'event' => "agent.{$agentId}", // Use a channel-like event name
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to send Socket.IO event: " . $e->getMessage());
        }
    }
}