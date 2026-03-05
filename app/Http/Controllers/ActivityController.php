<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index()
    {
        return response()->json([
            'status' => true,
            'message' => 'Fetched successfully',
            'data' => Activity::latest()->get()->map(function($act) {
                try { $act->action = \App\Services\CryptService::decryptData($act->action) ?? $act->action; } catch (\Exception $e) {}
                try { $act->message = \App\Services\CryptService::decryptData($act->message) ?? $act->message; } catch (\Exception $e) {}
                try {
                    $dec = \App\Services\CryptService::decryptData($act->details);
                    if ($dec) $act->details = $dec;
                } catch (\Exception $e) {}
                return $act;
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

    public function destroy($id)
    {
        $record = Activity::find($id);
        if (!$record) return response()->json(['status' => false, 'message' => 'Not found'], 404);

        $record->delete();

        return response()->json([
            'status' => true,
            'message' => 'Deleted successfully',
            'data' => null
        ]);
    }
}
