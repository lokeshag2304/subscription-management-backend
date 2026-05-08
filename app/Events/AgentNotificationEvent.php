<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentNotificationEvent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public $agentId;
    public $data;

    public function __construct($agentId, $data)
    {
        $this->agentId = $agentId;
        $this->data = $data;
    }

    public function broadcastOn()
    {
        // return new PrivateChannel('agent.' . $this->agentId);
            return new Channel('agent.' . $this->agentId); // 👈 PUBLIC Channel
    }

    public function broadcastAs()
    {
        return 'agent-notification';
    }
}
