<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\WasabiService;

class GoogleServices
{
    protected $setting;
    protected $clientId;
    protected $clientSecret;
    protected $redirectUri;
    protected $jsonpath;

    public function __construct($setting = null)
    {
        $this->setting = $setting;
        $this->clientId = env('GOOGLE_CLIENT_ID');
	$this->clientSecret = env('GOOGLE_CLIENT_SECRET');
        $this->redirectUri = env('API_BASE_URL') . '/api/Tickets/google/redirect';
        $this->jsonpath = 'stardesk-live-8f1f8ca268f6.json';
    }

   public function generateLoginLink($state = null)
{
    $scope = urlencode(
        'https://www.googleapis.com/auth/gmail.readonly ' .
        'https://www.googleapis.com/auth/userinfo.email ' .
        'https://www.googleapis.com/auth/userinfo.profile ' .
        'https://www.googleapis.com/auth/pubsub'
    );

    $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?"
        . "client_id={$this->clientId}"
        . "&redirect_uri=" . urlencode($this->redirectUri)
        . "&response_type=code"
        . "&access_type=offline"
        . "&prompt=consent"
        . "&scope={$scope}";

    if ($state) {
        $authUrl .= "&state={$state}";
    }

    return $authUrl;
}


    protected function getValidAccessToken()
    {
        if (now()->gte($this->setting->google_token_expires_at)) {
            return $this->refreshAccessToken();
        }

        return $this->setting->google_access_token;
    }

    protected function refreshAccessToken()
    {
        $refreshToken = $this->setting->google_refresh_token;

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            throw new \Exception("Failed to refresh Google access token");
        }

        $data = $response->json();

        DB::table('superadmins')
            ->where('id', $this->setting->id)
            ->update([
                'google_access_token' => $data['access_token'],
                'google_token_expires_at' => now()->addSeconds($data['expires_in']),
            ]);

        return $data['access_token'];
    }

    public function exchangeAuthorizationCode($code, $user_id)
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (!$response->successful()) {
            return [
                'success' => false,
                'error' => $response->json(),
            ];
        }

        $tokens = $response->json();
        $accessToken = $tokens['access_token'];

        // Fetch Google user info
        $userInfo = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo')->json();

        $email = $userInfo['email'] ?? null;

        DB::table('superadmins')->where('id', $user_id)->update([
            'google_access_token' => $accessToken,
            'google_refresh_token' => $tokens['refresh_token'] ?? $this->setting->refresh_token,
            'google_token_expires_at' => now()->addSeconds($tokens['expires_in']),
            'google_email' => $email,
        ]);

        return [
            'success' => true,
            'email' => $email,
            'tokens' => $tokens,
        ];
    }

    // public function getEmailByMessageId($messageId, $subadmin_id)
    // {
    //     $accessToken = $this->getValidAccessToken();
    //     if (!$accessToken) {
    //         Log::error("Google access token missing.");
    //         return null;
    //     }

    //     $emailUrl = "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}?format=full";
    //     $response = Http::withToken($accessToken)->get($emailUrl);

    //     if (!$response->successful()) {
    //         Log::error("❌ Failed to fetch Gmail message", ['status' => $response->status(), 'error' => $response->json()]);
    //         return null;
    //     }

    //     $data = $response->json();
    //     $attachments = [];

    //     // Extract attachments if any
    //     if (!empty($data['payload']['parts'])) {
    //         $wasabiService = app(WasabiService::class, ['subadminId' => $subadmin_id]);

    //         foreach ($data['payload']['parts'] as $part) {
    //             if (isset($part['body']['attachmentId'])) {
    //                 $attachmentId = $part['body']['attachmentId'];
    //                 $filename = $part['filename'];

    //                 $attachRes = Http::withToken($accessToken)
    //                     ->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}/attachments/{$attachmentId}");

    //                 if ($attachRes->successful()) {
    //                     $attachmentData = $attachRes->json();
    //                     $decoded = base64_decode(strtr($attachmentData['data'], '-_', '+/'));

    //                     $tempPath = tempnam(sys_get_temp_dir(), 'wasabi_');
    //                     file_put_contents($tempPath, $decoded);

    //                     $tempFile = new \Illuminate\Http\UploadedFile(
    //                         $tempPath,
    //                         $filename,
    //                         null,
    //                         null,
    //                         true
    //                     );

    //                     $wasabiUrl = $wasabiService->uploadFile('Ticket/attachments', $tempFile);

    //                     $attachments[] = [
    //                         'file_name' => $filename,
    //                         'url' => $wasabiUrl
    //                     ];
    //                 }
    //             }
    //         }
    //     }

    //     $data['saved_attachments'] = $attachments;
    //     return $data;
    // }

    /**
 * Create a Pub/Sub subscription using a service account
 */
public function createSubscription($email)
{
    try {
        // Load service account JSON
        // $serviceAccountPath = storage_path('app/google/gmailwebhookproject-474806-ad7aaf6197b5.json');
        $serviceAccountPath = storage_path('app/google/' . $this->jsonpath);
        $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);

        if (!$serviceAccount) {
            return ['status' => 'error', 'message' => 'Service account JSON not found'];
        }

        // Prepare JWT for OAuth 2.0
        $header = base64_encode(json_encode(['alg' => 'RS256','typ'=>'JWT']));
        $now = time();
        $claim = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/pubsub',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600
        ];
        $claim_encoded = base64_encode(json_encode($claim));

        $signature_input = $header . "." . $claim_encoded;
        openssl_sign($signature_input, $signature, openssl_pkey_get_private($serviceAccount['private_key']), 'SHA256');

        $jwt = $signature_input . "." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        // Get access token
        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]);

        if (!$tokenResponse->successful()) {
            return ['status' => 'error', 'message' => 'Failed to get service account token', 'response' => $tokenResponse->json()];
        }

        $accessToken = $tokenResponse->json()['access_token'];
        
        // Define subscription name & topic
        $projectId = $serviceAccount['project_id'];
        $subscriptionName = str_replace(['@', '.'], '-', $email) . '-subscription';
        $topicName = "projects/{$projectId}/topics/gmail_notifications";
        $pushEndpoint = env('API_BASE_URL') . '/api/Tickets/google/webhook-handler';

        // Create subscription via Pub/Sub REST API
        $url = "https://pubsub.googleapis.com/v1/projects/{$projectId}/subscriptions/{$subscriptionName}";
        $body = [
            'topic' => $topicName,
            'pushConfig' => ['pushEndpoint' => $pushEndpoint]
        ];

        $response = Http::withToken($accessToken)->put($url, $body);

        if ($response->status() == 409) {
            // Subscription already exists
            return ['status' => 'exists', 'message' => 'Subscription already exists'];
        }

        if (!$response->successful()) {
            Log::error('❌ Failed to create Google Pub/Sub subscription', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            return ['status' => 'error', 'message' => 'Failed to create subscription', 'response' => $response->json()];
        }

        // Save subscription info in DB
        DB::table('google_subscriptions')->insert([
            'email' => $email,
            'subscription_name' => $subscriptionName,
            'topic_name' => $topicName,
            'push_endpoint' => $pushEndpoint,
            'expires_at' => now()->addDays(7), // example: expires in 7 days
            'created_at' => now(),
            'updated_at' => now(),
        ]);


        Log::info('✅ Google Pub/Sub subscription created', [
            'subscription_name' => $subscriptionName,
            'topic' => $topicName,
            'endpoint' => $pushEndpoint
        ]);

        return [
            'status' => 'success',
            'message' => 'Subscription created successfully',
            'subscription_name' => $subscriptionName,
            'topic_name' => $topicName,
            'push_endpoint' => $pushEndpoint
        ];
    } catch (\Exception $e) {
        Log::error('❌ Exception while creating subscription: ' . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

public function setTopicIamPolicy()
{
    try {
        // 🔹 Load your Google service account
        // $serviceAccountPath = storage_path('app/google/gmailwebhookproject-474806-ad7aaf6197b5.json');
        $serviceAccountPath = storage_path('app/google/' . $this->jsonpath);
        $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);

        if (!$serviceAccount) {
            return ['status' => 'error', 'message' => 'Service account JSON not found'];
        }

        // 🔹 Create JWT for OAuth 2.0 service account flow
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $claim = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/pubsub',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $claim_encoded = base64_encode(json_encode($claim));
        $signature_input = $header . '.' . $claim_encoded;
        openssl_sign(
            $signature_input,
            $signature,
            openssl_pkey_get_private($serviceAccount['private_key']),
            'SHA256'
        );
        $jwt = $signature_input . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        // 🔹 Exchange JWT for an access token
        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (!$tokenResponse->successful()) {
            return [
                'status' => 'error',
                'message' => 'Failed to get access token',
                'response' => $tokenResponse->json()
            ];
        }

        $accessToken = $tokenResponse->json()['access_token'];

        // 🔹 Build the topic IAM policy
        $projectId = $serviceAccount['project_id'];
        $topicName = "projects/{$projectId}/topics/gmail_notifications";

        $policyUrl = "https://pubsub.googleapis.com/v1/{$topicName}:setIamPolicy";

        $policyBody = [
            'policy' => [
                'bindings' => [
                    [
                        'role' => 'roles/pubsub.publisher',
                        'members' => [
                            'serviceAccount:gmail-api-push@system.gserviceaccount.com'
                        ]
                    ]
                ]
            ]
        ];

        // 🔹 Call the API
        $response = Http::withToken($accessToken)->post($policyUrl, $policyBody);

        if ($response->successful()) {
            Log::info('✅ Successfully granted Pub/Sub publisher role to gmail-api-push service account', [
                'topic' => $topicName
            ]);
            return ['status' => 'success', 'message' => 'Policy applied successfully'];
        } else {
            Log::error('❌ Failed to apply IAM policy', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            return ['status' => 'error', 'response' => $response->json()];
        }
    } catch (\Exception $e) {
        Log::error('❌ Exception in setTopicIamPolicy: ' . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Return messages (ids + threadId) that changed since a Gmail historyId.
 *
 * @param string|int $startHistoryId
 * @param array $labelIds Optional labels filter for history list (default INBOX)
 * @param int $fallbackMaxResults How many recent messages to return if history is not available
 * @return array  Array of ['id' => messageId, 'threadId' => threadId|null]
 */
public function getLatestMessageSinceHistoryId($startHistoryId, $labelIds = ['INBOX'], $fallbackMaxResults = 50)
{
    try {
        $accessToken = $this->getValidAccessToken();
        if (!$accessToken) {
            Log::error("Google access token missing in getLatestMessageSinceHistoryId.");
            return null;
        }

        $baseUrl = 'https://gmail.googleapis.com/gmail/v1/users/me/history';
        $params = [
            'startHistoryId' => (string) $startHistoryId,
            'maxResults' => 10, // limit result size to avoid heavy response
        ];

        if (!empty($labelIds)) {
            $params['labelId'] = $labelIds[0];
        }

         $queryString = http_build_query($params);
        $fullUrl = $baseUrl . '?' . $queryString;

        // --- Generate cURL command for Postman testing ---
        $curlCommand = "curl -X GET \"$fullUrl\" -H \"Authorization: Bearer $accessToken\" -H \"Accept: application/json\"";

        // Log full request details
        Log::info("📡 Gmail API Request (getLatestMessageSinceHistoryId):", [
            'curl' => $curlCommand,
            'url' => $fullUrl,
            'params' => $params,
        ]);
        
        $response = Http::withToken($accessToken)->get($baseUrl, $params);

        if (!$response->successful()) {
            Log::warning("Gmail history.list failed", [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            return null;
        }

        $data = $response->json();

        // If no history changes found
        if (empty($data['history']) || !is_array($data['history'])) {
            Log::info("No history changes found after historyId: " . $startHistoryId);
            return null;
        }

        // Take the most recent history entry
        $latestEntry = end($data['history']);

        // Combine possible message arrays
        $messagesAdded = $latestEntry['messagesAdded'] ?? [];
        $messagesDirect = $latestEntry['messages'] ?? [];
        // $allMessages = array_merge($messagesAdded, $messagesDirect);
        $allMessages = $messagesDirect;

        if (!empty($allMessages)) {
            $latestMsg = end($allMessages);
            $msg = $latestMsg['message'] ?? $latestMsg;

            if (!empty($msg['id'])) {
                Log::info("Latest Gmail message ID found: " . $msg['id']);
                return [
                    'id' => $msg['id'],
                    'threadId' => $msg['threadId'] ?? null,
                ];
            }
        }

        Log::info("No new messages found in latest history entry.");
        return null;

    } catch (\Exception $e) {
        Log::error('Exception in getLatestMessageSinceHistoryId: ' . $e->getMessage());
        return null;
    }
}



public function watchMailbox()
{
    try {
        $accessToken = $this->getValidAccessToken(); // use your existing function
        if (!$accessToken) {
            return ['status' => 'error', 'message' => 'Access token not available'];
        }

        //  $serviceAccountPath = storage_path('app/google/gmailwebhookproject-474806-ad7aaf6197b5.json');
        $serviceAccountPath = storage_path('app/google/' . $this->jsonpath);
        $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);

        if (!$serviceAccount || empty($serviceAccount['project_id'])) {
            return ['status' => 'error', 'message' => 'Service account or project ID not found'];
        }

        $projectId = $serviceAccount['project_id'];

        $topicName = "projects/{$projectId}/topics/gmail_notifications";

        $url = "https://gmail.googleapis.com/gmail/v1/users/me/watch";

        $response = Http::withToken($accessToken)->post($url, [
            'topicName' => $topicName
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return [
                'status' => 'success',
                'historyId' => $data['historyId'] ?? null
            ];
        } else {
            Log::error('❌ Failed to set Gmail watch', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to set Gmail watch',
                'response' => $response->json()
            ];
        }
    } catch (\Exception $e) {
        Log::error('❌ Exception in watchMailbox: ' . $e->getMessage());
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

public function unwatchMailbox()
{
    try {
        $accessToken = $this->getValidAccessToken(); // use your existing function
        if (!$accessToken) {
            return ['status' => 'error', 'message' => 'Access token not available'];
        }

        // Gmail "stop" endpoint — cancels all watch subscriptions for the user
        $url = "https://gmail.googleapis.com/gmail/v1/users/me/stop";

        $response = Http::withToken($accessToken)->post($url);

        if ($response->successful()) {
            return [
                'status' => 'success',
                'message' => 'Successfully unwatched mailbox — all watches cleared.'
            ];
        } else {
            Log::error('❌ Failed to unwatch Gmail mailbox', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to unwatch Gmail mailbox',
                'response' => $response->json()
            ];
        }
    } catch (\Exception $e) {
        Log::error('❌ Exception in unwatchMailbox: ' . $e->getMessage());
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}




    public function getEmailByMessageId($messageId, $subadmin_id)
{
    $accessToken = $this->getValidAccessToken();
    if (!$accessToken) {
        Log::error("Google access token missing.");
        return null;
    }

    $emailUrl = "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}?format=full";
    $response = Http::withToken($accessToken)->get($emailUrl);

    if (!$response->successful()) {
        Log::error("❌ Failed to fetch Gmail message", [
            'status' => $response->status(),
            'error' => $response->json()
        ]);
        return null;
    }

    $data = $response->json();
    $payload = $data['payload'] ?? [];

    $headers = collect($payload['headers'] ?? []);

    // Extract basic email info
    $from = optional($headers->firstWhere('name', 'From'))['value'] ?? null;
    $subject = optional($headers->firstWhere('name', 'Subject'))['value'] ?? null;
    $toRecipients = optional($headers->firstWhere('name', 'To'))['value'] ?? null;
    $ccRecipients = optional($headers->firstWhere('name', 'Cc'))['value'] ?? null;
    $bccRecipients = optional($headers->firstWhere('name', 'Bcc'))['value'] ?? null;
    $threadId = $data['threadId'] ?? null;
    $internetMessageId = optional($headers->firstWhere('name', 'Message-ID'))['value'] ?? null;
    $ticketIdHeader = optional($headers->firstWhere('name', 'X-FlyingStars-Ticket-ID'))['value'] ?? null;


    // // Get email body
    // $body = '';
    // if (!empty($payload['parts'])) {
    //     foreach ($payload['parts'] as $part) {
    //         if (($part['mimeType'] ?? '') === 'text/plain' || ($part['mimeType'] ?? '') === 'text/html') {
    //             $body = base64_decode(strtr($part['body']['data'] ?? '', '-_', '+/'));
    //             break;
    //         }
    //     }
    // } else {
    //     $body = base64_decode(strtr($payload['body']['data'] ?? '', '-_', '+/'));
    // }

    // Get email body
        $body = '';

        if (!empty($payload['parts'])) {
            $htmlBody = '';
            $plainBody = '';

            foreach ($payload['parts'] as $part) {
                $mimeType = $part['mimeType'] ?? '';

                // Handle HTML body
                if ($mimeType === 'text/html' && isset($part['body']['data'])) {
                    $htmlBody = base64_decode(strtr($part['body']['data'], '-_', '+/'));
                    break; // Prefer HTML, so stop once found
                }

                // Handle plain text as fallback
                if ($mimeType === 'text/plain' && isset($part['body']['data'])) {
                    $plainBody = nl2br(e(base64_decode(strtr($part['body']['data'], '-_', '+/'))));
                }

                // Check for nested parts (multipart/alternative)
                if (!empty($part['parts'])) {
                    foreach ($part['parts'] as $subPart) {
                        $subType = $subPart['mimeType'] ?? '';
                        if ($subType === 'text/html' && isset($subPart['body']['data'])) {
                            $htmlBody = base64_decode(strtr($subPart['body']['data'], '-_', '+/'));
                            break 2;
                        }
                    }
                }
            }

            // Prefer HTML, fallback to plain text
            $body = !empty($htmlBody) ? $htmlBody : $plainBody;

        } else {
            $body = base64_decode(strtr($payload['body']['data'] ?? '', '-_', '+/'));
        }

    // Process attachments
    $attachments = [];
    if (!empty($payload['parts'])) {
        $wasabiService = app(WasabiService::class, ['subadminId' => $subadmin_id]);

        foreach ($payload['parts'] as $part) {
            if (isset($part['body']['attachmentId'])) {
                Log::info("📎 Checking message part for attachments", [
                'message_id' => $messageId,
                'filename' => $part['filename'] ?? '(no name)',
                'mimeType' => $part['mimeType'] ?? '(unknown)',
                'hasAttachmentId' => isset($part['body']['attachmentId']),
            ]);
                $attachmentId = $part['body']['attachmentId'];
                $filename = $part['filename'];

                $attachRes = Http::withToken($accessToken)
                    ->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}/attachments/{$attachmentId}");

                     Log::debug("📨 Gmail attachment API response", [
                        'status' => $attachRes->status(),
                        'body_preview' => substr($attachRes->body(), 0, 300) // first 300 chars
                    ]);

                if ($attachRes->successful()) {
                    $attachmentData = $attachRes->json();
                    $decoded = base64_decode(strtr($attachmentData['data'], '-_', '+/'));

                    $tempPath = tempnam(sys_get_temp_dir(), 'wasabi_');
                    file_put_contents($tempPath, $decoded);

                    $tempFile = new \Illuminate\Http\UploadedFile(
                        $tempPath,
                        $filename,
                        null,
                        null,
                        true
                    );

                    $wasabiUrl = $wasabiService->uploadFile('Ticket/attachments', $tempFile);

                    $attachments[] = [
                        'file_name' => $filename,
                        'url' => $wasabiUrl
                    ];
                      Log::info("✅ Attachment uploaded successfully", [
                        'filename' => $filename,
                        'wasabi_url' => $wasabiUrl
                    ]);
                }
            }
        }
    }

    return [
        'from' => ['emailAddress' => ['address' => $from]],
        'subject' => $subject,
        'body' => ['content' => $body],
        'toRecipients' => $this->parseRecipients($toRecipients),
        'ccRecipients' => $this->parseRecipients($ccRecipients),
        'bccRecipients' => $this->parseRecipients($bccRecipients),
        'saved_attachments' => $attachments,
        'internetMessageId' => $internetMessageId,
        'conversationId' => $threadId,
        'ticketIdHeader'=>$ticketIdHeader
    ];
}

protected function parseRecipients($string)
{
    if (!$string) return [];
    $emails = array_map('trim', explode(',', $string));
    return array_map(function ($email) {
        return ['emailAddress' => ['address' => $email]];
    }, $emails);
}



    public function deleteAllSubscriptions()
        {
            // $serviceAccountPath = storage_path('app/google/gmailwebhookproject-474806-ad7aaf6197b5.json');
            $serviceAccountPath = storage_path('app/google/' . $this->jsonpath);
            $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);

            // Generate access token from service account (JWT)
            $header = base64_encode(json_encode(['alg' => 'RS256','typ'=>'JWT']));
            $now = time();
            $claim = [
                'iss' => $serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/pubsub',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600
            ];
            $claim_encoded = base64_encode(json_encode($claim));
            $signature_input = $header . "." . $claim_encoded;
            openssl_sign($signature_input, $signature, openssl_pkey_get_private($serviceAccount['private_key']), 'SHA256');
            $jwt = $signature_input . "." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

            $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]);

            $accessToken = $tokenResponse->json()['access_token'];

            // List all subscriptions in your project
            $projectId = $serviceAccount['project_id'];
            $listUrl = "https://pubsub.googleapis.com/v1/projects/{$projectId}/subscriptions";
            $response = Http::withToken($accessToken)->get($listUrl);

            $subscriptions = $response->json()['subscriptions'] ?? [];
            // dd($subscriptions);
            foreach ($subscriptions as $sub) {
                // $sub['name'] has full path like: projects/project-id/subscriptions/sub-name
                $url = "https://pubsub.googleapis.com/v1/".$sub['name'];

                $deleteRes = Http::withToken($accessToken)->delete($url);

                if ($deleteRes->successful() || $deleteRes->status() == 404) {
                    Log::info("Subscription {$url} deleted from Google Cloud.");
                } else {
                    Log::error("Failed to delete subscription {$url}", [
                        'status' => $deleteRes->status(),
                        'response' => $deleteRes->json()
                    ]);
                }
            }

        }

    public function renewWatch($superadminId)
        {
            try {
                $accessToken = $this->getValidAccessToken();
                if (!$accessToken) {
                    return ['status' => 'error', 'message' => 'Access token not available'];
                }

                // 🔹 Load service account file dynamically
                $serviceAccountPath = storage_path('app/google/' . $this->jsonpath);
                $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);

                if (!$serviceAccount || empty($serviceAccount['project_id'])) {
                    return ['status' => 'error', 'message' => 'Service account or project ID not found'];
                }

                $projectId = $serviceAccount['project_id'];
                $topicName = "projects/{$projectId}/topics/gmail_notifications";
                $url = "https://gmail.googleapis.com/gmail/v1/users/me/watch";

                $body = [
                    'topicName' => $topicName,
                    'labelIds' => ['INBOX'], // optional
                ];

                // 🔹 Log CURL for testing
                $curl = "curl -X POST '$url' -H 'Authorization: Bearer $accessToken' -H 'Content-Type: application/json' -d '" . json_encode($body) . "'";
                Log::info("📡 Gmail Watch Renewal CURL: $curl");

                // 🔹 Make API call
                $response = Http::withToken($accessToken)->post($url, $body);

                if ($response->failed()) {
                    Log::error('❌ Gmail watch renewal failed', [
                        'status' => $response->status(),
                        'response' => $response->json()
                    ]);

                    return [
                        'status' => 'error',
                        'message' => 'Failed to renew Gmail watch',
                        'response' => $response->json()
                    ];
                }

                $data = $response->json();

                if (isset($data['historyId'])) {
                    $historyId = $data['historyId'];
                    $expiry = now()->addDays(6); // Gmail watch expires every 7 days → renew slightly earlier

                    // 🔹 Update in DB
                    DB::table('superadmins')
                        ->where('id', $superadminId)
                        ->update([
                            'last_history_id' => $historyId,
                            'gmail_watch_expiry' => $expiry,
                            'updated_at' => now(),
                        ]);

                    Log::info("✅ Gmail watch renewed successfully for superadmin ID {$superadminId}. New historyId: {$historyId}, expires: {$expiry}");

                    return [
                        'status' => 'success',
                        'historyId' => $historyId,
                        'expiry' => $expiry,
                    ];
                }

                Log::warning("⚠️ Gmail watch renewed but no historyId found", ['response' => $data]);
                return [
                    'status' => 'warning',
                    'message' => 'Renewal successful but no historyId found',
                    'response' => $data
                ];

            } catch (\Exception $e) {
                Log::error('💥 Exception during Gmail watch renewal: ' . $e->getMessage());
                return [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

}
