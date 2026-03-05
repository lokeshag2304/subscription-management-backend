<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\RemarkHistoryService;

class RemarkHistoryController extends Controller
{
    /**
     * Get remark history for a record.
     */
    public function index(Request $request)
    {
        $module   = $request->query('module');
        $recordId = $request->query('record_id');

        if (!$module || !$recordId) {
            return response()->json([
                'success' => false,
                'message' => 'module and record_id are required'
            ], 400);
        }

        $history = RemarkHistoryService::getHistory($module, $recordId)->map(function ($item) {
            try {
                $item->remark = \App\Services\CryptService::decryptData($item->remark) ?? $item->remark;
            } catch (\Exception $e) {}
            return $item;
        });

        return response()->json([
            'success' => true,
            'data'    => $history
        ]);
    }
}
