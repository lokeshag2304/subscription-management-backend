<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\CryptService;  // Adjust if needed, depends on your namespace

class SlaService
{
    public function applySlaToTicket($ticket_id, $newValue,$type=1)
    {
        // echo $ticket_id;exit;
        $ticket = DB::table("tickets")->where("id", $ticket_id)->first();
        $subadmin_id = $ticket->subadmin_id;
        $customerData = DB::table("superadmins")->where("id", $ticket->customer_id)->first();
        // dd($customerData);
        if (!$customerData) {
            return false; // or throw exception or handle as per your app logic
        }

        $customer_name = CryptService::decryptData($customerData->name);

        $agents = DB::table('agent_assign_history as aah')
            ->join('superadmins as a', 'a.id', '=', 'aah.agent_id')
            ->where('aah.ticket_id', $ticket->id)
            ->select('a.name')
            ->get();

        $agent_names2 = $agents->map(function ($agent) {
            return CryptService::decryptData($agent->name);
        })->toArray();

        $agent_names = implode(' & ', $agent_names2);

        $encrypted_customer_mail = $customerData->email;

        $any_sla_for_apply = DB::table('sla_targets')
            ->join('sla', 'sla.id', '=', 'sla_targets.sla_id')
            ->where('sla_targets.email', $encrypted_customer_mail)
            ->where("sla.subadmin_id",$subadmin_id)
            ->where('priority', $newValue)
            ->select('sla_targets.*', 'sla.priority')
            ->first();
        //  dd($any_sla_for_apply)
        if (empty($any_sla_for_apply)) {
            return false;  // no SLA applicable
        }

        $sladata = DB::table("sla")->where("id", $any_sla_for_apply->sla_id)->first();

        $now = Carbon::now('Asia/Kolkata');

        $responseMinutes = (int) $sladata->response_time_minute;
        $resolutionMinutes = (int) $sladata->resolution_time_minute;

        $sla_response_deadline = $now->copy()->addMinutes($responseMinutes)->format('d M Y h:i A');
        $sla_resolution_deadline = $now->copy()->addMinutes($resolutionMinutes)->format('d M Y h:i A');

        $response_breach_time = $sla_response_deadline;
        $resolution_breach_time = $sla_resolution_deadline;

        $formattedCreationDate = Carbon::parse($ticket->created_at)
            ->timezone('Asia/Kolkata')
            ->format('d M Y h:i A');

        $ticket_info = [
            "ticket_id" => $ticket->ticket_id,
            "agent_name" => $agent_names,
            'customer_name' => $customer_name,
            'creation_date' => $formattedCreationDate,
            'sla_response_deadline' => $sla_response_deadline,
            'sla_resolution_deadline' => $sla_resolution_deadline,
            'response_breach_time' => $response_breach_time,
            'resolution_breach_time' => $resolution_breach_time
        ];

        $slaRoles = DB::table('sla_roles')
            ->where('sla_id', $any_sla_for_apply->sla_id)
            ->get();

        foreach ($slaRoles as $role) {
            $roleName = CryptService::decryptData($role->role_name);
            $formattedRoleKey = str_replace(' ', '_', strtolower($roleName));

            $agent = DB::table('superadmins')->where('id', $role->agent_id)->first();

            if ($agent) {
                $ticket_info[$formattedRoleKey . '_name'] = CryptService::decryptData($agent->name);
            }
        }
        
        $now = now();
        $oldSla = null;
        if($type==1){
            $ticket_added_at = $ticket->first_sla_applied_date ?? $now;
        
            if (!empty($ticket->active_sla_id)) {
                $oldSla = DB::table('active_slas')->where("id", $ticket->active_sla_id)->first();
            }
        }else {
          $ticket_added_at = $now;  
        }

        $activeSlaId = DB::table('active_slas')->insertGetId([
            'ticket_id' => $ticket->id,
            'sla_id' => $any_sla_for_apply->sla_id,
            'subadmin_id' =>$subadmin_id,
            'ticket_added_at' => $ticket_added_at,
            'has_responded' => $oldSla->has_responded ?? 0,
            'sla_applied_at' => $now,
            'status' => 1,
            'ticket_info' => json_encode($ticket_info)
        ]);

        if (!empty($ticket->active_sla_id)) {
            DB::table('active_slas')
                ->where('id', $ticket->active_sla_id)
                ->update(['has_sla_cancelled' => 1]);
        }
        // echo $ticket_added_at;exit;
        DB::table('tickets')
            ->where('id', $ticket->id)
            ->update(['active_sla_id' => $activeSlaId, 'first_sla_applied_date' => $ticket_added_at]);

        return true; // or return the active SLA id or ticket info as needed
    }
}
