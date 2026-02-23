<?php 

namespace App\Lib;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
class WhatsappInteg {
  public function __construct($customer_id=''){
    $setting = DB::table('setting')->first();
    $this->user_id=$setting->wtsp_api_key;
   $this->password=$setting->wtsp_pass;


  }
  
  function insertNotification($uid,$mob,$user_id){
       $insert['mob'] = $mob;
       $insert['user_id'] = $uid;
       if(!empty(Session::get("customer_id"))){
        $insert['uid'] = Session::get("customer_id");
       }else {
        if(empty($uid)){
          $insert['uid'] = $user_id;  
        }else {
         $insert['uid'] = $user_id;
        }
       }
       DB::table('notification')->insert($insert);
  }

  function SendNotification($mobile,$message,$is_template=false,$customer_Idd=0){
    /* check opt in */
    // echo $this->is_template;exit;
    // $message = ($is_template==false)?urldecode($message):$message;
    //echo $mess
    // echo $message;exit;
    // if(!DB::table('opt_in_whtsp')->where('mobile',$mobile)->exists()){
    $url = "https://media.smsgupshup.com/GatewayAPI/rest?method=OPT_IN&format=json&userid=".$this->user_id."&password=".$this->password."&phone_number=".$mobile."&v=1.1&auth_scheme=plain&channel=WHATSAPP";
    // echo $url;exit;
   $response = Http::get($url);
  // }
    $url = "https://media.smsgupshup.com/GatewayAPI/rest?method=SendMessage&format=json&userid=".$this->user_id."&password=".$this->password."&send_to=".$mobile."&v=1.1&auth_scheme=plain&msg_type=HSM&msg=".$message."&isTemplate={$is_template}";
    // echo $url;exit;
   $response = Http::get($url);
   // $this->insertNotification($this->user_id,$mobile,$customer_Idd);
}


function SendNotificationOTP($mobile,$message,$is_template=false,$customer_Idd=0){
  /* check opt in */
  // echo $this->is_template;exit;
  // $message = ($is_template==false)?urldecode($message):$message;
  //echo $mess
  // echo $message;exit;
  // if(!DB::table('opt_in_whtsp')->where('mobile',$mobile)->exists()){
  $url = "https://media.smsgupshup.com/GatewayAPI/rest?method=OPT_IN&format=json&userid=".$this->user_id."&password=".$this->password."&phone_number=".$mobile."&v=1.1&auth_scheme=plain&channel=WHATSAPP";
  // echo $url;exit;
 $response = Http::get($url);
// }
  $url = "https://media.smsgupshup.com/GatewayAPI/rest?method=SendMessage&format=json&userid=".$this->user_id."&password=".$this->password."&send_to=".$mobile."&v=1.1&auth_scheme=plain&msg_type=TEXT&msg=".urlencode($message);
  // echo $url;exit;
 $response = Http::get($url);
 // $this->insertNotification($this->user_id,$mobile,$customer_Idd);
}





public function SendNotificationWithMedia($mobile,$message,$is_template=false,$media_url,$customer_Idd=0){
   if(!DB::table('opt_in_whtsp')->where('mobile',$mobile)->exists()){
    $url = "https://media.smsgupshup.com/GatewayAPI/rest?method=OPT_IN&format=json&userid=".$this->user_id."&password=".$this->password."&phone_number=".$mobile."&v=1.1&auth_scheme=plain&channel=WHATSAPP";
    // echo $url;exit;
   $response = Http::get($url);
  }
  if(strpos($media_url,'.mp4') !== false){
      $url = "https://media.smsgupshup.com/GatewayAPI/rest?method=SENDMEDIAMESSAGE&format=json&userid=".$this->user_id."&password=".$this->password."&send_to=".$mobile."&v=1.1&auth_scheme=plain&msg_type=VIDEO&caption=".$message."&isTemplate={$is_template}&media_url={$media_url}";
  }else {
      $url = "https://media.smsgupshup.com/GatewayAPI/rest?method=SENDMEDIAMESSAGE&format=json&userid=".$this->user_id."&password=".$this->password."&send_to=".$mobile."&v=1.1&auth_scheme=plain&msg_type=IMAGE&caption=".$message."&isTemplate={$is_template}&media_url={$media_url}";
  }
  
     // DB::table('chat')->insert([
     //        'str' => $url,
     //        'user_id' => "233", 
     //    ]);

    // echo $url;exit;
  // return json_encode(array('status'=>true,'message'=>$url));exit;
   $response = Http::get($url);  
    $this->insertNotification($this->user_id,$mobile,$customer_Idd);
}


 function SendNotification_2($mobile,$message,$is_template=false,$user,$pass,$customer_Idd=0){
    /* check opt in */
    // echo $this->is_template;exit;
    if(!DB::table('opt_in_whtsp')->where('mobile',$mobile)->exists()){
    $url = "https://media.smsgupshup.com/GatewayAPI/rest?method=OPT_IN&format=json&userid=".$user."&password=".$pass."&phone_number=".$mobile."&v=1.1&auth_scheme=plain&channel=WHATSAPP";
    // echo $url;exit;
   $response = Http::get($url);
  }
    $url = "https://media.smsgupshup.com/GatewayAPI/rest?method=SendMessage&format=json&userid=".$user."&password=".$pass."&send_to=".$mobile."&v=1.1&auth_scheme=plain&msg_type=HSM&msg=".$message."&isTemplate={$is_template}";
    // echo $url;exit;
    $this->insertNotification($this->user_id,$mobile,$customer_Idd);
   $response = Http::get($url);

}

}