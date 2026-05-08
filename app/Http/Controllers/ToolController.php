<?php

namespace App\Http\Controllers;

use App\Models\Tool;
use Illuminate\Http\Request;

class ToolController extends Controller
{
    use \App\Traits\DataNormalizer;
 
    private function logActivity($action, $record, $oldData = null, $newData = null)
    {
        try {
            $user = auth()->user() ?: (object)['id' => request()->input('s_id') ?: 1, 'name' => 'Admin', 'role' => 'Superadmin'];
            
            $standardize = function($data) {
                if (!$data) return $data;
                $arr = is_array($data) ? $data : $data->toArray();
                
                if (isset($arr['name']))        $arr['Name']        = $arr['name'];
                if (isset($arr['client_name'])) $arr['Client']      = $arr['client_name'];
                if (isset($arr['amount']))      $arr['Amount']      = $arr['amount'];
                if (isset($arr['expiry_date'])) $arr['Expiry Date'] = $arr['expiry_date'];
                if (isset($arr['status']))      $arr['Status']      = $arr['status'] ? 'Active' : 'Inactive';
                if (isset($arr['remarks']))     $arr['Remarks']     = $arr['remarks'];
                
                return $arr;
            };
 
            \App\Services\ActivityLogger::logActivity(
                $user, 
                strtoupper($action), 
                'Tools', 
                'tools', 
                $record->id ?? null, 
                $standardize($oldData), 
                $standardize($newData), 
                null, 
                request()
            );
        } catch (\Exception $e) {}
    }

    public function index()
    {
        return response()->json([
            'status' => true,
            'message' => 'Fetched successfully',
            'data' => Tool::latest()->get()->map(function($item) {
                $item->updated_at = $item->updated_at->format('j/n/Y, g:i:s a');
                $item->last_updated = $item->updated_at;
                $item->created_at = $item->created_at->format('j/n/Y, g:i:s a');
                $item->grace_period = $item->grace_period ?? 0;
                $item->due_date = $item->due_date;
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

        // Normalize data before saving
        if (isset($validated['name'])) $validated['name'] = self::normalizeData($validated['name'], 'Name');
        if (isset($validated['client_name'])) $validated['client_name'] = self::normalizeData($validated['client_name'], 'Client');

        $record = Tool::create($validated);

        $this->logActivity('CREATE', $record, null, $record->toArray());

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

        $oldData = $record->toArray();

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

        // Normalize data
        if (isset($validated['name'])) $validated['name'] = self::normalizeData($validated['name'], 'Name');
        if (isset($validated['client_name'])) $validated['client_name'] = self::normalizeData($validated['client_name'], 'Client');

        $record->update($validated);

        $this->logActivity('UPDATE', $record, $oldData, $record->toArray());

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

    public function destroy(Request $request, $id)
    {
        $record = Tool::find($id);
        if (!$record) return response()->json(['status' => false, 'message' => 'Not found'], 404);

        $oldData = $record->toArray();
        $record->delete();

        $this->logActivity('DELETE', (object)['id' => $id], $oldData, null);

        return response()->json([
            'status' => true,
            'message' => 'Deleted successfully',
            'data' => null
        ]);
    }
}
