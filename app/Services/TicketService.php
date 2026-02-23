<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Services\CryptService;
use Carbon\Carbon;

use Illuminate\Http\Request;


use App\Services\SlaService;
use App\Services\PusherTicketNotifier;


class TicketService
{
    /**
     * Close a ticket by ID and Admin ID.
     *
     * @param int $ticketId
     * @param int $adminId
     * @param int|null $status
     * @return array
     */
    public function closeTicket($ticketId, $adminId, $status = null)
    {
        if (empty($ticketId) || empty($adminId)) {
            return [
                'status' => false,
                'message' => 'Missing required parameters: ticket_id or admin_id'
            ];
        }

        try {
            DB::beginTransaction();

            $agent = DB::table('superadmins')->where('id', $adminId)->first();
            $agentName = $agent && !empty($agent->name)
                ? CryptService::decryptData($agent->name)
                : 'Unknown Agent';

            $ticket = DB::table('tickets')->where('id', $ticketId)->first();
            if (!$ticket) {
                return ['status' => false, 'message' => 'Ticket not found'];
            }

            $statusNames = DB::table('status')
                ->where("subadmin_id", $ticket->subadmin_id)
                ->pluck('name', 'status as id')
                ->toArray();

            $closureDate = now();

            // ✅ Check agent permission
            if ($agent && $agent->login_type == 2 && $agent->root_access == 0) {
                $ticketRouting = DB::table('ticket_routing')
                    ->where('customer_id', $ticket->customer_id)
                    ->where("subadmin_id", $ticket->subadmin_id)
                    ->first();
                    // dd($changeStatus);
                if(!empty($ticketRouting)){
                    $closableAgents = [];
                    if ($ticketRouting && $ticketRouting->closable_agent_ids) {
                        $closableAgents = json_decode($ticketRouting->closable_agent_ids, true);
                    }

                    if (!in_array($agent->id, $closableAgents)) {
                        return [
                            'status' => false,
                            'message' => 'Agent does not have permission to close this ticket'
                        ];
                    }
                }
            }

            // ✅ Calculate resolution time
            $existingResolution = $ticket->resolution_time ?? 0;
            if ($existingResolution == 0) {
                $startTime = Carbon::parse($ticket->added_at);
                $resolutionMinutes = $startTime->diffInMinutes($closureDate);
                $totalResolution = $resolutionMinutes;
            } else {
                if (!is_null($ticket->reopen_at) && $ticket->reopen_at !== '0000-00-00 00:00:00') {
                    $startTime = Carbon::parse($ticket->reopen_at);
                    $resolutionMinutes = $startTime->diffInMinutes($closureDate);
                    $totalResolution = $existingResolution + $resolutionMinutes;
                } else {
                    $totalResolution = $existingResolution;
                }
            }

            // ✅ Update ticket
            DB::table('tickets')->where('id', $ticketId)->update([
                'status' => $status ?? $ticket->status,
                'closed_by' => $adminId,
                'closure_date' => $closureDate,
                'resolution_time' => $totalResolution,
                'updated_at' => now(),
                'reopen_at' => ''
            ]);

            // ✅ Update SLA table
            DB::table('active_slas')
                ->where('ticket_id', $ticketId)
                ->update(['has_responded' => 1, 'has_resolved' => 1, 'check_in_depth' => 1]);

            // ✅ Add to activity log
            $ticketMessage = 'Ticket closed successfully';
            DB::table('activities')->insert([
                'action' => CryptService::encryptData('Ticket Closed'),
                'user_id' => $adminId,
                'message' => CryptService::encryptData($ticketMessage),
                'ticket_id' => $ticketId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return [
                'status' => true,
                'message' => 'Ticket closed successfully',
                'ticket_id' => $ticketId,
                'closed_by' => $agentName,
                'new_status' => $statusNames[$status ?? $ticket->status] ?? $ticket->status
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            return [
                'status' => false,
                'message' => 'Error closing ticket: ' . $e->getMessage()
            ];
        }
    }


    public function changeStatus($adminId,$ticketId,$status)
    {
        if (empty($adminId) || empty($ticketId) || empty($status)) {
            return response()->json(['status' => false, 'message' => 'Missing required parameters'], 200);
        }
        // echo $adminId;
        // echo $ticketId;
        // echo $status;exit;

        try {
            DB::beginTransaction();

            // Get agent data
            $agent = DB::table('superadmins')->where('id', $adminId)->first();
            $agentName = !empty($agent->name) ? CryptService::decryptData($agent->name) : 'Unknown Agent';

            // Get ticket and creator
            $ticket = DB::table('tickets')->where('id', $ticketId)->first();
            $ticketCreator = DB::table('superadmins')->where('id', $ticket->customer_id)->first();
            $oldStatus = $ticket->status ?? null;

            $subadminData = DB::table('superadmins')->where('id', $ticket->subadmin_id)->first();
            $prefixSetting = DB::table('setting')
                                ->where('subadmin_id', $ticket->subadmin_id)
                                ->first();

            $statusNames = DB::table('status')->where("subadmin_id", $ticket->subadmin_id)->pluck('name', 'status as id')->toArray();
            $closure_date = now();

            // ✅ Add closure permission check for agent
            if ($status == 4 && $agent) {
                if ($agent->login_type == 2) {
                    if ($agent->root_access == 0) {
                        $ticketRouting = DB::table('ticket_routing')
                            ->where('customer_id', $ticket->customer_id)
                            ->where("subadmin_id", $ticket->subadmin_id)
                            ->first();
                        if(!empty($ticketRouting)){
                            $closableAgents = [];
                            if ($ticketRouting && $ticketRouting->closable_agent_ids) {
                                $closableAgents = json_decode($ticketRouting->closable_agent_ids, true);
                            }

                            if (!in_array($agent->id, $closableAgents)) {
                                return response()->json([
                                    'status' => false,
                                    'message' => 'Ticket closure is restricted based on your access level. Kindly contact your administrator for clarification'
                                ], 200);
                            }
                        }
                    }
                }
            }

            // ✅ Handle status update logic
            if ($status == 4) {
                $existing_resolution_time = $ticket->resolution_time ?? 0;

                if ($existing_resolution_time == 0) {
                    // First closure
                    $start_time = Carbon::parse($ticket->added_at);
                    $resolution_minutes = $start_time->diffInMinutes($closure_date);
                    $total_resolution_time = $resolution_minutes;
                } else {
                    // Re-closure
                    if (!is_null($ticket->reopen_at) && $ticket->reopen_at !== '0000-00-00 00:00:00') {
                        $start_time = Carbon::parse($ticket->reopen_at);
                        $resolution_minutes = $start_time->diffInMinutes($closure_date);
                        $total_resolution_time = $existing_resolution_time + $resolution_minutes;
                    } else {
                        $total_resolution_time = $existing_resolution_time;
                    }
                }

                DB::table('tickets')->where('id', $ticketId)->update([
                    'status' => $status,
                    'closed_by' => $adminId,
                    'closure_date' => $closure_date,
                    'resolution_time' => $total_resolution_time,
                    'updated_at' => now(),
                    'reopen_at' => ''
                ]);

                DB::table('active_slas')
                    ->where('ticket_id', $ticketId)
                    ->update(['has_responded' => 1, 'has_resolved' => 1, 'check_in_depth' => 1]);
            } elseif ($status == 1 && in_array($oldStatus, [2, 3, 4])) {
                DB::table('tickets')->where('id', $ticketId)->update([
                    'status' => 1,
                    'reopen_at' => now(),
                    'updated_at' => now()
                ]);

                $slaService = new SlaService();
                $slaId = $slaService->applySlaToTicket($ticket->id, $ticket->priority, 0);
            } elseif ($status == 3) {
                $holdDate = now();
                $existing_resolution_time = $ticket->resolution_time ?? 0;

                if ($existing_resolution_time == 0) {
                    $start_time = Carbon::parse($ticket->added_at);
                    $resolution_minutes = $start_time->diffInMinutes($holdDate);
                    $total_resolution_time = $resolution_minutes;
                } else {
                    if (!is_null($ticket->reopen_at) && $ticket->reopen_at !== '0000-00-00 00:00:00') {
                        $start_time = Carbon::parse($ticket->reopen_at);
                        $resolution_minutes = $start_time->diffInMinutes($holdDate);
                        $total_resolution_time = $existing_resolution_time + $resolution_minutes;
                    } else {
                        $total_resolution_time = $existing_resolution_time;
                    }
                }

                DB::table('tickets')->where('id', $ticketId)->update([
                    'status' => 3,
                    'on_hold_at' => $holdDate,
                    'resolution_time' => $total_resolution_time,
                    'notified_72h' => 0,
                    'notified_24h' => 0,
                    'reopen_at' => '',
                    'updated_at' => now(),
                    // 'companyName' => CryptService::decryptData($subadminData->company_name),
                    // 'companyLogo' => asset($prefixSetting->logo),
                ]);

                DB::table('active_slas')
                    ->where('ticket_id', $ticketId)
                    ->update(['has_resolved' => 1, 'check_in_depth' => 1]);
            } else {
                // echo $ticketId;
                // echo $status;exit;
                DB::table('tickets')->where('id', $ticketId)->update([
                    'status' => $status,
                    'updated_at' => now()
                ]);
            }

            // ✅ Prepare mail data
            $username = CryptService::decryptData($ticketCreator->name);
            $targetEmail = CryptService::decryptData($ticketCreator->email);
            $ticketTitle = CryptService::decryptData($ticket->subject);

            $agentIds = DB::table('agent_assign_history')
                ->where('ticket_id', $ticketId)
                ->pluck('agent_id');

            $footer_team_name = $agent && $agent->footer_team_name
                ? CryptService::decryptData($agent->footer_team_name)
                : null;

            $footer_email = $agent && $agent->footer_email
                ? CryptService::decryptData($agent->footer_email)
                : null;

            // ✅ Send closure mail
            if ($status == 4 && $ticket->notification_block != 2) {
                $body = view('emails.ticket_closure_notification', [
                    'userName' => $username,
                    'ticketId' => $ticket->ticket_id,
                    'ticketTitle' => $ticketTitle,
                    'ticketStatus' => $status ?? 'Open',
                    'closedBy' => $agentName,
                    'closedOn' => $closure_date,
                    'footer_team_name' => $footer_team_name,
                    'footer_email' => $footer_email,
                    'companyName' => CryptService::decryptData($subadminData->company_name),
                    'companyLogo' => asset($prefixSetting->logo),
                ])->render();

                $subject = "Support Ticket #{$ticket->ticket_id} Has Been Closed";
                $EmailCurl = new \App\Lib\EmailCurl();
                $EmailCurl->sendEmailWithAgentSMTP($ticketId, $subject, $body, [$targetEmail], $adminId);
            }

            // ✅ Send reopen mail
            if ($status == 1 && $oldStatus == 4 && $ticket->notification_block != 2) {
                $body = view('emails.ticket_reopen', [
                    'userName' => $username,
                    'ticketId' => $ticket->ticket_id,
                    'ticketTitle' => $ticketTitle,
                    'ticketStatus' => 'Reopened',
                    'closedBy' => $agentName,
                    'closedOn' => $closure_date,
                    'footer_team_name' => $footer_team_name,
                    'footer_email' => $footer_email,
                    'companyName' => CryptService::decryptData($subadminData->company_name),
                    'companyLogo' => asset($prefixSetting->logo),
                ])->render();

                $subject = "Support Ticket #{$ticket->ticket_id} Has Been Reopened";
                $EmailCurl = new \App\Lib\EmailCurl();
                $EmailCurl->sendEmailWithAgentSMTP($ticketId, $subject, $body, [$targetEmail], $adminId);
            }

            // ✅ Log activity
            if ($oldStatus == 4 && $status == 1) {
                $activityMessage = sprintf("%s reopened the ticket", $agentName);
            } else {
                $activityMessage = sprintf(
                    "%s changed ticket status from %s to %s",
                    $agentName,
                    $oldStatus ? ($statusNames[$oldStatus] ?? $oldStatus) : 'N/A',
                    $statusNames[$status] ?? $status
                );
            }

            $ticketMessage = '#' . $ticket->ticket_id . ' — ' . $activityMessage;

            DB::table('activities')->insert([
                'action' => CryptService::encryptData('Status Changed'),
                'user_id' => $adminId,
                'message' => CryptService::encryptData($ticketMessage),
                'ticket_id' => $ticketId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            PusherTicketNotifier::notifyAssignedAgents(
                $ticketId,
                $adminId
            );

            return response()->json([
                'status' => true,
                'message' => 'Ticket status updated successfully',
                'new_status' => $statusNames[$status] ?? $status
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to update ticket status: ' . $e->getMessage()
            ], 500);
        }
    }
}
