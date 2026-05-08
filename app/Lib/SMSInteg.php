<?php 

namespace App\Lib;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
class SMSInteg {
  public function __construct($customer_id=''){
    $setting = DB::table('setting')->first();
    $this->user_id=$setting->sms_api_key;
   $this->password=$setting->sms_secret_key;


  }
  
  

  function SendOTPNotification($mobile,$message){

    $url ="http://bulksms.flyingstars.co/GatewayAPI/rest?loginid=".$this->user_id."&password=".$this->password."&send_to=$mobile&msg=" . rawurlencode($message) . "&method=SendMessage&msg_type=TEXT&auth_scheme=plain&v=1.1&format=text";
    // echo $url;exit;
    $response = Http::get($url);
   // $this->insertNotification($this->user_id,$mobile,$customer_Idd);
}



}