<?php 

namespace App\Lib;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use App\Services\CryptService;
use Illuminate\Support\Facades\Log;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Transport;

class EmailCurl {
  public function __construct($subadmin_id=''){
    
    //  $this->cdata = DB::table('setting')->first();

    //  $rawSettings = DB::table('setting')->first();
       $query = DB::table('setting');

        if (!empty($subadmin_id)) {
            $query->where('subadmin_id', $subadmin_id);
        }

        $rawSettings = $query->first();

    $this->cdata = (object) [
        'zoho_api_key'     => CryptService::decryptData($rawSettings->zoho_api_key),
        'bounce_address'   => CryptService::decryptData($rawSettings->bounce_address),
        'zoho_from_email'  => CryptService::decryptData($rawSettings->zoho_from_email),
    ];
    

    // print_r($this->cdata);exit;
      
   
  }

  function insertNotification($mob,$user_id,$zoho_api_key){
       $insert['email'] = $mob;
       $insert['noti_type'] = 2;
       $insert['uid'] = $user_id;  
       $insert['user_id'] = $zoho_api_key;
       DB::table('notification')->insert($insert);
  }

  function SendNotification($email,$message,$title=''){
      $zoho_cred = Session::get('zoho_cred');
      $curl = curl_init();
      curl_setopt_array($curl, array(
      CURLOPT_URL => "https://api.zeptomail.in/v1.1/email",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_POSTFIELDS => '{
      "bounce_address":"'.$zoho_cred['bounce_address'].'",
      "from": { "address": "'.$zoho_cred['zoho_from_email'].'"},
      "to": [{"email_address": {"address": "'.$email.'","name": "'.$title.'"}}],
      "subject":"'.$title.'",
      "htmlbody":"'.$message.'",
      }
      ]
      }',
      CURLOPT_HTTPHEADER => array(
      "accept: application/json",
      "authorization: Zoho-enczapikey {$zoho_cred['zoho_api_key']}",
      "cache-control: no-cache",
      "content-type: application/json",
      ),
      ));

      $response = curl_exec($curl);
      $err = curl_error($curl);

      curl_close($curl);

      // if ($err) {
      // echo "cURL Error #:" . $err;
      // } else {
      // echo $response;
      // }

}

function MSendNotification($emails, $message, $title = '', $customer_id = 0)
{
    $cdata = $this->cdata;

    if (!empty($cdata->zoho_api_key)) {
        $curl = curl_init();

        // Prepare the 'to' field with multiple recipients
        $recipients = [];
        foreach ($emails as $email) {
            $recipients[] = [
                "email_address" => [
                    "address" => $email,
                    "name" => $email
                ]
            ];
        }
        $styledMessage = str_replace('<p>', '<p style="margin:0;">', $message);

        $postData = json_encode([
            "bounce_address" => $cdata->bounce_address,
            "from" => ["address" => $cdata->zoho_from_email],
            "to" => $recipients, // Place all emails in the 'to' field
            "subject" => $title,
            "htmlbody" => $styledMessage
        ]);

        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.zeptomail.in/v1.1/email",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: Zoho-enczapikey {$cdata->zoho_api_key}",
                "cache-control: no-cache",
                "content-type: application/json",
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            echo "cURL Error #:" . $err;
        } else {
            // Handle response if necessary
            // print_r($response);
        }
    }
}

function SendNotification_3($email,$message,$title='',$customer_id=0){
    $cdata = $this->cdata;
      // dd($cdata);
      if(!empty($cdata->zoho_api_key)){
          $curl = curl_init();
          curl_setopt_array($curl, array(
          CURLOPT_URL => "https://api.zeptomail.in/v1.1/email",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "POST",
          CURLOPT_POSTFIELDS => '{
          "bounce_address":"'.$cdata->bounce_address.'",
          "from": { "address": "'.$cdata->zoho_from_email.'"},
          "to": [{"email_address": {"address": "'.$email.'","name": "'.$title.'"}}],
          "subject":"'.$title.'",
          "htmlbody":"'.$message.'",
          }
          ]
          }',
          CURLOPT_HTTPHEADER => array(
          "accept: application/json",
          "authorization: Zoho-enczapikey {$cdata->zoho_api_key}",
          "cache-control: no-cache",
          "content-type: application/json",
          ),
          ));
          $response = curl_exec($curl);
          $err = curl_error($curl);
          print_r($response);exit;
          curl_close($curl);
      }
}

function getFileContent($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}
function SendNotification_2($email, $message, $title = '',$pdfPath = null,$fname='') {
    // Get the settings or customer data
    // echo $pdfPath;exit;
        $cdata = $this->cdata;
    

    // Check if Zoho API key exists
    if (!empty($cdata->zoho_api_key)) {
        // Prepare the data for the API request
        $postData = [
            "bounce_address" => $cdata->bounce_address,
            "from" => ["address" => $cdata->zoho_from_email],
            "to" => [["email_address" => ["address" => $email, "name" => $email]]],
            "subject" => $title,
            "htmlbody" => $message,
        ];

        if (!empty($pdfPath) ) {
            // $pdfContent = file_get_contents($pdfPath);
            // echo $pdfPath;exit;
            $pdfContent = $this->getFileContent($pdfPath."?ver=".time());
            $base64PDF = base64_encode($pdfContent);
            $fileName = $fname;

            $postData["attachments"] = [
                [
                    "name" => $fileName,
                    "content" => $base64PDF,
                    "mime_type"=>"application/pdf"
                ]
            ];
        }
        // dd($postData);
        // print_r([
        //     CURLOPT_URL => "https://api.zeptomail.in/v1.1/email",
        //     CURLOPT_RETURNTRANSFER => true,
        //     CURLOPT_ENCODING => "",
        //     CURLOPT_MAXREDIRS => 10,
        //     CURLOPT_TIMEOUT => 30,
        //     CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        //     CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        //     CURLOPT_CUSTOMREQUEST => "POST",
        //     CURLOPT_POSTFIELDS => json_encode($postData),
        //     CURLOPT_HTTPHEADER => [
        //         "accept: application/json",
        //         "authorization: Zoho-enczapikey {$cdata->zoho_api_key}",
        //         "cache-control: no-cache",
        //         "content-type: application/json",
        //     ]]);exit;
        // Initialize cURL
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.zeptomail.in/v1.1/email",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: Zoho-enczapikey {$cdata->zoho_api_key}",
                "cache-control: no-cache",
                "content-type: application/json",
            ],
        ]);

        // Execute the cURL request
        $response = curl_exec($curl);
        $err = curl_error($curl);
        // print_r($response);exit;
        curl_close($curl);

       
    }
}

function SendNotificationM($email, $message, $title = '', $customer_id = 0, $pdfPath = null, $cc = [], $bcc = []) {
    $cdata = $this->cdata;

    if (!empty($cdata->zoho_api_key)) {
        $recipients = [];
        foreach ((array)$email as $emailAddress) {
            $recipients[] = [
                "email_address" => [
                    "address" => $emailAddress,
                    "name" => $emailAddress
                ]
            ];
        }

        $styledMessage = str_replace('<p>', '<p style="margin:0;">', $message);

        $postData = [
            "bounce_address" => $cdata->bounce_address,
            "from" => ["address" => $cdata->zoho_from_email],
            "to" => $recipients,
            "subject" => $title,
            "htmlbody" => $styledMessage,
        ];

        // Add CC if provided
        if (!empty($cc)) {
            $ccList = [];
            foreach ((array)$cc as $ccEmail) {
                $ccList[] = [
                    "email_address" => [
                        "address" => $ccEmail,
                        "name" => $ccEmail
                    ]
                ];
            }
            $postData['cc'] = $ccList;
        }

        // Add BCC if provided
        if (!empty($bcc)) {
            $bccList = [];
            foreach ((array)$bcc as $bccEmail) {
                $bccList[] = [
                    "email_address" => [
                        "address" => $bccEmail,
                        "name" => $bccEmail
                    ]
                ];
            }
            $postData['bcc'] = $bccList;
        }

        // Handle PDF attachment
        if (!empty($pdfPath) && file_exists($pdfPath)) {
            $pdfContent = file_get_contents($pdfPath);
            $base64PDF = base64_encode($pdfContent);
            $fileName = basename($pdfPath);

            $postData["attachments"] = [
                [
                    "name" => $fileName,
                    "content" => $base64PDF,
                    "mime_type" => "application/pdf"
                ]
            ];
        }

        // Send request
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.zeptomail.in/v1.1/email",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: Zoho-enczapikey {$cdata->zoho_api_key}",
                "cache-control: no-cache",
                "content-type: application/json",
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        // Optional logging
        if ($err) {
            // Log or handle error
        } else {
            // Log or process response
        }
    }
}


function SendNotificationMfffff($email, $message, $title = '', $customer_id = 0, $pdfPath = null) {
    $cdata = $this->cdata;
    // Check if Zoho API key exists
    if (!empty($cdata->zoho_api_key)) {
        // Prepare the recipients array
        $recipients = [];
        foreach ((array)$email as $emailAddress) {
            $recipients[] = [
                "email_address" => [
                    "address" => $emailAddress,
                    "name" => $title
                ]
            ];
        }

        // Prepare the data for the API request
        $styledMessage = str_replace('<p>', '<p style="margin:0;">', $message);
        $postData = [
            "bounce_address" => $cdata->bounce_address,
            "from" => ["address" => $cdata->zoho_from_email],
            "to" => $recipients,
            "subject" => $title,
            "htmlbody" => $styledMessage,
        ];

        if (!empty($pdfPath) && file_exists($pdfPath)) {
            $pdfContent = file_get_contents($pdfPath);
            $base64PDF = base64_encode($pdfContent);
            $fileName = basename($pdfPath);

            $postData["attachments"] = [
                [
                    "name" => $fileName,
                    "content" => $base64PDF,
                    "mime_type" => "application/pdf"
                ]
            ];
        }

        // Initialize cURL
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.zeptomail.in/v1.1/email",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: Zoho-enczapikey {$cdata->zoho_api_key}",
                "cache-control: no-cache",
                "content-type: application/json",
            ],
        ]);

        // Execute the cURL request
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        // You might want to handle the response or errors here
        if ($err) {
            // Log or handle error
        } else {
            // Process successful response
        }
    }
}

function SendNotificationMForSLA(
    $email,
    $message,
    $title = '',
    $customer_id = 0,
    $pdfPath = null,
    $cc = [],
    $bcc = [],
    $names = [],
    $cc_names = [],
    $bcc_names = []
) {
    $cdata = $this->cdata;

    if (!empty($cdata->zoho_api_key)) {
        $recipients = [];
        foreach ((array)$email as $index => $emailAddress) {
            $recipients[] = [
                "email_address" => [
                    "address" => $emailAddress,
                    "name" => $names[$index] ?? $title
                ]
            ];
        }

        $postData = [
            "bounce_address" => $cdata->bounce_address,
            "from" => [
                "address" => $cdata->zoho_from_email,
                "name" => $cdata->zoho_from_name ?? $title
            ],
            "to" => $recipients,
            "subject" => $title,
            "htmlbody" => str_replace('<p>', '<p style="margin:0;">', $message),
        ];

        Log::info('📧 ZeptoMail Email Log:', [
            'to' => $email,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $title,
            'message' => $message,
            'customer_id' => $customer_id,
            'pdf_attached' => !empty($pdfPath) ? basename($pdfPath) : 'No PDF',
        ]);

        // CC
        if (!empty($cc)) {
            $ccList = [];
            foreach ((array)$cc as $index => $ccEmail) {
                $ccList[] = [
                    "email_address" => [
                        "address" => $ccEmail,
                        "name" => $cc_names[$index] ?? $title
                    ]
                ];
            }
            $postData['cc'] = $ccList;
        }

        // BCC
        if (!empty($bcc)) {
            $bccList = [];
            foreach ((array)$bcc as $index => $bccEmail) {
                $bccList[] = [
                    "email_address" => [
                        "address" => $bccEmail,
                        "name" => $bcc_names[$index] ?? $title
                    ]
                ];
            }
            $postData['bcc'] = $bccList;
        }

        // Attach PDF if exists
        if (!empty($pdfPath) && file_exists($pdfPath)) {
            $pdfContent = file_get_contents($pdfPath);
            $base64PDF = base64_encode($pdfContent);
            $postData["attachments"] = [
                [
                    "name" => basename($pdfPath),
                    "content" => $base64PDF,
                    "mime_type" => "application/pdf"
                ]
            ];
        }

        // Send Email via Curl
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.zeptomail.in/v1.1/email",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: Zoho-enczapikey {$cdata->zoho_api_key}",
                "cache-control: no-cache",
                "content-type: application/json",
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            // Optional: Log error
            Log::error('ZeptoMail Error: ' . $err);
        } else {
            // Optional: Log success
            Log::info('ZeptoMail Response: ' . $response);
        }
    }
}

public  function sendEmailWithAgentSMTP($ticketId, $subject, $html, $toEmails = [],$finalized_agent_id = null)
    {
        // Step 1: Fetch agent data
       if (!empty($finalized_agent_id)) {
            $agentData = DB::table('superadmins')
                ->where('id', $finalized_agent_id)
                ->where("login_type",2)
                ->first();
          if(empty($agentData)){
             $agentData = DB::table('agent_assign_history as aah')
                ->join('superadmins as ag', 'aah.agent_id', '=', 'ag.id')
                ->where('aah.ticket_id', $ticketId)
                ->orderByDesc('ag.root_access') // Prioritize root_access = 1
                ->orderBy('ag.id')             // Fallback: lowest id
                ->select('ag.*')
                ->first();
          }      
        } else {
            $agentData = DB::table('agent_assign_history as aah')
                ->join('superadmins as ag', 'aah.agent_id', '=', 'ag.id')
                ->where('aah.ticket_id', $ticketId)
                ->orderByDesc('ag.root_access') // Prioritize root_access = 1
                ->orderBy('ag.id')             // Fallback: lowest id
                ->select('ag.*')
                ->first();
        }

        if (!$agentData || empty($agentData->smtp_server_name)) {
            return false; // Or throw exception/log error
        }

        try {
            // Step 2: Decrypt SMTP configuration
            $smtpHost = CryptService::decryptData($agentData->smtp_server_name);
            $smtpPort = CryptService::decryptData($agentData->smtp_port);
            $outlookEmail = CryptService::decryptData($agentData->smtp_username);
            $accessToken = CryptService::decryptData($agentData->smtp_password);
            $authMethod = CryptService::decryptData($agentData->authentication_method);
            $smtpEncryption = CryptService::decryptData($agentData->smtp_encryption);

            // Step 3: Build DSN
            $dsn = sprintf(
                'smtp://%s:%s@%s:%d?auth_mode=%s&encryption=%s',
                rawurlencode($outlookEmail),
                rawurlencode($accessToken),
                $smtpHost,
                $smtpPort,
                $authMethod,
                $smtpEncryption
            );

            // Step 4: Create transport and mailer
            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);

            // Step 5: Create email
             $html1 = str_replace(["\r", "\n"], '', $html);
            
            $html2 = str_replace('<p>', '<p style="margin:0;">', $html1);
            $email = (new Email())
                ->from($outlookEmail)
                ->to(...$toEmails)
                ->subject($subject)
                ->html($html2);

            // Step 6: Send the email
            $mailer->send($email);
            return true;
        } catch (\Exception $e) {
            // Log or handle exception
            Log::error('Email sending failed: ' . $e->getMessage());
            return false;
        }
    }


 
}