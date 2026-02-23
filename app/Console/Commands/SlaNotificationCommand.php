<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\PusherTicketNotifier;
use App\Services\CryptService;
use Carbon\Carbon;


class SlaNotificationCommand extends Command
{
    protected $signature = 'sla:notify'; // This will be your command

    protected $description = 'Dispatch SLA notifications';
    

    

    private function notifyIdleClients()
    {
        $now = now();

        // Get all subadmins' settings
        $subadmins = DB::table("setting")->get();

        foreach ($subadmins as $setting) {
            $subadminId = $setting->subadmin_id;

            $fnm = $setting?->first_notification_minutes;     // e.g., 24h
            $lnm = $setting?->last_notification_minutes;      // e.g., 72h
            $cnm = $setting?->closure_notification_minutes;   // e.g., 96h

            $fnt = $setting?->first_notification_template;
            $lnt = $setting?->last_notification_template;
            $cnt = $setting?->closure_notification_template;

            // 1️⃣ Notify at 24h
            $tickets24 = DB::table('tickets')
                ->where('subadmin_id', $subadminId)   // 🔑 filter by subadmin
                ->where('status', 3)
                ->whereNotNull('on_hold_at')
                ->whereBetween('on_hold_at', [
                    $now->copy()->subMinutes($lnm),
                    $now->copy()->subMinutes($fnm)
                ])
                ->where(function ($q) {
                    $q->whereNull('notified_24h')
                    ->orWhere('notified_24h', '!=', 1);
                })
                ->get();

            foreach ($tickets24 as $ticket) {
                try {
                    if ($ticket->notification_block == 1) {
                        dispatch(new \App\Jobs\NotifyTicketIdle24Hours((array)$ticket, $fnt,$subadminId));
                    }
                    DB::table('tickets')
                        ->where('id', $ticket->id)
                        ->update(['notified_24h' => 1]);
                } catch (\Exception $e) {
                    Log::error("24h Notification Error - Ticket ID {$ticket->id}: " . $e->getMessage());
                }
            }

            // 2️⃣ Notify at 72h
            $tickets72 = DB::table('tickets')
                ->where('subadmin_id', $subadminId)
                ->where('status', 3)
                ->whereNotNull('on_hold_at')
                ->whereBetween('on_hold_at', [
                    $now->copy()->subMinutes($cnm),
                    $now->copy()->subMinutes($lnm)
                ])
                ->where(function ($q) {
                    $q->whereNull('notified_72h')
                    ->orWhere('notified_72h', '!=', 1);
                })
                ->get();

            foreach ($tickets72 as $ticket) {
                try {
                    if ($ticket->notification_block == 1) {
                        dispatch(new \App\Jobs\NotifyAndCloseTicket72Hours((array)$ticket, $lnt,$subadminId));
                    }
                    DB::table('tickets')
                        ->where('id', $ticket->id)
                        ->update(['notified_72h' => 1]);
                } catch (\Exception $e) {
                    Log::error("72h Notification Error - Ticket ID {$ticket->id}: " . $e->getMessage());
                }
            }

            // 3️⃣ Auto Close Tickets
            $closureTickets = DB::table('tickets')
                ->where('subadmin_id', $subadminId)
                ->where('status', 3)
                ->whereNotNull('on_hold_at')
                ->where('on_hold_at', '<=', $now->copy()->subMinutes($cnm))
                ->where('notified_24h', 1)
                ->where('notified_72h', 1)
                ->get();

            foreach ($closureTickets as $ticket) {
                try {
                    DB::table('tickets')->where('id', $ticket->id)->update([
                        'status' => 4,
                        'closed_by' => 6,
                        'closure_date' => $now,
                        'updated_at' => $now,
                        'reopen_at' => ''
                    ]);

                    DB::table('active_slas')
                        ->where('ticket_id', $ticket->id)
                        ->update([
                            'has_responded' => 1,
                            'has_resolved' => 1,
                            'check_in_depth' => 1
                        ]);

                    if ($ticket->notification_block == 1) {
                        dispatch(new \App\Jobs\NotifyTicketClosure((array)$ticket, $cnt));
                    }

                    PusherTicketNotifier::notifyAssignedAgents($ticket->id);
                } catch (\Exception $e) {
                    Log::error("Closure Notification Error - Ticket ID {$ticket->id}: " . $e->getMessage());
                }
            }

            $this->info("Subadmin {$subadminId} → 24h (" . count($tickets24) . "), 72h (" . count($tickets72) . "), Closures (" . count($closureTickets) . ")");
        }
    }


    public function handle()
    {  
            // $nowFormatted = now()->format('Y-m-d H:i');
            // $active_slas = DB::table("active_slas")
            //     ->where('has_sla_done', 0)
            //     ->where("has_resolved",0)
            //     ->where("has_sla_cancelled",0)
            //     ->where(function ($query) use ($nowFormatted) {
            //         $query->where('check_in_depth', 1)
            //             ->orWhereRaw("DATE_FORMAT(upcoming_noti_time, '%Y-%m-%d %H:%i') = ?", [$nowFormatted]);
            //     })
            //     ->get();

           $now = now(); // Carbon instance
            $twoMinutesBefore = $now->copy()->subMinutes(1);

            // Format when you actually need the string
            $nowFormatted = $now->format('Y-m-d H:i');
            $twoMinutesBeforeFormatted = $twoMinutesBefore->format('Y-m-d H:i');


            $active_slas = DB::table("active_slas")
                ->where('has_sla_done', 0)
                ->where('has_resolved', 0)
                ->where('has_sla_cancelled', 0)
                ->where(function ($query) use ($twoMinutesBefore, $now) {
                    $query->where('check_in_depth', 1)
                        ->orWhereBetween('upcoming_noti_time', [$twoMinutesBefore, $now]);
                })
                ->get();

        foreach ($active_slas as $as) {
            try {
                dispatch(new \App\Jobs\ProcessSlaNotification((array)$as));
             } catch (\Exception $e) {
            \Log::error('Error processing row: ' . $e->getMessage());
            continue; // if in a loop
        }
        }

        $this->info("Dispatched SLA Jobs: " . count($active_slas));
        $this->notifyIdleClients();
    }
}
