<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function saveRemarkHistory($record, $newRemarks, $module)
    {
        // Trim and compare
        $oldRemark = trim($record->remarks ?? $record->latest_remark?->remark ?? '');
        $newRemark = trim($newRemarks ?? '');

        if ($newRemark !== $oldRemark && !empty($oldRemark)) {
            \App\Models\RemarkHistory::create([
                'module' => $module,
                'record_id' => $record->id,
                'remark' => $oldRemark,
                'user_name' => auth()->user()->name ?? 'Admin',
            ]);
        }
    }
}
