<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use App\Services\CryptService;
use Illuminate\Support\Facades\Crypt;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Transport;
use Illuminate\Support\Facades\Log;
use App\Services\GoogleServices;
use App\Helpers\WebSocketHelper;
use App\Services\AgentActivityService;


class GoogleWebhookController extends Controller
{
    private function extractEmailFromResource($resource)
    {
        // Matches the email address after /users/ and before /mailFolders
        preg_match("/\/users\/([^\/]+)\/mailFolders/", $resource, $matches);
        return $matches[1] ?? null;
    }

    public function handleRedirect(Request $request)
    {
        $code = $request->query('code');

        $state = $request->query('state');

        // ✅ Decode state
        $decodedState = json_decode(urldecode($state), true);
        $user_id = $decodedState['user_id'] ?? null;
        $type = $decodedState['type'] ?? null;
        $admin_id = $decodedState['admin_id'] ?? null;

        if (!$code) {
            return response()->json(['error' => 'Authorization code not found'], 400);
        }

        $service = new GoogleServices();
        $result = $service->exchangeAuthorizationCode($code,$user_id,$type);
        // print_r($result);exit;

        if ($result['success']) {
            // return response()->json($result['tokens']);
            $agent_data = DB::table("superadmins")->where("id",$user_id)->first();
            $creator_data = DB::table("superadmins")->where("id",$admin_id)->first();
            if(!empty($agent_data->subadmin_id)){
                $company_info = DB::table("superadmins")->where("id",$agent_data->subadmin_id)->first();
                $service = new GoogleServices($agent_data);
                // $service->createSubscription($result['email']);
                $service->setTopicIamPolicy();
                $service->watchMailbox(); 
                // $decryptedDomain = CryptService::DecryptData($company_info->company_domain);
                // return redirect("https://${decryptedDomain}/SuperAdmin/agent-management");
                if($creator_data->login_type==4){
                    return redirect("https://stardesk.co.in/SubAdmin/agent-management");
                }else {
                    return redirect("https://stardesk.co.in/SuperAdmin/agent-management");
                }
            }
        } else {
            return response()->json([
                'error' => 'Failed to exchange code for token',
                'details' => $result['error']
            ], 500);
        }
    }

     public function deleteAllSubscriptions7890987(){
            $service = new GoogleServices();
            $response = $service->deleteAllSubscriptions();

    }

      public function deleteallsubscription(){
        
        $agent_data = DB::table('superadmins')
            // ->whereNotNull('google_access_token')
            ->where("id",4258)
            ->get();
            // echo '<pre>';print_r($agent_data);exit;
        foreach($agent_data as $ad){
            $service = new GoogleServices($ad);
            $service->unwatchMailbox(); 
        }
    }

//     public function deleteAllSubscriptions()
// {
//     try {
//         // 🔹 Fetch all superadmins whose Gmail watch will expire within 1 day
//         // and who have a valid Google token
//        $admins = DB::table('superadmins')
//             ->whereNotNull('google_access_token')
//             ->where('google_access_token', '!=', '')
//             ->where(function ($query) {
//                 $query->whereNull('gmail_watch_expiry')
//                       ->orWhere('gmail_watch_expiry', '=', '')
//                       ->orWhereDate('gmail_watch_expiry', '<=', now()->addDay());
//             })
//             ->get();

//         if ($admins->isEmpty()) {
//             Log::info('📭 No Gmail watches to renew today.');
//             return;
//         }

//         Log::info('🔁 Starting Gmail watch renewal for ' . $admins->count() . ' superadmins.');

//         foreach ($admins as $admin) {
//             try {
//                 Log::info("🧩 Renewing Gmail watch for superadmin ID: {$admin->id}");
//                 // $this->renewWatch($admin->id);
//             $googleService = new GoogleServices($admin);
//             $googleService->renewWatch($admin->id);

//             } catch (\Exception $ex) {
//                 Log::error("💥 Error renewing Gmail watch for superadmin ID {$admin->id}: " . $ex->getMessage());
//             }
//         }

//         Log::info('✅ Gmail watch auto-renew process completed.');

//     } catch (\Exception $e) {
//         Log::error('💥 Exception in autoRenewAllWatches: ' . $e->getMessage());
//     }
// }


    public function handleGmailNotification(Request $request)
    {
        Log::info("google subscription worked");
        if ($request->has('challenge')) {
            return response($request->get('challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

       $dataB64 = $request->input('message.data') ?? $request->input('data') ?? null;
    if (empty($dataB64)) {
        Log::info('🔄 Gmail webhook: no message.data - ping/keep-alive');
        return response('OK', 200);
    }

        // STEP 2: Handle actual Gmail push notifications
        $notifications = $request->input('emailAddress', []); // Gmail may send single or multiple notifications
        if (empty($notifications)) {
            $notifications = [$request->all()]; // fallback to full payload
        }

        foreach ($notifications as $notification) {
            $decodedData = json_decode(base64_decode($notification['message']['data']), true);
            $emailAddress = $decodedData['emailAddress'] ?? null;
            $historyId    = $decodedData['historyId'] ?? null;


            if (!$emailAddress || !$historyId) {
                Log::warning("⚠️ Invalid Gmail notification structure: " . json_encode($decodedData));
                continue;
            }

            // Fetch user/service info
            $agent_data = DB::table('superadmins')->where('google_email', $emailAddress)->first();

            if($agent_data->last_history_id ==$historyId){
             Log::warning("skipping this webhook becuase history id is same $historyId");   
            }
            if (!$agent_data) {
                Log::warning("⚠️ No agent found for email $emailAddress");
                continue;
            }

            // Get Gmail messages since last historyId
            $service = new GoogleServices($agent_data);
            $last_history_id = $agent_data->last_history_id??$historyId;
            $message = $service->getLatestMessageSinceHistoryId($last_history_id);

            // if ($message) {
                DB::table('superadmins')
                    ->where('id', $agent_data->id)
                    ->update(['last_history_id' => $historyId]);

                Log::info("✅ Updated last_history_id for $emailAddress to $historyId");
                 if (empty($message['id'])) {
                    Log::warning("no message found");
                    continue;
                }
                $messageId = $message['id'];
                $emailData = $service->getEmailByMessageId($messageId, $agent_data->subadmin_id);
                Log::info("✅MEssage details".json_encode($emailData));
                $internetMessageId = $emailData['internetMessageId'] ?? null;
                $conversationId    = $emailData['conversationId'] ?? null;

                $fromRaw = $emailData['from']['emailAddress']['address'] ?? null;
                // if($emailData['ticketIdHeader']){
                //     // Log::info("it's replied by agent ".$emailData['ticketIdHeader']);exit;
                //     if(!empty($messageId)){ 
                //         $insertData['message_id'] = $messageId;
                //     }
                //     if(!empty($internetMessageId)){ 
                //         $insertData['internal_message_id'] = $internetMessageId;
                //     }
                //     if(!empty($conversationId)){ 
                //         $insertData['conversationId'] = $conversationId;
                //     }
                //      $updated = DB::table('tickets')
                //         ->where('id', $emailData['ticketIdHeader'])
                //         ->update($insertData);
                //         exit;
                // }


                if (!empty($emailData['ticketIdHeader'])) {
                    $ticketId = $emailData['ticketIdHeader'];

                    // Build insert data only for non-empty values
                    if (!empty($messageId)) { 
                        $insertData['message_id'] = $messageId;
                    }
                    if (!empty($internetMessageId)) { 
                        $insertData['internal_message_id'] = $internetMessageId;
                    }
                    if (!empty($conversationId)) { 
                        $insertData['conversationId'] = $conversationId;
                    }

                    // Proceed only if we have something to update
                    if (!empty($insertData)) {
                        // ✅ Check if all 3 fields are empty in DB before updating
                        $existing = DB::table('tickets')
                            ->where('id', $ticketId)
                            ->where(function ($query) {
                                $query->whereNull('message_id')->orWhere('message_id', '');
                            })
                            ->where(function ($query) {
                                $query->whereNull('internal_message_id')->orWhere('internal_message_id', '');
                            })
                            ->where(function ($query) {
                                $query->whereNull('conversationId')->orWhere('conversationId', '');
                            })
                            ->first();

                        if ($existing) {
                            // ✅ All are empty → safe to update
                            DB::table('tickets')->where('id', $ticketId)->update($insertData);
                            Log::info("✅ Ticket {$ticketId} updated successfully.", $insertData);
                        } else {
                            // ❌ Not empty → skip update
                            Log::info("⚠️ Ticket {$ticketId} not updated because fields already exist.");
                        }
                    } else {
                        Log::info("⚠️ No insert data provided for Ticket {$ticketId}.");
                    }
                } else {
                    Log::warning("❌ Missing ticket ID in email header.");
                }


                $customer_name = null;
                $from = null;

                if ($fromRaw) {
                    // Example: "Gopal Sharma <gopalsh444@gmail.com>"
                    if (preg_match('/(.*)<(.+)>/', $fromRaw, $matches)) {
                        $customer_name = trim($matches[1], '" ');
                        $from = trim($matches[2]);
                    } else {
                        // No name, only email address
                        $from = trim($fromRaw);
                        $customer_name = null;
                    }
                }

                $toEmails  = collect($emailData['toRecipients'] ?? [])->pluck('emailAddress.address')->toArray();
                $ccEmails  = collect($emailData['ccRecipients'] ?? [])->pluck('emailAddress.address')->toArray();
                $bccEmails = collect($emailData['bccRecipients'] ?? [])->pluck('emailAddress.address')->toArray();

                $replyTo  = CryptService::encryptData(json_encode($toEmails));
                $replyCc  = CryptService::encryptData(json_encode($ccEmails));
                $replyBcc = CryptService::encryptData(json_encode($bccEmails));
                $replyFromEncrypted = CryptService::encryptData($from);

                // Avoid duplicates
                $exists = DB::table('tickets')
                    ->where('message_id', $messageId)
                    ->where('internal_message_id', $internetMessageId)
                    ->where('conversationId', $conversationId)
                    ->exists();

                if ($exists) {
                    Log::info("🛑 Skipping duplicate Gmail: message_id: $messageId");
                    continue;
                }

                // Skip no-reply
                if (preg_match('/^no[-_]?reply@/i', $from)) continue;

                $emailTitle       = $emailData['subject'] ?? '';
                $emailDescription = $emailData['body']['content'] ?? '';
                $attachments      = $emailData['saved_attachments'] ?? [];
                $attachmentsStr   = !empty($attachments) ? json_encode($attachments) : '';

                // Create or fetch customer
                $customerData = DB::table('superadmins')->where([
                    'email' => CryptService::encryptData($from),
                    'login_type' => 3
                ])->first();

                $cutomer_id = 0;
                $is_customer_new = 0;
                $customer_username = '';
                $customer_password = '';

                if (!$customerData) {
                    $defaultMobile = '0000000000';
                    $defaultPassword = Str::random(12);
                    $customer_username = $from;
                    $customer_password = $defaultPassword;

                    $cutomer_id = DB::table('superadmins')->insertGetId([
                        'name' => CryptService::encryptData($customer_name),
                        'email' => CryptService::encryptData($from),
                        'password' => Hash::make($defaultPassword),
                        'd_password' => CryptService::encryptData($defaultPassword),
                        'login_type' => 3,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    $is_customer_new = 1;

                    DB::table("customer_history")->insert([
                        "customer_id" => $cutomer_id,
                        "subadmin_id" => $agent_data->subadmin_id
                    ]);
                } else {
                    $cutomer_id = $customerData->id;

                    $historyExists = DB::table("customer_history")
                        ->where("customer_id", $cutomer_id)
                        ->where("subadmin_id", $agent_data->subadmin_id)
                        ->exists();

                    if (!$historyExists) {
                        DB::table("customer_history")->insert([
                            "customer_id" => $cutomer_id,
                            "subadmin_id" => $agent_data->subadmin_id
                        ]);
                    }
                }

                // Send to add_tickets API
                $url = env('API_BASE_URL') . '/api/Tickets/add_tickets';
                $payload = [
                    'auto_suggent_agent' => $agent_data->id,
                    'status' => 1,
                    'admin_id' => $cutomer_id,
                    'subadmin_id' => $agent_data->subadmin_id,
                    'subject' => !empty($emailTitle) ? $emailTitle : 'No Subject',
                    'description' => !empty($emailDescription) ? $emailDescription : 'No Description',
                    'attachments_string' => $attachmentsStr,
                    'message_id' => $messageId,
                    'internetMessageId' => $internetMessageId,
                    'conversationId' => $conversationId,
                    'sender' => strtolower($from),
                    'is_customer_new' => $is_customer_new,
                    'customer_username' => $customer_username,
                    'customer_password' => $customer_password,
                    'reply_from' => $replyFromEncrypted,
                    'reply_to' => $replyTo,
                    'reply_cc' => $replyCc,
                    'reply_bcc' => $replyBcc
                ];

                $response = Http::withHeaders([
                    'accept' => 'application/json, text/plain, */*',
                ])->post($url, $payload);


                 // Convert payload to JSON string for Postman
                        $jsonPayload = json_encode($payload, JSON_PRETTY_PRINT);

                        // Create cURL string
                        $curlCommand = <<<CURL
                        curl -X POST "$url" \\
                        -H "Accept: application/json, text/plain, */*" \\
                        -H "Content-Type: application/json" \\
                        -d '$jsonPayload'
                        CURL;
                    Log::info("Generated cURL for add_tickets:\n$curlCommand");

                Log::info("✉️ Gmail processed: message_id $messageId, response: " . $response->status());
            // }
        }

        return response()->json(['message' => 'Gmail webhook received']);
    }

}