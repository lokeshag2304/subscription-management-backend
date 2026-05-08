<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\CryptService;
use Carbon\Carbon;

class SubscriptionNotificationCommand extends Command
{
    protected $signature = 'subscription:notify';
    protected $description = 'Send notifications for active subscriptions based on subscription_notification table';

    public function handle()
    {
        $today = Carbon::now()->startOfDay(); // Only date comparison

        // 1️⃣ Get all active subscriptions with notifications enabled
        $subscriptions = DB::table('subscription')
            ->where('status', 1)
            ->where('notification_status', 1)
            ->get();
        // Log::info("hi");
        
        foreach ($subscriptions as $sub) {
            $subId = $sub->id;
            // $this->info($sub->end_date);

            // 2️⃣ Get notifications for this subscription
            $notifications = DB::table('subscription_notification')
                ->where('subscription_id', $subId)
                ->get();

            foreach ($notifications as $noti) {
                try {
                    // 3️⃣ Calculate notification date based on time_type
                    // time_type 1 = before start_date, 2 = after start_date
                    // $this->info($sub->end_date);
                    $notificationDate = Carbon::parse($sub->end_date);

                   if ($noti->time_type == 1) {
                        $notificationDate->subDays($noti->no_of_days);
                    } elseif ($noti->time_type == 2) {
                        $notificationDate->addDays($noti->no_of_days);
                    } elseif ($noti->time_type == 3) {
                        
                    }


                    $notificationDate->startOfDay();
                    

                    // 4️⃣ Check if today matches notification date
                    if ($notificationDate->eq($today)) {
                        // $this->info('yes its today');
                        $audienceIds = json_decode(CryptService::decryptData($noti->audience));
                         $audienceEmails = DB::table('superadmins')
                            ->whereIn('id', $audienceIds)
                            ->pluck('email') // get the encrypted email column
                            ->map(function ($email) {
                                return CryptService::decryptData($email);
                            })
                            ->toArray();
                            $this->info(print_r($audienceEmails, true));
                        dispatch(new \App\Jobs\SendSubscriptionNotification([
                            'subscription_id' => $subId,
                            'notification_id' => $noti->id,
                            'audience' => array("gopalsh022@gmail.com","vignesh.s@flyingstars.biz"),
                            'template_id' => $noti->template_id
                        ]));

                        // Log::info("Notification {$noti->id} dispatched for subscription {$subId} to audience: {$audienceEmails}");
                    }

                } catch (\Exception $e) {
                    Log::error("Error processing notification {$noti->id} for subscription {$subId}: " . $e->getMessage());
                }
            }
        }

        $this->info("Subscription notifications processed for " . count($subscriptions) . " subscriptions.");
    }
}
