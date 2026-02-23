<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use App\Lib\SMSinteg;
use App\Lib\Whatsappinteg;
use App\Lib\EmailInteg;
use Carbon\Carbon;
use App\Services\CryptService;
use App\Services\CustomCipherService;

class SearchController extends Controller
{


public function searchTickets(Request $request)
{
    $data = json_decode($request->getContent(), true);

    $admin_id = $data['admin_id'] ?? null;
    $login_type = $data['login_type'] ?? null;
    $searchTerm = $data['search'] ?? '';
    $subadmin_id = $data['subadmin_id'] ?? null;

    if (!$admin_id || !$login_type || empty($searchTerm)) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid input or login_type',
        ]);
    }

    $matchedTickets = [];

    if ($login_type == 1 || $login_type == 4) {
        $query = DB::table('tickets')
        ->select('id as ticket_id', 'ticket_id as ticket_number', 'subject')
        ->whereNotNull('subject');

        if (!empty($subadmin_id)) {
            $query->where('tickets.subadmin_id', $subadmin_id);
        }

        $tickets = $query->get();

    } elseif ($login_type == 3) {
       $query = DB::table('tickets')
        ->select('id as ticket_id', 'ticket_id as ticket_number', 'subject')
        ->where('customer_id', $admin_id)
        ->whereNotNull('subject');

        if (!empty($subadmin_id)) {
            $query->where('tickets.subadmin_id', $subadmin_id);
        }

        $tickets = $query->get();

    } elseif ($login_type == 2) {
        $ticketIds = DB::table('agent_assign_history')
        ->where('agent_id', $admin_id)
        ->pluck('ticket_id')
        ->unique()
        ->toArray();

        $query = DB::table('tickets')
            ->select('id as ticket_id', 'ticket_id as ticket_number', 'subject')
            ->whereIn('id', $ticketIds)
            ->whereNotNull('subject');

        if (!empty($subadmin_id)) {
            $query->where('tickets.subadmin_id', $subadmin_id);
        }

        $tickets = $query->get();

    } 

    // Match search term in either decrypted subject or ticket_number
    foreach ($tickets as $ticket) {
        $decryptedSubject = CryptService::decryptData($ticket->subject);
        $ticketNumber = $ticket->ticket_number;

        if (
            stripos($decryptedSubject, $searchTerm) !== false ||
            stripos($ticketNumber, $searchTerm) !== false
        ) {
            $matchedTickets[] = [
                'ticket_id' => $ticket->ticket_id,
                'ticket_number' => $ticketNumber,
                's_subject' => $decryptedSubject,
            ];
        }
    }

    return response()->json([
        'success' => true,
        'results' => $matchedTickets,
    ]);
}


public function getNotifications(Request $request)
{
    $data = json_decode($request->getContent(), true);

    $login_type = $data['login_type'] ?? null;
    $admin_id = $data['admin_id'] ?? null;
    $subadmin_id = $data['subadmin_id'] ?? $request->input('subadmin_id');

    if (is_null($login_type)) {
        return response()->json([
            'success' => false,
            'message' => 'login_type is required.'
        ], 400);
    }

    try {
        $query = DB::table('notifications');

        // Filter based on login_type
        if ($login_type == 1 || $login_type == 2 || $login_type == 4) {
            if (is_null($admin_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'admin_id is required for agent.'
                ], 400);
            }
            $query->where('agent_id', $admin_id);
        } elseif ($login_type == 3) {
            if (is_null($admin_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'admin_id is required for customer.'
                ], 400);
            }
            $query->where('customer_id', $admin_id);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Invalid login_type.'
            ], 400);
        }
        if(!empty($subadmin_id)){
        $query->where('subadmin_id', $subadmin_id);
        }

        // ✅ Only unread notifications
        $query->where('is_read', 0);

        // $sql = $query->toSql();
        // $bindings = $query->getBindings();
        // dd(vsprintf(str_replace('?', '%s', $sql), $bindings));

        // Get unread count
        $unreadCount = $query->count();

        // Get latest 5 unread notifications
        $notifications = $query->orderByDesc('id')->limit(5)->get();

        $result = $notifications->map(function ($item) {
            $decrypted = $item->title;

            try {
                $maybeDecrypted = CryptService::decryptData($item->title);
                if (!empty($maybeDecrypted) && $maybeDecrypted !== $item->title) {
                    $decrypted = $maybeDecrypted;
                }
            } catch (\Exception $e) {
                $decrypted = $item->title;
            }

            return [
                'id' => $item->id,
                'reffrence_id' => $item->reffrence_id,
                's_title' => $decrypted,
                'created_at' => $item->created_at,
            ];
        });

        $settings = DB::table('setting')
            ->select('notification_status', 'notification_tune')
            ->first();

        $notificationTune = null;

        if ($settings && $settings->notification_status == 1) {
            $notificationTune = $settings->notification_tune;
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'notification_tune' => $notificationTune,
            'unread_noti_count' => $unreadCount
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Something went wrong.',
            'error' => $e->getMessage()
        ], 500);
    }
}


public function getNotificationslist(Request $request)
{
    $page = $request->input('page', 0);
    $rowsPerPage = $request->input('rowsPerPage', 10);
    $offset = $page * $rowsPerPage;

    $search = $request->input('search', '');
    $encryptedSearch = $search ? CustomCipherService::encryptData($search) : '';

    $admin_id = $request->input('admin_id');
    $subadmin_id = $request->input('subadmin_id');
    $login_type = $request->input('login_type');

    if (is_null($login_type) || is_null($admin_id)) {
        return response()->json([
            'success' => false,
            'message' => 'login_type and admin_id are required.'
        ], 400);
    }

    try {
        $baseQuery = DB::table('notifications')
            ->leftJoin('superadmins as agent', 'notifications.agent_id', '=', 'agent.id')
            ->leftJoin('superadmins as customer', 'notifications.customer_id', '=', 'customer.id')
            ->select(
                'notifications.id',
                'notifications.s_title',
                'notifications.title',
                'notifications.agent_id',
                'notifications.customer_id',
                'notifications.is_read',
                'notifications.created_at',
                'notifications.reffrence_id',
                DB::raw('COALESCE(agent.name, customer.name) as created_by')
            );

        if ($login_type == 2 || $login_type == 1 || $login_type == 4) {
            $baseQuery->where('notifications.agent_id', $admin_id);
        } elseif ($login_type == 3) {
            $baseQuery->where('notifications.customer_id', $admin_id);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Invalid login_type'
            ], 400);
        }

        if ($search || $encryptedSearch) {
            $baseQuery->where(function ($q) use ($search, $encryptedSearch) {
                if ($encryptedSearch) {
                    $q->where('notifications.s_title', 'like', '%' . $encryptedSearch . '%');
                }
                $q->orWhere('notifications.s_title', 'like', '%' . $search . '%');
            });
        }
        $baseQuery->where('notifications.subadmin_id', $subadmin_id);

        $paginatedQuery = clone $baseQuery;
        $notifications = $paginatedQuery
            ->orderByDesc('notifications.id')
            ->offset($offset)
            ->limit($rowsPerPage)
            ->get();

        $idsToMarkRead = $notifications->pluck('id')->toArray();

        // ✅ Updated: Count only unread notifications where is_read = 0
        $unreadCountQuery = clone $baseQuery;
        $unreadCountQuery->where('notifications.is_read', 0);
        $unreadCount = $unreadCountQuery->count();

        $totalRows = (clone $baseQuery)->count();

        // if (!empty($idsToMarkRead)) {
        //     DB::table('notifications')
        //         ->whereIn('id', $idsToMarkRead)
        //         ->update(['is_read' => 1]);
        // }

        foreach ($notifications as $item) {
            try {
                $decrypted = CryptService::decryptData($item->title);
                if (!empty($decrypted) && $decrypted !== $item->title) {
                    $item->title = $decrypted;
                }
            } catch (\Exception $e) {}

            try {
                $item->created_by = CryptService::decryptData($item->created_by);
            } catch (\Exception $e) {}
        }

        return response()->json([
            'success' => true,
            'rows' => $notifications,
            'total' => $totalRows,
            'unread_noti_count' => $unreadCount
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Something went wrong.',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function markAsRead(Request $request)
{
    $id = $request->input('id');

    if (empty($id)) {
        return response()->json([
            'success' => false,
            'message' => 'Notification ID is required.'
        ], 400);
    }

    $updated = DB::table('notifications')
        ->where('id', $id)
        ->update(['is_read' => 1]);

    if ($updated) {
        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.'
        ]);
    } else {
        return response()->json([
            'success' => false,
            'message' => 'Notification not found or already read.'
        ]);
    }
}


public function markAsReadAll(Request $request)
{
    $admin_id = $request->input('admin_id');

    if (empty($admin_id)) {
        return response()->json([
            'success' => false,
            'message' => 'Notification ID is required.'
        ], 400);
    }

    $updated = DB::table('notifications')
        ->where('agent_id', $admin_id)
        ->update(['is_read' => 1]);

    if ($updated) {
        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.'
        ]);
    } else {
        return response()->json([
            'success' => false,
            'message' => 'Notification not found or already read.'
        ]);
    }
}



    
}
