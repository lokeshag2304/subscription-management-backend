<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use App\Services\CryptService;
use Carbon\Carbon;

class SendSubscriptionNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $subscription_id;
    public $notification_id;
    public $audience;
    public $template_id;

    public function __construct(array $data)
    {
        $this->subscription_id   = $data['subscription_id'];
        $this->notification_id   = $data['notification_id'];
        $this->audience          = $data['audience'];
        $this->template_id       = $data['template_id'];
    }

    public function handle()
    {
        try {
             \Log::info('SendSubscriptionNotification job started');
            // Fetch subscription details
            $subscription = DB::table('subscription')->where('id', $this->subscription_id)->first();
            if (!$subscription) return;
            \Log::info('stop after subscription');

            // Fetch client info from superadmins table
            $client = DB::table('superadmins')->where('id', $subscription->subadmin_id)->first();
            $clientName = $client ? CryptService::decryptData($client->name) : 'Client';

            // Fetch template
            $template = DB::table("templates")->where("id", $this->template_id)->first();
            if (!$template) return;
            \Log::info('stop after template');

            $activeTemplate  = CryptService::decryptData($template->template);
            $subjectTemplate = CryptService::decryptData($template->subject);

            // Compute days remaining / overdue
            $today = Carbon::today();
            $endDate = Carbon::parse($subscription->end_date);
            $daysRemaining = 0;
            $daysOverdue = 0;

            if ($endDate->isFuture()) {
                $daysRemaining = $today->diffInDays($endDate);
            } elseif ($endDate->isPast()) {
                $daysOverdue = $today->diffInDays($endDate);
            }

            // Audience info
            $audienceData = $this->audience;
            $placeholders = [
                '{client_name}',
                '{start_date}',
                '{end_date}',
                '{days_remaining}',
                '{days_overdue}'
            ];

            $values = [
                $clientName,
                $subscription->start_date,
                $subscription->end_date,
                $daysRemaining,
                $daysOverdue
            ];

            // Replace placeholders in subject and template
            $subject = str_replace($placeholders, $values, $subjectTemplate);
            $finalMessage = str_replace($placeholders, $values, $activeTemplate);
           \Log::info('Audience array:', $audienceData);

            // Send email
            // $subject = 'ddd';
            $EmailCurl = new \App\Lib\EmailCurl();
            $EmailCurl->SendNotificationMForSLA(
                $audienceData,
                $finalMessage,
                $subject,
                0,
                null
            );

            // // Mark notification as sent
            // DB::table('subscription_notification')
            //     ->where('id', $this->notification_id)
            //     ->update(['sent_at' => now()]);

        } catch (\Exception $e) {
            \Log::error("Subscription Notification Error: " . $e->getMessage());
        }
    }
}
