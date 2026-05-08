<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Carbon\Carbon;
use App\Services\CryptService;
use Illuminate\Support\Facades\Log;
use App\Services\CustomCipherService;


class ProcessSlaNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $sla;

    public function __construct($sla)
    {
        $this->sla = $sla;
    }

    public function handle()
    {
        
        Log::info('🔁 API called: JOb Handled at ' . now());
        // echo 'hi';exit;
        $as = (object) $this->sla;
        Log::info('SLA Object: ' . json_encode($as));
        Log::info('🔁 Upcoming level ' . $as->upcoming_level_id);
        $level_type = ($as->has_responded == 0) ? 0 : 2;
        $createdAt = Carbon::parse($as->ticket_added_at);
        $now = Carbon::now();
        $minutesPassed = $createdAt->diffInMinutes($now);
        // $executedLevels = explode(',', $as->executed_levels ?? '');

        $executedLevels = array_filter(explode(',', $as->executed_levels ?? ''));

        // Step 2: Add the current upcoming_level_id (if it exists)
        if (!empty($as->upcoming_level_id)) {
            $executedLevels[] = $as->upcoming_level_id;
        }

        // Step 3: Remove duplicates and reindex
        $executedLevels = array_values(array_unique($executedLevels));

        $query = DB::table('sla_level')
            ->where('sla_id', $as->sla_id)
            ->when($level_type != 0, function ($query) use ($level_type) {
                return $query->where('type', $level_type);
            })
             ->when(!empty($executedLevels), function ($query) use ($executedLevels) {
                return $query->whereNotIn('id', $executedLevels);
            })
            ->where('final_trigger_time', '>=', $minutesPassed)
            ->orderBy('final_trigger_time', 'asc');

        // Clone before execution
        $clone = clone $query;

        // Log the SQL with bindings
        Log::info('Nearest SLA Level SQL: ' . vsprintf(str_replace('?', '%s', $clone->toSql()), collect($clone->getBindings())->map(function ($binding) {
            return is_numeric($binding) ? $binding : "'$binding'";
        })->toArray()));

        $nearestSlaLevel = $query->first();

        if ($nearestSlaLevel) {     
            Log::info('🔁 i got nearest level ' . now());
         
            $mailReceiverIds = is_array($nearestSlaLevel->mail_reciever)
                ? $nearestSlaLevel->mail_reciever
                : (!empty($nearestSlaLevel->mail_reciever) ? json_decode($nearestSlaLevel->mail_reciever, true) : []);

            $ccReceiverIds = is_array($nearestSlaLevel->cc_emails)
                ? $nearestSlaLevel->cc_emails
                : (!empty($nearestSlaLevel->cc_emails) ? json_decode($nearestSlaLevel->cc_emails, true) : []);

            $bccReceiverIds = is_array($nearestSlaLevel->bcc_emails)
                ? $nearestSlaLevel->bcc_emails
                : (!empty($nearestSlaLevel->bcc_emails) ? json_decode($nearestSlaLevel->bcc_emails, true) : []);

            // Step 2: Merge and get unique IDs (only if non-empty)
            $allAgentIds = collect(array_merge($mailReceiverIds, $ccReceiverIds, $bccReceiverIds))
                ->filter() // removes null/empty values
                ->unique()
                ->values();

            $toEmails = $toNames = $ccEmails = $ccNames = $bccEmails = $bccNames = [];

            if ($allAgentIds->isNotEmpty()) {
                // Step 3: Query all agents once
                $agents = DB::table('superadmins')
                    ->whereIn('id', $allAgentIds)
                    ->get(['id', 'name', 'email']);

                // Step 4: Distribute names and emails based on group
                foreach ($agents as $agent) {
                    $decryptedEmail = CryptService::decryptData($agent->email);
                    $decryptedName = CryptService::decryptData($agent->name);

                    if (in_array($agent->id, $mailReceiverIds)) {
                        $toEmails[] = $decryptedEmail;
                        $toNames[] = $decryptedName;
                    }

                    if (in_array($agent->id, $ccReceiverIds)) {
                        $ccEmails[] = $decryptedEmail;
                        $ccNames[] = $decryptedName;
                    }

                    if (in_array($agent->id, $bccReceiverIds)) {
                        $bccEmails[] = $decryptedEmail;
                        $bccNames[] = $decryptedName;
                    }
                }
            }

            // Step 5: Comma-separated strings (even if empty)
            $commaSeparatedToEmails = implode(',', $toEmails);
            $commaSeparatedToNames  = implode(', ', $toNames);

            $commaSeparatedCcEmails = implode(',', $ccEmails);
            $commaSeparatedCcNames  = implode(', ', $ccNames);

            $commaSeparatedBccEmails = implode(',', $bccEmails);
            $commaSeparatedBccNames  = implode(', ', $bccNames);



           DB::table('active_slas')
            ->where('id', $as->id)
            ->update([
                'upcoming_noti_time' => $createdAt->addMinutes($nearestSlaLevel->final_trigger_time),
                'audience' => $commaSeparatedToEmails,
                'audience_name' => $commaSeparatedToNames,
                'audience_cc' => $commaSeparatedCcEmails,
                'audience_cc_name' => $commaSeparatedCcNames,
                'audience_bcc' => $commaSeparatedBccEmails,
                'audience_bcc_name' => $commaSeparatedBccNames,
                'upcoming_template_id' => $nearestSlaLevel->template_id,
                'upcoming_level_id' => $nearestSlaLevel->id,
                'upcoming_level_type' => $nearestSlaLevel->type,
                'check_in_depth' => 0
            ]);

        }else {
             DB::table('active_slas')
                ->where('id', $as->id)
                ->update([
                    'upcoming_noti_time' => '',
                    // 'audience' => $commaSeparatedEmails,
                    // 'upcoming_template_id' => $nearestSlaLevel->template_id,
                    // 'upcoming_level_id' => $nearestSlaLevel->id,
                    // 'upcoming_level_type' => $nearestSlaLevel->type,
                    'check_in_depth' => 0,
                    'has_sla_done' => 1
                ]);
        }

        if ($as->check_in_depth != 1) {
            
            if(!empty($as->audience)){
                Log::info('🔁 entred in mail system ' . $as->subadmin_id);
                $to = explode(',', $as->audience); 
                //  $to = array("gopalsh022@gmail.com"); 
                $EmailCurl = new \App\Lib\EmailCurl($as->subadmin_id);
                $template = DB::table("templates")->where("id",$as->upcoming_template_id)->first();
                $ticket = DB::table('tickets')->where("id",$as->ticket_id)->first();
                $status = DB::table('status')
                ->where('id', $ticket->status)
                ->where("subadmin_id",$as->subadmin_id)
                ->value('name'); // directly get the name

                // $agentsList = DB::table('superadmins')
                //         ->whereIn('id', $Unique_agents)
                //         ->get();

                //     $decrypted_names = [];
                //     foreach ($agentsList as $agent) {
                //         $decrypted_names[] = CryptService::decryptData($agent->name);
                //     }

                //     $agent_names = implode(' & ', $decrypted_names);
                $activeTemplate = CryptService::decryptData($template->template);
                $subjectTemplate = CryptService::decryptData($template->subject);
                $ticket_info = json_decode($as->ticket_info, true); // converts to associative array
                $placeholders = [];
                $values = [];
                $rawNames = explode(',', $as->audience_name);
                 $cc_name = !empty($as->audience_cc) ? array_map('trim', explode(',', $as->audience_cc)) : [];

                // Prepare BCC
                $bcc_name = !empty($as->audience_bcc) ? array_map('trim', explode(',', $as->audience_bcc)) : [];

                // Clean and trim each name
                $names = array_map('trim', $rawNames);

                if (count($names) > 1) {
                    $lastName = array_pop($names);
                    $formattedAudienceName = implode(', ', $names) . ' & ' . $lastName;
                } else {
                    $formattedAudienceName = $names[0] ?? '';
                }

                // Add to ticket_info
                $ticket_info['name'] = $formattedAudienceName;
                $ticket_info['status'] = $status;
                $ticket_info['ticket_title'] = CryptService::decryptData($ticket->subject);
                foreach ($ticket_info as $key => $value) {
                    $placeholders[] = '{' . $key . '}';
                    $values[] = $value;
                }
                $subject = str_replace($placeholders, $values, $subjectTemplate);

                $finalMessage = str_replace($placeholders, $values, $activeTemplate);
                // $EmailCurl->SendNotificationM($to,$finalMessage,$subject, 0,null,['cc1@example.com', 'cc2@example.com'],['bcc1@example.com']);
                $cc = !empty($as->audience_cc) ? explode(',', $as->audience_cc) : [];
                $bcc = !empty($as->audience_bcc) ? explode(',', $as->audience_bcc) : [];
                $EmailCurl->SendNotificationMForSLA($to, $finalMessage, $subject, 0, null, $cc, $bcc,$names,$cc_name,$bcc_name);

            }
                    $newExecutedLevels = $executedLevels;

                DB::table('active_slas')
                    ->where('id', $as->id)
                    ->update([
                        'executed_levels' => implode(',', $newExecutedLevels)
                    ]);

                DB::table('active_sla_details')->insert([
                    'sla_id'      => $as->id,
                    'notifiy_to'  => CryptService::encryptData($as->audience), // Assuming it's already encrypted
                    'level_id'    => $as->upcoming_level_id, // use appropriate level id variable
                    'template_id' => $as->upcoming_template_id,
                    'sent_at'     => now(),
                ]);
               
               if ($as->upcoming_level_type == 1) {
                    $actionLabel = 'Response Escalation';
                } elseif ($as->upcoming_level_type == 2) {
                    $actionLabel = 'Resolution Escalation';
                } else {
                    $actionLabel = 'SLA Escalation'; // fallback
                }
               $activityMessage = 'SLA notification sent to audience: ' . $as->audience;
                DB::table('activities')->insert([
                    'action' => CryptService::encryptData($actionLabel),
                    's_action' => CustomCipherService::encryptData($actionLabel),
                    'user_id' => $as->subadmin_id,

                    'message' => CryptService::encryptData($activityMessage),
                    's_message' => CustomCipherService::encryptData($activityMessage),
                    'ticket_id' => $as->ticket_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

        }
 
    }
}
