<?php

namespace App\Http\Controllers;

use App\Models\Tool;
use Illuminate\Http\Request;

class ToolController extends Controller
{
    public function index()
    {
        return response()->json([
            'status' => true,
            'message' => 'Fetched successfully',
            'data' => Tool::latest()->get()->map(function($item) {
                $item->updated_at = $item->updated_at->format('j/n/Y, g:i:s a');
                $item->last_updated = $item->updated_at;
                $item->created_at = $item->created_at->format('j/n/Y, g:i:s a');
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
        ]);

        $record = Tool::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Created successfully',
            'data' => tap($record, function($r) {
                $r->updated_at = $r->updated_at->format('j/n/Y, g:i:s a');
                $r->last_updated = $r->updated_at;
                $r->created_at = $r->created_at->format('j/n/Y, g:i:s a');
            })
        ], 201);
    }

    public function show($id)
    {
        $record = Tool::find($id);
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
        $record = Tool::find($id);
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
            'data' => tap($record, function($r) {
                $r->updated_at = $r->updated_at->format('j/n/Y, g:i:s a');
                $r->last_updated = $r->updated_at;
                $r->created_at = $r->created_at->format('j/n/Y, g:i:s a');
            })
        ]);
    }

    public function destroy($id)
    {
        $record = Tool::find($id);
        if (!$record) return response()->json(['status' => false, 'message' => 'Not found'], 404);

        $record->delete();

        return response()->json([
            'status' => true,
            'message' => 'Deleted successfully',
            'data' => null
        ]);
    }
}
