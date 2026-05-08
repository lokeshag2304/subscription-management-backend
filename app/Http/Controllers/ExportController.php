<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ExportController extends Controller
{
    /**
     * Export functionality cleared for complete rebuild.
     */
    public function globalExport(Request $request)
    {
        return response()->json(['success' => false, 'message' => 'Export logic is currently being rebuilt.'], 501);
    }
}
