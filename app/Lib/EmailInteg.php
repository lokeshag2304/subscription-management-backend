<?php 

namespace App\Lib;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
class EmailInteg {
  public function __construct($customer_id=''){
    $setting = DB::table('setting')->first();
    $this->from_email = $customer_id;
  }
  
  

  function SendOTPNotification($mobile,$otp){
   $response = Http::withHeaders([
            'accept' => 'application/json',
            'authorization' => 'Zoho-enczapikey PHtE6r0PS73viWMvpBYE46e/EsekN4on+7thfwBA5NkWD/AHGU0HoosskDO1rR8oAPcQF/fJyophsrmY4L6DcWjqMj5LCGqyqK3sx/VYSPOZsbq6x00ftF0Sf0fcUIPrd9Rt0yPUvNnSNA==',
            'cache-control' => 'no-cache',
            'content-type' => 'application/json',
        ])->post('https://api.zeptomail.in/v1.1/email', [
            'from' => [
                'address' => 'shaanmsk4@gmail.com'
            ],
            'to' => [
                [
                    'email_address' => [
                        'address' => 'shaanmsk4@gmail.com',
                        'name' => 'Support'
                    ]
                ]
            ],
            'subject' => 'Test Email',
            'htmlbody' => '<div><b> Test email sent successfully. </b></div>',
        ]);
}



}