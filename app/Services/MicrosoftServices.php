<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use App\Services\WasabiService;

class MicrosoftServices
{
    protected $setting;
    protected $accessToken;
    protected $tenantId;
    protected $clientId;
    protected $clientSecret;

    public function __construct($setting='')
    {
        $this->setting = $setting;
        $this->tenantId = '01af84f1-389b-422d-8473-9dee02ee68fc'; // Replace with actual tenant ID
        $this->clientId = env('MICROSOFT_CLIENT_ID');
	$this->clientSecret = env('MICROSOFT_CLIENT_SECRET');
    }

    protected function getValidAccessToken()
    {
        if (now()->gte($this->setting->token_expires_at)) {
            return $this->refreshAccessToken();
        }

        return $this->setting->access_token;
    }

    public function generateLoginLink($state = null)
    {
        $redirectUri = urlencode(env('API_BASE_URL') . '/api/Tickets/microsoft/redirect');

        $link = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/authorize?"
            . "client_id={$this->clientId}"
            . "&response_type=code"
            . "&redirect_uri={$redirectUri}"
            . "&response_mode=query"
            . "&scope=offline_access%20https://graph.microsoft.com/.default";

        if ($state) {
            $link .= "&state={$state}";
        }

        return $link;
    }



    protected function refreshAccessToken()
    {
        $refreshToken = $this->setting->refresh_token;
        $response = Http::asForm()->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'client_id' => $this->clientId,
            'scope' => 'https://graph.microsoft.com/.default',
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',    
            'client_secret' => $this->clientSecret,
        ]);

        if (!$response->successful()) {
            throw new \Exception("Failed to refresh access token");
        }

        $data = $response->json();

        DB::table('superadmins')
            ->where('id', $this->setting->id) // or your specific condition
            ->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $this->setting->refresh_token, // fallback to old token if not provided
                'token_expires_at' => now()->addSeconds($data['expires_in']),
            ]);


        return $data['access_token'];
    }

    // public function getEmailByMessageId($userId, $messageId)
    // {
    //     $accessToken = $this->getValidAccessToken();

    //     if (!$accessToken) {
    //         \Log::error("Access token not found in settings table.");
    //         return null;
    //     }

    //     $url = "https://graph.microsoft.com/v1.0/users/{$userId}/messages/{$messageId}";

    //     $response = Http::withToken($accessToken)->get($url);

    //     if ($response->successful()) {
    //         return $response->json();
    //     } else {
    //         \Log::error("❌ Failed to fetch message from Microsoft Graph", [
    //             'status' => $response->status(),
    //             'error' => $response->json()
    //         ]);
    //         return null;
    //     }
    // }


    public function getEmailByMessageId($userId, $messageId,$subadmin_id)
    {
        $accessToken = $this->getValidAccessToken();

        if (!$accessToken) {
            \Log::error("Access token not found in settings table.");
            return null;
        }

        // $emailUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/messages/{$messageId}";
        // $emailUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/messages/{$messageId}?$select=subject,body,from,toRecipients,ccRecipients,bccRecipients,internetMessageId,conversationId,internetMessageHeaders";
        $emailUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/messages/{$messageId}?\$select=subject,body,from,toRecipients,ccRecipients,bccRecipients,internetMessageId,conversationId,internetMessageHeaders";

        $attachmentsUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/messages/{$messageId}/attachments";

        // Fetch email content
        $emailResponse = Http::withToken($accessToken)->get($emailUrl);

        if (!$emailResponse->successful()) {
            \Log::error("❌ Failed to fetch message from Microsoft Graph", [
                'status' => $emailResponse->status(),
                'error' => $emailResponse->json()
            ]);
            return null;
        }

        $emailData = $emailResponse->json();

        // Fetch attachments
        $attachmentsResponse = Http::withToken($accessToken)->get($attachmentsUrl);

        $savedAttachments = [];
        // $wasabiService = app(WasabiService::class);
        $wasabiService = app(WasabiService::class, ['subadminId' => $subadmin_id]);
        if ($attachmentsResponse->successful()) {
            $attachmentData = $attachmentsResponse->json();

            foreach ($attachmentData['value'] as $attachment) {
                if ($attachment['@odata.type'] === '#microsoft.graph.fileAttachment') {
                    $decodedContent = base64_decode($attachment['contentBytes']);
                    $filename = $attachment['name'];
                    // $storagePath = "uploads/docs/" . uniqid() . "_" . $filename;

                    // Storage::disk('public')->put($storagePath, $decodedContent);
                      $tempPath = tempnam(sys_get_temp_dir(), 'wasabi_');
                    file_put_contents($tempPath, $decodedContent);

                    // Wrap temp file as UploadedFile to make it compatible with Laravel-style
                    $tempFile = new \Illuminate\Http\UploadedFile(
                        $tempPath,
                        $filename,
                        null,
                        null,
                        true // mark as test (prevent move)
                    );

                    $wasabiUrl = $wasabiService->uploadFile('Ticket/attachments', $tempFile);

                    $savedAttachments[] = [
                        'file_name' => $filename,
                        'url' => $wasabiUrl
                    ];
                }
            }
        } else {
            \Log::warning("⚠️ Failed to fetch attachments for message $messageId", [
                'status' => $attachmentsResponse->status(),
                'error' => $attachmentsResponse->json()
            ]);
        }

        // Attach attachments info to the returned object
        $emailData['saved_attachments'] = $savedAttachments;

        return $emailData;
    }


    public function renewAllSubscriptions($subscription,$newExpiration)
    {
        $accessToken = $this->getValidAccessToken();
            
            return $response = Http::withToken($accessToken)
                ->patch("https://graph.microsoft.com/v1.0/subscriptions/{$subscription->subscription_id}", [
                    'expirationDateTime' => $newExpiration,
                    'clientState' => $this->clientSecret,
                ]);

          
        

       
    }

    public function getAllSubscriptions()
{
    $accessToken = $this->getValidAccessToken(); // however you get/store tokens

    return Http::withToken($accessToken)
        ->get('https://graph.microsoft.com/v1.0/subscriptions');
}


    public function createSubscription($email)
    {
        $accessToken = $this->getValidAccessToken();
        $exists = DB::table('subscriptions')->where('email', $email)->first();
        if ($exists) {
            return ['status' => 'exists', 'message' => 'Subscription already exists'];
        }
        $notificationUrl = env('API_BASE_URL') . '/api/Tickets/microsoft/webhook-handler';
        // $notificationUrl = 'https://backend.stardesk.co.in/public/api/Tickets/microsoft/webhook-handler';
        $expirationDateTime = now()->addDay()->toIso8601String();
        $resource = "/users/{$email}/mailFolders('inbox')/messages";

        $response = Http::withToken($accessToken)
            ->post('https://graph.microsoft.com/v1.0/subscriptions', [
                'changeType' => 'created',
                'notificationUrl' => $notificationUrl,
                'resource' => $resource,
                'expirationDateTime' => $expirationDateTime,
                'clientState' => $this->clientSecret
            ]);

        if (!$response->successful()) {
            return ['status' => 'error', 'message' => 'Failed to create subscription', 'details' => $response->json()];
        }

        $subscriptionData = $response->json();
        $expiresAt = Carbon::parse($subscriptionData['expirationDateTime'])->format('Y-m-d H:i:s');

        DB::table('subscriptions')->insert([
            'email' => $email,
            'subscription_id' => $subscriptionData['id'],
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['status' => 'success', 'data' => $subscriptionData];
    }

    public function deleteAllSubscriptions()
    {
        $accessToken = $this->getValidAccessToken();
        $subs = Http::withToken($accessToken)
            ->get('https://graph.microsoft.com/v1.0/subscriptions')
            ->json()['value'] ?? [];
       print_r($subs);
        // foreach ($subs as $sub) {
        //     Http::withToken($accessToken)
        //         ->delete("https://graph.microsoft.com/v1.0/subscriptions/{$sub['id']}");
        // }

        

        return ['status' => 'success', 'message' => 'All subscriptions deleted'];
    }

    // public function exchangeAuthorizationCode($code)
    // {
    //     $response = Http::asForm()->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
    //         'client_id' => $this->clientId,
    //         'scope' => 'https://graph.microsoft.com/.default offline_access',
    //         'code' => $code,
    //         'redirect_uri' => route('microsoft.redirect'),
    //         'grant_type' => 'authorization_code',
    //         'client_secret' => $this->clientSecret,
    //     ]);

    //     if ($response->successful()) {
    //         $tokens = $response->json();

    //         DB::table('setting')->where('id', 1)->update([
    //             'access_token' => $tokens['access_token'],
    //             'refresh_token' => $tokens['refresh_token'],
    //             'token_expires_at' => now()->addSeconds($tokens['expires_in']),
    //             'userId'=>$tokens['id']
    //         ]);

    //         return [
    //             'success' => true,
    //             'tokens' => $tokens
    //         ];
    //     } else {
    //         return [
    //             'success' => false,
    //             'error' => $response->json()
    //         ];
    //     }
    // }


    public function exchangeAuthorizationCode($code,$user_id,$type)
    {
        $response = Http::asForm()->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'client_id' => $this->clientId,
            'scope' => 'https://graph.microsoft.com/.default offline_access',
            'code' => $code,
            'redirect_uri' => route('microsoft.redirect'),
            'grant_type' => 'authorization_code',
            'client_secret' => $this->clientSecret,
        ]);

        if ($response->successful()) {
            $tokens = $response->json();
            $accessToken = $tokens['access_token'];

            // ✅ Step 1: Get user profile info
            $userResponse = Http::withToken($accessToken)
                ->get('https://graph.microsoft.com/v1.0/me');

            if (!$userResponse->successful()) {
                return [
                    'success' => false,
                    'error' => 'Token received but failed to fetch user profile.',
                    'details' => $userResponse->json(),
                ];
            }

            $user = $userResponse->json();
            $email = $user['mail'] ?? $user['userPrincipalName']; // fallback if 'mail' is null
            $userId = $user['id'];
            // echo CryptService::encryptData($email);exit;
            // echo "<pre>";print_r($user);exit;
            // ✅ Step 2: Check if user exists in superadmin table
            $superadmin = DB::table('superadmins')->where('id', $user_id)->first();

            if (!$superadmin) {
                return [
                    'success' => false,
                    'error' => 'Email not found in superadmin table.',
                    'email' => $email,
                ];
            }

            // ✅ Step 3: Update superadmin table
            DB::table('superadmins')->where('id', $user_id)->update([
                'access_token' => $accessToken,
                'refresh_token' => $tokens['refresh_token'],
                'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                'graph_user_id' => $userId,
            ]);

            return [
                'success' => true,
                'email' => $email,
                'tokens' => $tokens,
            ];
        } else {
            return [
                'success' => false,
                'error' => $response->json()
            ];
        }
    }


}
