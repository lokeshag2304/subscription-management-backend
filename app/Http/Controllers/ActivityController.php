<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('rowsPerPage', 10);
        $activities = Activity::latest()->paginate($perPage);

        $activities->getCollection()->transform(function($act) {
            try { $act->action = \App\Services\CryptService::decryptData($act->action) ?? $act->action; } catch (\Exception $e) {}
            try { $act->message = \App\Services\CryptService::decryptData($act->message) ?? $act->message; } catch (\Exception $e) {}
            try {
                $dec = \App\Services\CryptService::decryptData($act->details);
                if ($dec) $act->details = $dec;
            } catch (\Exception $e) {}
            return $act;
        });

        return response()->json($activities);
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
        ]);

        $record = Activity::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Created successfully',
            'data' => $record
        ], 201);
    }

    public function show($id)
    {
        $record = Activity::find($id);
        if (!$record) return response()->json(['status' => false, 'message' => 'Not found'], 404);

        return response()->json([
            'status' => true,
            'message' => 'Fetched successfully',
            'data' => $record
        ]);
    }

    public function update(Request $request, $id)
    {
        $record = Activity::find($id);
        if (!$record) return response()->json(['status' => false, 'message' => 'Not found'], 404);

        $validated = $request->validate([
            'name' => 'nullable|string',
            'client_name' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'start_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'status' => 'nullable|boolean',
            'remarks' => 'nullable|string',
        ]);

        $record->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Updated successfully',
            'data' => $record
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');
        if (!is_array($ids)) {
            return response()->json(['status' => false, 'message' => 'Invalid IDs'], 400);
        }

        Activity::whereIn('id', $ids)->delete();

        return response()->json([
            'status' => true,
            'message' => count($ids) . ' activities deleted successfully',
            'data' => null
        ], 200);
    }
}
