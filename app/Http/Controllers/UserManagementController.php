<?php

namespace App\Http\Controllers;

use App\Models\UserManagement;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    public function index()
    {
        return response()->json([
            'status' => true,
            'message' => 'Fetched successfully',
            'data' => UserManagement::withCount('remarkHistories')->latest()->get()->map(function($item) {
                $item->updated_at = $item->updated_at->format('j/n/Y, g:i:s a');
                $item->last_updated = $item->updated_at;
                $item->created_at = $item->created_at->format('j/n/Y, g:i:s a');
                $item->grace_period = $item->grace_period ?? 0;
                $item->due_date = $item->due_date;
                $item->has_remark_history = $item->remark_histories_count > 0;
                try { $item->remarks = \App\Services\CryptService::decryptData($item->remarks); } catch (\Exception $e) {}
                return $item;
            })
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string',
            'client_name' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'start_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'status' => 'nullable|boolean',
            'remarks' => 'nullable|string',
            'grace_period' => 'nullable|integer',
        ]);

        $record = UserManagement::create($validated);
        
        \App\Services\GracePeriodService::syncModel($record);
        $record->save();

        return response()->json([
            'status' => true,
            'message' => 'Created successfully',
            'data' => tap($record, function($r) {
                $r->updated_at = $r->updated_at->format('j/n/Y, g:i:s a');
                $r->last_updated = $r->updated_at;
                $r->created_at = $r->created_at->format('j/n/Y, g:i:s a');
                $r->grace_period = $r->grace_period ?? 0;
                $r->due_date = $r->due_date;
            })
        ], 201);
    }

    public function show($id)
    {
        $record = UserManagement::find($id);
        if (!$record) return response()->json(['status' => false, 'message' => 'Not found'], 404);

        return response()->json([
            'status' => true,
            'message' => 'Fetched successfully',
            'data' => tap($record, function($r) {
                $r->updated_at = $r->updated_at->format('j/n/Y, g:i:s a');
                $r->last_updated = $r->updated_at;
                $r->created_at = $r->created_at->format('j/n/Y, g:i:s a');
            })
        ]);
    }

    public function update(Request $request, $id)
    {
        $record = UserManagement::find($id);
        if (!$record) return response()->json(['status' => false, 'message' => 'Not found'], 404);

        $validated = $request->validate([
            'name' => 'nullable|string',
            'client_name' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'start_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'status' => 'nullable|boolean',
            'remarks' => 'nullable|string',
            'grace_period' => 'nullable|integer',
        ]);

        // Track Remark History
        \App\Services\RemarkHistoryService::logUpdate('UserManagement', $record, $validated);

        if (isset($validated['remarks'])) {
            $validated['remarks'] = \App\Services\CryptService::encryptData($validated['remarks']);
        }

        $record->update($validated);
        
        \App\Services\GracePeriodService::syncModel($record);
        $record->save();
        $record->loadCount('remarkHistories');

        return response()->json([
            'status' => true,
            'message' => 'Updated successfully',
            'data' => tap($record, function($r) {
                $r->updated_at = $r->updated_at->format('j/n/Y, g:i:s a');
                $r->last_updated = $r->updated_at;
                $r->created_at = $r->created_at->format('j/n/Y, g:i:s a');
                $r->grace_period = $r->grace_period ?? 0;
                $r->due_date = $r->due_date;
            })
        ]);
    }

    public function destroy($id)
    {
        $record = UserManagement::find($id);
        if (!$record) return response()->json(['status' => false, 'message' => 'Not found'], 404);

        $record->delete();

        return response()->json([
            'status' => true,
            'message' => 'Deleted successfully',
            'data' => null
        ]);
    }
}
