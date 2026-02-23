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
use App\Services\MicrosoftServices;
use App\Services\GoogleServices;
use App\Helpers\WebSocketHelper;
use App\Services\AgentActivityService;


class WebhookController extends Controller
{
   

     private function extractEmailFromResource($resource)
    {
        // Matches the email address after /users/ and before /mailFolders
        preg_match("/\/users\/([^\/]+)\/mailFolders/", $resource, $matches);
        return $matches[1] ?? null;
    }

    public function deleteallsubscription(){
        
        $agent_data = DB::table('superadmins')
            ->where('id',395)
            ->get();
            // dd($agent_data);
        foreach($agent_data as $ad){
            $service = new MicrosoftServices($ad);
            $service->deleteAllSubscriptions();
        }
    }


    public function generateLoginLink(Request $request){
        $user_id = $request->query('user_id');
        $type = $request->query('type');
        $admin_id = $request->query('admin_id');
        $state = urlencode(json_encode([
            'user_id' => $user_id,
            'type' => $type,
            'admin_id'=>$admin_id
        ]));
        $agent_data = DB::table("superadmins")->where("id",$user_id)->first();
        
        $setting = DB::table("setting")->where("subadmin_id",$agent_data->subadmin_id)->first();
// dd($setting);
        if($setting->webhook_type==1){
            $service = new MicrosoftServices();
            $result = $service->generateLoginLink($state);
        }else {
            $service = new GoogleServices();
            $result = $service->generateLoginLink($state);
        }
        echo $result;
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

        $service = new MicrosoftServices();
        $result = $service->exchangeAuthorizationCode($code,$user_id,$type);
        // print_r($result);exit;

        if ($result['success']) {
            // return response()->json($result['tokens']);
            $agent_data = DB::table("superadmins")->where("id",$user_id)->first();
            $creator_data = DB::table("superadmins")->where("id",$admin_id)->first();
            if(!empty($agent_data->subadmin_id)){
                $company_info = DB::table("superadmins")->where("id",$agent_data->subadmin_id)->first();
                $service = new MicrosoftServices($agent_data);
                $service->createSubscription($result['email']);
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
     

    // public function renewAllSubscriptions(){
    //     // echo CryptService::encryptData("7440498598");exit;
    //      $subscriptions = DB::table('subscriptions')->get();
    //     //  dd($subscriptions);
    //      $updatedCount = 0;
    //     $failed = [];

    //     foreach ($subscriptions as $subscription) {
    //         $agent_data = DB::table("superadmins")->where("email",CryptService::encryptData($subscription->email))->first();
    //         $service = new MicrosoftServices($agent_data);
    //         $newExpiration = Carbon::now()->addMinutes(4230)->toIso8601String();
    //         $response = $service->renewAllSubscriptions($subscription,$newExpiration);
    //         // echo "<pre>";print_r($response);
    //         if ($response->successful()) {
    //                 // Update the expiration date in the database
    //                 DB::table('subscriptions')
    //                     ->where('id', $subscription->id)
    //                     ->update([
    //                         'expires_at' => Carbon::parse($newExpiration)->format('Y-m-d H:i:s'),
    //                         'updated_at' => now(),
    //                     ]);
    //                 $updatedCount++;
    //             } else {
    //                 $failed[] = [
    //                     'email' => $subscription->email,
    //                     'subscription_id' => $subscription->subscription_id,
    //                     'error' => $response->json()
    //                 ];
    //             }
    //             return [
    //             'updated' => $updatedCount,
    //             'failed' => $failed
    //         ];
    //     }
    // }

    public function renewAllSubscriptions() {
        $subscriptions = DB::table('subscriptions')->get();
        $updatedCount = 0;
        $failed = [];

        foreach ($subscriptions as $subscription) {
            $agent_data = DB::table("superadmins")->where("email", CryptService::encryptData($subscription->email))->first();
            $service = new MicrosoftServices($agent_data);
            $newExpiration = Carbon::now()->addMinutes(4230)->toIso8601String();
            $response = $service->renewAllSubscriptions($subscription, $newExpiration);

            if ($response->successful()) {
                DB::table('subscriptions')
                    ->where('id', $subscription->id)
                    ->update([
                        'expires_at' => Carbon::parse($newExpiration)->format('Y-m-d H:i:s'),
                        'updated_at' => now(),
                    ]);
                $updatedCount++;
            } else {
                $failed[] = [
                    'email' => $subscription->email,
                    'subscription_id' => $subscription->subscription_id,
                    'error' => $response->json()
                ];
            }
        }

        // // ✅ Move return here
        // return [
        //     'updated' => $updatedCount,
        //     'failed' => $failed
        // ];

        try {
        // 🔹 Fetch all superadmins whose Gmail watch will expire within 1 day
        // and who have a valid Google token
       $admins = DB::table('superadmins')
            ->whereNotNull('google_access_token')
            ->where('google_access_token', '!=', '')
            ->where(function ($query) {
                $query->whereNull('gmail_watch_expiry')
                      ->orWhere('gmail_watch_expiry', '=', '')
                      ->orWhereDate('gmail_watch_expiry', '<=', now()->addDay());
            })
            ->get();

        if ($admins->isEmpty()) {
            Log::info('📭 No Gmail watches to renew today.');
            return;
        }

        Log::info('🔁 Starting Gmail watch renewal for ' . $admins->count() . ' superadmins.');

        foreach ($admins as $admin) {
            try {
                Log::info("🧩 Renewing Gmail watch for superadmin ID: {$admin->id}");
                // $this->renewWatch($admin->id);
            $googleService = new GoogleServices($admin);
            $googleService->renewWatch($admin->id);

            } catch (\Exception $ex) {
                Log::error("💥 Error renewing Gmail watch for superadmin ID {$admin->id}: " . $ex->getMessage());
            }
        }

        Log::info('✅ Gmail watch auto-renew process completed.');

    } catch (\Exception $e) {
        Log::error('💥 Exception in autoRenewAllWatches: ' . $e->getMessage());
    }
    }


    public function handleNotification(Request $request)
    {
        if ($request->has('validationToken')) {
            return response($request->get('validationToken'), 200)
                ->header('Content-Type', 'text/plain');
        }
        // STEP 2: Handle actual notification payload
        $notifications = $request->input('value');
        
        // Log::info('📥 Raw notification payload: ', $request->all());

        foreach ($notifications as $notification) {
            Log::info('Duplication Testing :', (array) $notification);
            $resource = ltrim($notification['resource'], '/'); // Keep original case
            // Log::info($resource); 
            if (preg_match('#users/([^/]+)/messages/([^/]+)#i', $resource, $matches)) {
                $userId = $matches[1];     
                $messageId = $matches[2];  
                
                $agent_data = DB::table("superadmins")->where("graph_user_id",$userId)->first();
                $service = new MicrosoftServices($agent_data);
                $emailData = $service->getEmailByMessageId($userId, $messageId,$agent_data->subadmin_id);
                Log::debug('Full email data:', (array) $emailData);
                $internetMessageId = $emailData['internetMessageId'] ?? null;
                $conversationId = $emailData['conversationId'] ?? null;

                $from = $emailData['from']['emailAddress']['address'] ?? null;

                $toEmails = collect($emailData['toRecipients'] ?? [])
                    ->pluck('emailAddress.address')
                    ->toArray();

                $ccEmails = collect($emailData['ccRecipients'] ?? [])
                    ->pluck('emailAddress.address')
                    ->toArray();

                $bccEmails = collect($emailData['bccRecipients'] ?? [])
                    ->pluck('emailAddress.address')
                    ->toArray();

                $replyTo = CryptService::encryptData(json_encode($toEmails));
                $replyCc = CryptService::encryptData(json_encode($ccEmails));
                $replyBcc = CryptService::encryptData(json_encode($bccEmails));
                $replyFromEncrypted = CryptService::encryptData($from);

                $ticketIdHeader = null;
                foreach ($emailData['internetMessageHeaders'] as $header) {
                    if (strtolower($header['name']) === 'x-flyingstars-ticket-id') {
                        $ticketIdHeader = $header['value'];
                        break;
                    }
                }

                if (!empty($ticketIdHeader)) {
                    $ticketId = $ticketIdHeader;

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

                Log::info("Ticket ID from email header: ".$ticketIdHeader); // 71


                $exists = DB::table('tickets')
                    ->where('message_id', $messageId)
                    ->where('internal_message_id', $internetMessageId)
                    ->where('conversationId', $conversationId)
                    ->exists();

                if ($exists) {
                    Log::info("🛑 Skipping duplicate email: message_id: $messageId, internetMessageId: $internetMessageId, conversationId: $conversationId");
                    continue;
                }
                if ($emailData) {
                    $emailTitle = $emailData['subject'] ?? '';
                    $emailDescription = $emailData['body']['content'] ?? '';

                    $sender = $emailData['from']['emailAddress']['address'] ?? '';

                    // if (preg_match('/^no[-_]?reply@/i', $sender)) {
                    //     Log::info("📭 Skipping no-reply email: $sender");
                    //     continue;
                    // }
                    if (preg_match("/\[CLIENT:\s*([^\]]+)\]/i", $emailDescription, $matches)) {
                        $clientEmail = trim($matches[1]);
                        if (!empty($clientEmail)) {
                            $sender = $clientEmail; // Override sender with client email
                        }
                    }
                    if($sender=='noreply@stardesk.co.in' || $sender==
                    'noreply@testingscrew.com'){
                        continue;

                    }
                    $receiver = $emailData['toRecipients'][0]['emailAddress']['address'] ?? '';
                    // Log::info("📬 Email Received");
                    
                    Log::info("✉️ From: $sender");
                    // Log::info("📨 To: $receiver");
                      $attachments = $emailData['saved_attachments'] ?? [];
                    $attachments_string = !empty($attachments) ? json_encode($attachments) : '';

                    $customerData = DB::table("superadmins")->where(["email"=>CryptService::encryptData($sender),"login_type"=>3])->first();
                    $cutomer_id = 0;
                    $is_customer_new = 0;
                    $customer_username = '';
                    $customer_password = '';
                    if(empty($customerData)){
                        $senderEmail = $emailData['from']['emailAddress']['address'] ?? '';
                        $senderName  = $emailData['from']['emailAddress']['name'] ?? '';
                        $defaultMobile = '0000000000'; // fallback dummy number
                        $defaultPassword = Str::random(12);;
                        $customer_username = $senderEmail;
                        $customer_password = $defaultPassword;

                            $encryptedName     = CryptService::encryptData($senderName ?: 'Unknown');
                            $encryptedEmail    = CryptService::encryptData($senderEmail);
                            $encryptedNumber   = CryptService::encryptData($defaultMobile);
                            $encryptedPassword = CryptService::encryptData($defaultPassword);
                           $cutomer_id = DB::table('superadmins')->insertGetId([
                            'name' => $encryptedName,
                            'email' => $encryptedEmail,
                            // 'subadmin_id'=>$agent_data->subadmin_id,
                            'password' => Hash::make($defaultPassword),
                            'd_password' => $encryptedPassword,
                            'login_type' => 3,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                        $is_customer_new = 1;

                        DB::table("customer_history")->insert([
                            "customer_id" => $cutomer_id,
                            "subadmin_id"       => $agent_data->subadmin_id
                        ]);
                    }else {
                        $cutomer_id = $customerData->id;

                         $historyExists = DB::table("customer_history")
                            ->where("customer_id", $cutomer_id)
                            ->where("subadmin_id", $agent_data->subadmin_id)
                            ->exists();

                        if (!$historyExists) {
                            DB::table("customer_history")->insert([
                                "customer_id"  => $cutomer_id,
                                "subadmin_id"  => $agent_data->subadmin_id
                            ]);
                        }
                    }

                    $data = [
                        'auto_suggent_agent' => $agent_data->id,
                        'status'=>1,
                        'admin_id' => $cutomer_id,
                        'subject' => $emailTitle,
                        'description' => $emailDescription,
                        'attachments_string'=>$attachments_string,
                        'message_id'=>$messageId,
                        'internetMessageId'=>$internetMessageId,
                        'conversationId'=>$conversationId
                    ];

                    // Log::info('Logging email data:', $data);

                    $url = env('API_BASE_URL') . '/api/Tickets/add_tickets';
                    $response = Http::withHeaders([
                        'accept' => 'application/json, text/plain, */*',
                    ])
                    
                    ->post($url, [
                        'auto_suggent_agent' => $agent_data->id,
                        'status'=>1,
                        'admin_id' => $cutomer_id,
                        'subadmin_id'=>$agent_data->subadmin_id,
                        'subject' => $emailTitle,
                        'description' => $emailDescription,
                        'attachments_string'=>$attachments_string,
                        'message_id'=>$messageId,
                        'internetMessageId'=>$internetMessageId,
                        'conversationId'=>$conversationId,
                        'sender'=>strtolower($sender),
                        'is_customer_new'=>$is_customer_new,
                        'customer_username'=>$customer_username,
                        'customer_password'=>$customer_password,
                         'reply_from'=> $replyFromEncrypted,
                            'reply_to'  => $replyTo,
                            'reply_cc'  => $replyCc,
                            'reply_bcc' => $replyBcc
                    ]);


                    $payload = [
                        'auto_suggent_agent' => $agent_data->id,
                        'status'=>1,
                        'admin_id' => $cutomer_id,
                        'subadmin_id'=>$agent_data->subadmin_id,
                        'subject' => $emailTitle,
                        'description' => $emailDescription,
                        'attachments_string'=>$attachments_string,
                        'message_id'=>$messageId,
                        'internetMessageId'=>$internetMessageId,
                        'conversationId'=>$conversationId,
                        'sender'=>strtolower($sender),
                        'is_customer_new'=>$is_customer_new,
                        'customer_username'=>$customer_username,
                        'customer_password'=>$customer_password,
                         'reply_from'=> $replyFromEncrypted,
                            'reply_to'  => $replyTo,
                            'reply_cc'  => $replyCc,
                            'reply_bcc' => $replyBcc
                    ];

                        

                        // Convert payload to JSON string for Postman
                        $jsonPayload = json_encode($payload, JSON_PRETTY_PRINT);

                        // Create cURL string
                        $curlCommand = <<<CURL
                        curl -X POST "$url" \\
                        -H "Accept: application/json, text/plain, */*" \\
                        -H "Content-Type: application/json" \\
                        -d '$jsonPayload'
                        CURL;

                        // Log it
                        Log::info("Generated cURL for add_tickets:\n$curlCommand");

                } else {
                    Log::warning("❌ Failed to fetch message for user $userId and message $messageId");
                }

            } else {
                Log::warning("⚠️ Invalid resource structure: {$notification['resource']}");
            }
        }


        return response()->json(['message' => 'Webhook received']);
    }

    
    public function setWebhookUrl(){
        $agent_dataw = DB::table("superadmins")->where("root_access",1)->get();
        foreach($agent_dataw as $agent_data){
            $service = new MicrosoftServices($agent_data);

            $response = $service->getAllSubscriptions();

            if ($response->successful()) {
                $subscriptions = $response->json();
                echo "<pre>";
                print_r($subscriptions);
                echo "</pre>";
            } else {
                dd([
                    'error' => $response->status(),
                    'body' => $response->body()
                ]);
            }
        }
    exit;
         AgentActivityService::notify(165, [
                'event_type' => 'new_ticket',
                'ticket_id' => '176',
                'message' => 'New ticket assigned to you',
            ]);
            exit;
        //  WebSocketHelper::send(json_encode([
        //     'type' => 'new_ticket',
        //     'title' => "new_ticket",
        //     'ticket_id' => '2'
        // ]));
        // echo CryptService::encryptData("8688825859");exit;
        $userId = 'cc4ba65b-8170-412f-9fe3-e2f0f1587d4f';     
                $messageId = 'AAMkADU2MjRiZDM5LTFkOTUtNGM1NS05MzQ1LWJhNTJjNjRlY2NiMQBGAAAAAAAMhrkeAhfPQLgWNKM7kLZ0BwCRfEqHxLAtRbe0dgl1tbDDAAAAAAEMAACRfEqHxLAtRbe0dgl1tbDDAADyq6IKAAA=';  
                $agent_data = DB::table("superadmins")->where("graph_user_id",$userId)->first();
                $service = new MicrosoftServices($agent_data);
                $emailData = $service->getEmailByMessageId($userId, $messageId);
                print_r($emailData);exit;
        // $service = new MicrosoftServices();
        // $response = $service->createSubscription('unsubscribe@flyingstars.biz');

        // return response()->json($response);
    }




}