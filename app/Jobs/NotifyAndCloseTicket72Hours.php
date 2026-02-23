<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Lib\EmailCurl;
use Illuminate\Support\Facades\DB;
use App\Services\CryptService;

class NotifyAndCloseTicket72Hours implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    protected $ticket;
    protected $templateId;
    protected $subadminId;

    public function __construct(array $ticket, int $templateId,int $subadminId)
    {
        $this->ticket = $ticket;
        $this->templateId = $templateId;
        $this->subadminId = $subadminId;
    }

     public function handle()
    {
        $ticket = $this->ticket;
        $EmailCurl = new EmailCurl($this->subadminId);

        // Fetch template for 24h
        $templateRow = DB::table("templates")->where("id", $this->templateId)->first();
        $customerData = DB::table("superadmins")->where("id", $ticket['customer_id'])->first();
        if(!empty($customerData)){
           $customer_name = CryptService::decryptData($customerData->name);
            $customer_email = CryptService::decryptData($customerData->email);
            $ticket_title = CryptService::decryptData($ticket['subject']);
            $assignedAgentIds = DB::table('agent_assign_history')
            ->where('ticket_id', $ticket['id'])
            ->pluck('agent_id')
            ->toArray();
            $footer_team_name = null;
            if (!empty($assignedAgentIds)) {
                $agentsWithAccess = DB::table('superadmins')
                    ->whereIn('id', $assignedAgentIds)
                    ->where('root_access', 1)
                    ->get();

                foreach ($agentsWithAccess as $agent) {
                    if (!empty($agent->footer_team_name)) {
                        $footer_team_name = CryptService::decryptData($agent->footer_team_name);
                    }
                    break;
                }
            }

            $lastAgentReply = DB::table('ticket_reply as tr')
                ->where('tr.ticket_id', $ticket['id'])
                ->where('tr.admin_id', $ticket['customer_id'])
                ->orderByDesc('tr.created_at')
                ->select('tr.created_at')
                ->first();

            $lastAgentResponseTime = $lastAgentReply && !empty($lastAgentReply->created_at)
                ? \Carbon\Carbon::parse($lastAgentReply->created_at)->format('d M Y, h:i A')
                : 'Not responded yet';

            $V_Replace['customer_name'] = $customer_name;
            $V_Replace['ticket_title'] = $ticket_title;
            $V_Replace['ticket_id'] = $ticket['ticket_id'];
            $V_Replace['support_team_name'] = $footer_team_name;
            $formattedHoldDate = \Carbon\Carbon::parse($ticket['on_hold_at'])->format('d M Y, h:i A');
            $V_Replace['hold_since'] = $formattedHoldDate;
            $V_Replace['last_response_time'] = $lastAgentResponseTime;

            if (!$templateRow) return;

            $activeTemplate = CryptService::decryptData($templateRow->template);
            $subjectTemplate = CryptService::decryptData($templateRow->subject);

            // Use one loop to replace placeholders like {client_name}, {ticket_id}, etc.
            foreach ($V_Replace as $key => $value) {
                $subjectTemplate = str_replace('{' . $key . '}', $value, $subjectTemplate);
                $activeTemplate = str_replace('{' . $key . '}', $value, $activeTemplate);
            }

            $subject = $subjectTemplate;
            $message = $activeTemplate;

            // $EmailCurl->SendNotificationM($customer_email, $message, $subject);
            $EmailCurl->sendEmailWithAgentSMTP($ticket['id'],$subject,$message,[$customer_email]);
        }
    }
}
