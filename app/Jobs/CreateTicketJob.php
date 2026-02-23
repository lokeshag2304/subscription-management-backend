<?php

namespace App\Jobs;

use App\Services\CryptService;
use App\Services\CustomCipherService;
use App\Services\PusherTicketNotifier;
use App\Services\WasabiService;
use App\Services\AgentActivityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Exception;

class CreateTicketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $request;
     protected $data;
     public $files;
    //  public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(array $data,array $files)
    {
        // $this->request = $request;
        $this->data = $data;
        $this->files = $files;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // $request = $this->request;
        $request = (object) $this->data;
        try {
            $validator = Validator::make($this->data, [
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed for AddTicketJob', [
                'errors' => $validator->errors()->toArray(),
            ]);
            return;
        }
        // echo 'hi';exit;

            if (!empty($request->message_id)) {
                $exists = DB::table('tickets')
                    ->where('message_id', $request->message_id)
                    ->where('subadmin_id', $request->subadmin_id)
                    ->first();
            }

            $cleanSubject = preg_replace('/^(Re:\s*)+/i', '', $request->subject);
            $encryptedSubject = CryptService::encryptData($cleanSubject);
            $encryptedDescription = CryptService::encryptData($request->description);
            $SencryptedSubject = CustomCipherService::encryptData($cleanSubject);
            $SencryptedDescription = CustomCipherService::encryptData($request->description);

            $ticket = null;
            $automation_agents = [];

            if (!empty($request->conversationId)) {
                $ticket = DB::table('tickets')
                    ->where('conversationId', $request->conversationId)
                    ->where('subadmin_id', $request->subadmin_id)
                    ->first();
            }

            Log::info('cleanSubject: ' . $cleanSubject);
            Log::info('ticket: ', (array) $ticket);

            // ✅ If ticket exists → reply logic
            if (!empty($ticket)) {
                // $cleanHtml = app('App\Http\Controllers\Controller')->extractNewReplyContent($request->description);
                $cleanHtml = app(\App\Http\Controllers\TicketController::class)
            ->extractNewReplyContent($request->description);

                $fclearHtml = CryptService::encryptData($cleanHtml);
                if (!empty($encryptedDescription)) {
                    $ticket_reply = [
                        'ticket_reply' => $fclearHtml,
                        'admin_id' => $request->admin_id,
                        'created_at' => Carbon::now(),
                        'ticket_id' => $ticket->id,
                        'status' => 1
                    ];
                    if (!empty($request->attachments_string)) {
                        $ticket_reply['attachments'] = $request->attachments_string;
                    }
                    if (!empty($request->message_id)) {
                        $ticket_reply['message_id'] = $request->message_id;
                    }
                    if (!empty($request->internetMessageId)) {
                        $ticket_reply['internetMessageId'] = $request->internetMessageId;
                    }
                    if (!empty($request->reply_from)) {
                        $ticket_reply['replied_from'] = $request->reply_from;
                    }
                    if (!empty($request->reply_to)) {
                        $ticket_reply['replied_to'] = $request->reply_to;
                    }
                    if (!empty($request->reply_cc)) {
                        $ticket_reply['replied_cc'] = $request->reply_cc;
                    }
                    if (!empty($request->reply_bcc)) {
                        $ticket_reply['replied_bcc'] = $request->reply_bcc;
                    }

                    Log::info('ticket_reply: ', $ticket_reply);
                    DB::table('ticket_reply')->insert($ticket_reply);

                    if ($ticket->status == 4 || $ticket->status == 3) {
                        DB::table('tickets')
                            ->where('id', $ticket->id)
                            ->update(['status' => 1]);
                    }

                    PusherTicketNotifier::notifyAssignedAgents($ticket->id);
                }

                $activityMessage = 'Client replied via email: ' . $cleanHtml;
                DB::table('activities')->insert([
                    'action' => CryptService::encryptData('Client Replied via email'),
                    'user_id' => $ticket->customer_id,
                    'message' => CryptService::encryptData($activityMessage),
                    'ticket_id' => $ticket->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            // ✅ New Ticket Creation
            $admin = DB::table('superadmins')->where('id', $request->admin_id)->first();

            DB::beginTransaction();

            try {
                               $prefixSetting = DB::table('setting')
                                ->where('subadmin_id', $request->subadmin_id)
                                ->first();
                $prefix = $prefixSetting ? $prefixSetting->ticket_prefix : '00000'; // Default prefix

                // Get latest ticket_id (assumes ticket_id is sorted properly as string)
                $lastTicket = DB::table('tickets')
                    ->where('ticket_prefix', $prefix)
                    ->where('subadmin_id', $request->subadmin_id)
                    ->orderByDesc('ticket_id')
                    ->value('ticket_id');

                if ($lastTicket) {
                    $lastNumber = (int)$lastTicket; // Convert to int, e.g., "00054" -> 54
                } else {
                    $lastNumber = 0;
                }

                $newNumber = $lastNumber + 1;
                $formattedTicketId = str_pad($newNumber, 5, '0', STR_PAD_LEFT); // e.g., "00055"

                $existingTicket = DB::table('tickets')->where('ticket_id', $formattedTicketId)->where('subadmin_id', $request->subadmin_id)->first();
                if ($existingTicket) {
                    throw new \Exception('Ticket ID already exists');
                }

                // ✅ Encrypt subject & description
                
                 $insertData = [];
                 $agents = [];
                 

                if (!empty($request->sender)) {
                    $encodeSender = CryptService::encryptData($request->sender);
                    // echo $encodeSender;exit;
                    $ticket_routing = DB::table('ticket_routing')->where('subadmin_id', $request->subadmin_id)->where('ticket_email', $encodeSender)->first();
                    if ($ticket_routing && !empty($ticket_routing->ticket_agents_id)) {
                        $agentIds = json_decode($ticket_routing->ticket_agents_id, true);
                        if (is_array($agentIds)) {
                            $agents = array_merge($agents, $agentIds);
                            $automation_agents = $agents;
                        }
                    } 
                }  else {
                    if (!empty($request->customer_id)) {
                        $customer = DB::table('superadmins')->where('id', $request->customer_id)->first();
                        if ($customer) {
                            $customerEmail = CryptService::decryptData($customer->email);
                            $encodeSender = CryptService::encryptData($customerEmail);

                            $ticket_routing = DB::table('ticket_routing')->where('ticket_email', $encodeSender)->first();
                            if ($ticket_routing && !empty($ticket_routing->ticket_agents_id)) {
                                $agentIds = json_decode($ticket_routing->ticket_agents_id, true);
                                if (is_array($agentIds)) {
                                    $agents = array_merge($agents, $agentIds);
                                    $automation_agents = $agents;
                                }
                            }
                        }
                    }
                }
               
                
                
                $ticket_mail_target = '';
                if (!empty($request->admin_id)) {
                    if($admin->login_type==1 || $admin->login_type==4){
                        $insertData['customer_id'] = $request->customer_id;
                        if(!empty($request->agent_id)){
                            $insertData['assigned_agent_id'] = $request->agent_id;
                            $agents[] = $request->agent_id;
                        }
                        $ticket_mail_target = $request->customer_id;
                    }
                    else if($admin->login_type==2){
                    $insertData['customer_id'] = $request->customer_id;
                        $insertData['assigned_agent_id'] = $request->admin_id; 
                        $agents[] = $request->admin_id;
                        $ticket_mail_target = $request->customer_id;
                    }
                    else if($admin->login_type==3){
                    //    $insertData['customer_id'] = $request->customer_id;
                        $insertData['customer_id'] = $request->admin_id; 
                        if (!empty($request->auto_suggent_agent)) {
                            $insertData['assigned_agent_id'] = $request->auto_suggent_agent; 
                            $agents[] = $request->auto_suggent_agent;
                        }else {
                          $insertData['assigned_agent_id'] = $request->agent_id; 
                          $agents[] = $request->agent_id;  
                        }
                        $ticket_mail_target = $request->admin_id;
                    }
                }
                
                if (!empty($request->admin_id)) {
                    $insertData['created_by'] = $request->admin_id;
                }
                if (!empty($request->subadmin_id)) {
                    $insertData['subadmin_id'] = $request->subadmin_id;
                }
                if (!empty($encryptedSubject)) {
                    $insertData['subject'] = $encryptedSubject;
                    $insertData['s_subject'] = $SencryptedSubject;
                }
                if (!empty($encryptedDescription)) {
                    $insertData['description'] = $encryptedDescription;
                    $insertData['s_description'] = $SencryptedDescription;
                }
                if (!empty($formattedTicketId)) {
                    $insertData['ticket_id'] = $formattedTicketId;
                }
                if (!empty($prefix)) {
                    $insertData['ticket_prefix'] = $prefix;
                }
                if(!empty($request->message_id)){ 
                    $insertData['message_id'] = $request->message_id;
                }
                if(!empty($request->internetMessageId)){ 
                    $insertData['internal_message_id'] = $request->internetMessageId;
                }
                if(!empty($request->conversationId)){ 
                    $insertData['conversationId'] = $request->conversationId;
                }
                    $insertData['status'] = 1;
                if (!empty($request->priority)) {
                    $insertData['priority'] = $request->priority;
                }else {
                    $insertData['priority'] = 'medium';
                }
                if (!empty($request->ticket_date_time)) {
                    $insertData['created_at'] = Carbon::parse($request->ticket_date_time)
                        ->timezone(config('app.timezone')); // Converts UTC to local timezone
                } else {
                    $insertData['created_at'] = Carbon::now();
                }
                $insertData['added_at'] = Carbon::now();
                $insertData['updated_at'] = Carbon::now();

                $insertedId = null;

                if (!empty($insertData)) {
                    $insertedId = DB::table('tickets')->insertGetId($insertData);
                }

               $Unique_agents = array_unique($agents);
               $encrypted_sender = '';
                if (!empty($request->sender)) {
                    $encrypted_sender = CryptService::encryptData($request->sender);
                }else {
                    $customer = DB::table('superadmins')->where('id', $request->customer_id)->first();
                    $encrypted_sender = $customer->email;
                }

                $customerData = DB::table('superadmins')->where('id', $insertData['customer_id'])->first();
                $customer_name = CryptService::decryptData($customerData->name);
                // $any_sla_for_apply = DB::table('sla_targets')
                //     ->where('email', $encrypted_sender)
                //     ->first();

                $all_slas = DB::table('sla_targets')
                    ->join('sla', 'sla.id', '=', 'sla_targets.sla_id')
                    ->where('sla_targets.email', $encrypted_sender)
                    ->where("sla.subadmin_id",$request->subadmin_id)
                    ->select('sla_targets.*', 'sla.priority') // Include priority in result
                    ->get();
                    $any_sla_for_apply = $all_slas->firstWhere('priority', $insertData['priority']);

                $agent_names = '';
                $footer_team_name = null;
                $footer_email = null;
               if($admin->login_type==2){
                    $insertData['assigned_agent_id'] = $request->admin_id; 
                    $agentData = DB::table('superadmins')
                                ->where('id', $request->admin_id)
                                ->first();
                        $footer_team_name = $agentData->footer_team_name
                                        ? CryptService::decryptData($agentData->footer_team_name)
                                        : null;

                        $footer_email = $agentData->footer_email
                                        ? CryptService::decryptData($agentData->footer_email)
                                        : null;

                        $agent_names = CryptService::decryptData($agentData->name);
                    }
                    else {

                        if (!empty($Unique_agents)) {
                            $agentsList = DB::table('superadmins')
                                ->whereIn('id', $Unique_agents)
                                ->get();

                            $decrypted_names = [];

                            foreach ($agentsList as $agent) {
                                $decrypted_names[] = CryptService::decryptData($agent->name);

                                $matchesAssignedAgent = (
                                    (!empty($request->agent_id) && $request->agent_id == $agent->id) ||
                                    (!empty($request->auto_suggent_agent) && $request->auto_suggent_agent == $agent->id)
                                );

                                if (
                                    $agent->root_access == 1 &&
                                    $matchesAssignedAgent &&
                                    $footer_team_name === null &&
                                    $footer_email === null
                                ) {
                                    $footer_team_name = $agent->footer_team_name
                                        ? CryptService::decryptData($agent->footer_team_name)
                                        : null;

                                    $footer_email = $agent->footer_email
                                        ? CryptService::decryptData($agent->footer_email)
                                        : null;
                                }
                            }

                            $agent_names = implode(' & ', $decrypted_names);
                        }
                }


                if (!empty($any_sla_for_apply)) {
                    $sladata = DB::table("sla")->where("id",$any_sla_for_apply->sla_id)->first();
                    $now = Carbon::now('Asia/Kolkata');

                    $responseMinutes = (int) $sladata->response_time_minute;
                    $resolutionMinutes = (int) $sladata->resolution_time_minute;

                    $sla_response_deadline = $now->copy()->addMinutes($responseMinutes)->format('d M Y h:i A');
                    $sla_resolution_deadline = $now->copy()->addMinutes($resolutionMinutes)->format('d M Y h:i A');

                    $response_breach_time = $sla_response_deadline;
                    $resolution_breach_time = $sla_resolution_deadline;

                    // Ensure created_at is also in IST
                    $formattedCreationDate = Carbon::parse($insertData['created_at'])
                        ->timezone('Asia/Kolkata')
                        ->format('d M Y h:i A');

                     $ticket_info = [
                        "ticket_id" => $formattedTicketId,
                        "agent_name" => $agent_names,
                        'customer_name'=>$customer_name,
                        'creation_date'=>$formattedCreationDate,
                        'sla_response_deadline' => $sla_response_deadline,
                        'sla_resolution_deadline' => $sla_resolution_deadline,
                        'response_breach_time' => $response_breach_time,
                        'resolution_breach_time' => $resolution_breach_time,
                        
                    ];
                        $slaRoles = DB::table('sla_roles')
                            ->where('sla_id', $any_sla_for_apply->sla_id)
                            ->get();

                        foreach ($slaRoles as $role) {
                            $roleName = CryptService::decryptData($role->role_name); // e.g., "Project Manager"
                            $formattedRoleKey = str_replace(' ', '_', strtolower($roleName)); // e.g., "project_manager"

                            $agent = DB::table('superadmins')->where('id', $role->agent_id)->first();

                            if ($agent) {
                                $ticket_info[$formattedRoleKey . '_name'] = CryptService::decryptData($agent->name);
                            }

                        }
                        

                    $activeSlaId = DB::table('active_slas')->insertGetId([
                        'ticket_id'       => $insertedId,
                        'subadmin_id'     => $request->subadmin_id ?? null,
                        'sla_id'          => $any_sla_for_apply->sla_id,
                        'ticket_added_at' => $now,
                        'sla_applied_at' =>$now,
                        'status'          => 1,
                        'ticket_info'     => json_encode($ticket_info)
                    ]);

                    // Now update tickets table with this active SLA ID
                    DB::table('tickets')
                        ->where('id', $insertedId)
                        ->update(['active_sla_id' => $activeSlaId,'first_sla_applied_date'=>$now]);

                }

               


                   $repliableAgentIds = [];
                    $closableAgentIds = [];
                    if (!empty($request->sender)) {
                        $encodeSender = CryptService::encryptData($request->sender);
                        $ticketRouting = DB::table('ticket_routing')
                            ->where('ticket_email', $encodeSender)
                            ->where('subadmin_id', $request->subadmin_id)
                            ->first();
                            if ($ticketRouting) {
                                DB::table('ticket_routing')
                                    ->where('id', $ticketRouting->id)
                                    ->update([
                                        'customer_id' => $request->admin_id,
                                    ]);
                            }

                    
                    }else {
                         if (!empty($request->customer_id)) {
                          $customer = DB::table('superadmins')->where('id', $request->customer_id)->first();
                         $encodeSender = $customer->email;
                        $ticketRouting = DB::table('ticket_routing')
                            ->where('ticket_email', $encodeSender)
                            ->where('subadmin_id', $request->subadmin_id)
                            ->first();
                            if ($ticketRouting) {
                                DB::table('ticket_routing')
                                    ->where('id', $ticketRouting->id)
                                    ->update([
                                        'customer_id' => $request->customer_id,
                                    ]);
                            }
                        }
                    }

                foreach ($Unique_agents as $agentId) {
                    // $canReply = in_array($agentId, $repliableAgentIds) ? 1 : 0;
                    // $canClose = in_array($agentId, $closableAgentIds) ? 1 : 0;

                    DB::table('agent_assign_history')->insert([
                        'ticket_id' => $insertedId,
                        'agent_id' => $agentId,
                        // 'assigned_by' => $request->admin_id ?? null,
                        'assigned_at' => now(),
                        // 'can_reply' => $canReply,
                        // 'can_close' => $canClose,
                    ]);
                }

                $attachments = [];
                
                // $wasabiService = app(WasabiService::class);
                // \Log::info('WasabiService constructor called with subadminId:', ['subadminId' => $subadminId]);
                $wasabiService = app(WasabiService::class, ['subadminId' => $request->subadmin_id]);
                if (!empty($this->files)) {
                    

                    foreach ($this->files as $doc) {
                        if ($doc->isValid()) {
                            $originalName = $doc->getClientOriginalName();

                            // Upload to Wasabi
                            $wasabiPath = 'Ticket/attachments'; // S3 path in bucket
                            $url = $wasabiService->uploadFile($wasabiPath, $doc); // Should return full public URL

                            $attachments[] = [
                                'file_name' => $originalName,
                                'url' => $url
                            ];
                        }
                    }

                    $ticket_reply['attachments'] = json_encode($attachments);
                }

                if(!empty($request->attachments_string)){
                    $ticket_reply['attachments'] = $request->attachments_string;  
                }

                if (!empty($encryptedDescription)) {
                    $ticket_reply['ticket_reply'] = $encryptedDescription;
                    $ticket_reply['admin_id'] = $request->admin_id;
                    $ticket_reply['created_at'] = Carbon::now();
                    $ticket_reply['ticket_id'] = $insertedId;
                    $ticket_reply['status'] = 0;
                    if (!empty($request->reply_from)) {
                        $ticket_reply['replied_from'] = $request->reply_from;
                    }
                    if (!empty($request->reply_to)) {
                        $ticket_reply['replied_to'] = $request->reply_to;
                    }
                    if (!empty($request->reply_cc)) {
                        $ticket_reply['replied_cc'] = $request->reply_cc;
                    }
                    if (!empty($request->reply_bcc)) {
                        $ticket_reply['replied_bcc'] = $request->reply_bcc;
                    }
                    $insertedId3 = DB::table('ticket_reply')->insertGetId($ticket_reply);
                }
                    Log::info("✉️ mailcutomerid: $ticket_mail_target");
            
                        // echo $ticket_mail_target;exit;
                    $ticketMailData = DB::table('superadmins')->where('id', $ticket_mail_target)->first();
                     $ticketMailData_name = CryptService::decryptData($ticketMailData->name);
                    $ticketMailData_email = CryptService::decryptData($ticketMailData->email);
                    // dd($ticketMailData);
                    $EmailCurl = new \App\Lib\EmailCurl();
                    // if (!$request->has('notify_customer') || $request->notify_customer == 1) {
                   
                    $subect = "Your Support Ticket Has Been Received #{$formattedTicketId}"; 
                    $subadminData = DB::table('superadmins')->where('id', $request->subadmin_id)->first();
 
                        $data = [
                            'name' => $ticketMailData_name,
                            'ticket_id' => $formattedTicketId,
                            'footer_team_name' => $footer_team_name, 
                            'footer_email' => $footer_email,
                            'companyName' => CryptService::decryptData($subadminData->company_name),
                            'companyLogo' => asset($prefixSetting->logo),
                        ];

                        // if ($request->is_customer_new == 1) {
                        //     $data['is_customer_new'] = true;
                        //     $data['email'] = $request->customer_username;
                        //     $data['password'] = $request->customer_password;
                        //     $data['login_url'] = 'https://stardesk.co.in/auth/sign-in';
                        // }

                        // Render HTML from blade
                        $body = view('emails.ticket_creation_notification', $data)->render();

                        $EmailCurl->sendEmailWithAgentSMTP($insertedId,$subect,$body,[$ticketMailData_email]);
                        // $EmailCurl->SendNotification_2($ticketMailData_email,$body,$subect);
                        // }
                        
                        if (is_array($automation_agents) && count($automation_agents) > 0) {
                        $this->agentAutoAssign($formattedTicketId,$cleanSubject,$insertData['created_at'],$ticketMailData_name,$automation_agents,$insertedId,$request->subadmin_id);
                        }
                        $extraAgents = [6, 291];
                        $allAgents = array_unique(array_merge($Unique_agents, $extraAgents));

                        foreach ($allAgents as $agentId) {
                            AgentActivityService::notify($agentId, [
                                'event_type' => 'new_ticket',
                                'ticket_id' => $insertedId,
                                'tags'=>$allAgents,
                                'message' => 'New ticket assigned to you',
                            ]);
                        }


                    // ✅ Insert into activities table
                $adminName = CryptService::decryptData($admin->name);
                $action = CryptService::encryptData("New Ticket Raised");
                $sActionEncrypted = CustomCipherService::encryptData("New Ticket Raised");
                $message = CryptService::encryptData("New ticket ($formattedTicketId) created for Customer ID " . $request->admin_id);
                $messageNew = CustomCipherService::encryptData("New ticket ($formattedTicketId) created for Customer ID " . $request->admin_id);

                // Correct way - encrypt the complete message including ticket ID
                $notificationMessage = "New support ticket raised successfully by $adminName & ticket id is #$formattedTicketId";
                //    echo $notificationMessage;exit;

                $notificationMessageEncrypted = CryptService::encryptData($notificationMessage);
                // echo $notificationMessageEncrypted;exit;
                $ssnotificationMessage = "New support ticket raised successfully by $adminName & ticket id is #$formattedTicketId";
                
                $sNotificationMessageEncrypted = CustomCipherService::encryptData($ssnotificationMessage);
                // echo $sNotificationMessageEncrypted;exit;
                    $details = CryptService::encryptData(json_encode([
                        'ticket_id' => $formattedTicketId,
                        'customer_id' => $request->admin_id,
                        // 'agent_id' => $request->agent_id,
                        'admin_id' => $request->admin_id,
                    ]));
                    
                        DB::table('activities')->insert([
                            'action' => $action,
                            's_action' => $sActionEncrypted,
                            'message' => $message,
                            's_message' => $messageNew,
                            'user_id' => $request->admin_id,
                            'ticket_id'=>$insertedId,
                            'details' => $details,
                            'created_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
                            'updated_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
                        ]);
                    

                        foreach ($allAgents as $agentId) {
                            DB::table('notifications')->insert([
                                'title' => $notificationMessageEncrypted,
                                's_title' => $sNotificationMessageEncrypted,
                                'customer_id' => $request->admin_id,
                                'agent_id' => $agentId,
                                'reffrence_id' => $insertedId,
                                'created_at' => Carbon::now()->setTimezone('Asia/Kolkata'),]);
                        }

                DB::commit();

                
            } catch (Exception $e) {
                DB::rollBack();
                // Log::error('AddTicketJob: Ticket creation failed', ['error' => $e->getMessage()]);
                Log::error('CreateTicketJob failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('AddTicketJob failed: ' . $e->getMessage());
        }
    }
}
