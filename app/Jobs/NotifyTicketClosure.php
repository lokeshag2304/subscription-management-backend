<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Lib\EmailCurl;
use Illuminate\Support\Facades\DB;
use App\Services\CryptService;

class NotifyTicketClosure implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected $ticket;
    protected $templateId;

    public function __construct(array $ticket, int $templateId)
    {
        $this->ticket = $ticket;
        $this->templateId = $templateId;
    }

    public function handle()
    {
        $ticket = $this->ticket;
        $EmailCurl = new EmailCurl();

        $templateRow = DB::table("templates")->where("id", $this->templateId)->first();
        if (!$templateRow) return;

        $activeTemplate = CryptService::decryptData($templateRow->template);
        $subjectTemplate = CryptService::decryptData($templateRow->subject);

        $customerData = DB::table("superadmins")->where("id", $ticket['customer_id'])->first();
        if (!$customerData) return;
         $customer_name = CryptService::decryptData($customerData->name);
            $customer_email = CryptService::decryptData($customerData->email);
            $ticket_title = CryptService::decryptData($ticket['subject']);
            $assignedAgentIds = DB::table('agent_assign_history')
            ->where('ticket_id', $ticket['id'])
            ->pluck('agent_id')
            ->toArray();
            $footer_team_name = null;
            if (!empty($assignedAgentIds)) {
                $agent = DB::table('superadmins')
                    ->where('id', $assignedAgentIds)
                    // ->where('root_access', 1)
                    ->first();

                // foreach ($agentsWithAccess as $agent) {
                    if (!empty($agent->footer_team_name)) {
                        $footer_team_name = CryptService::decryptData($agent->footer_team_name);
                    }
                    // break;
                // }
            }

            

            $V_Replace['customer_name'] = $customer_name;
            $V_Replace['ticket_title'] = $ticket_title;
            $V_Replace['ticket_id'] = $ticket['ticket_id'];
            $V_Replace['support_team_name'] = $footer_team_name;
            $formattedHoldDate = \Carbon\Carbon::parse($ticket['closure_date'])->format('d M Y, h:i A');
            $V_Replace['closure_date'] = $formattedHoldDate;
            

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
