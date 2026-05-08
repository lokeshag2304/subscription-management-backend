<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SubscriptionModel;

class SubscriptionModelController extends Controller
{
    // GET all subscriptions (latest first)
    public function index()
    {
        return response()->json(
            SubscriptionModel::orderBy('created_at', 'desc')->get()
        );
    }

    // STORE new subscription
    public function store(Request $request)
    {
        $request->validate([
            'product_name' => 'required|string',
            'client_name' => 'required|string',
            'amount' => 'required|numeric',
            'renewal_date' => 'required|date',
            'deletion_date' => 'nullable|date',
            'status' => 'required|integer',
        ]);

        $renewalDate = \Carbon\Carbon::parse($request->renewal_date);
        $deletionDate = $request->deletion_date
            ? \Carbon\Carbon::parse($request->deletion_date)
            : null;

        $daysLeft = now()->diffInDays($renewalDate, false);
        $daysToDelete = $deletionDate
            ? now()->diffInDays($deletionDate, false)
            : null;

        $subscription = \App\Models\SubscriptionModel::create([
            'product_name' => $request->product_name,
            'client_name' => $request->client_name,
            'amount' => $request->amount,
            'renewal_date' => $renewalDate->format('Y-m-d'),
            'deletion_date' => $deletionDate?->format('Y-m-d'),
            'days_left' => $daysLeft,
            'days_to_delete' => $daysToDelete,
            'status' => $request->status,
            'remarks' => $request->remarks,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Subscription saved successfully',
            'data' => $subscription
        ], 201);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        $file = $request->file('file');
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getPathname())
            ->getActiveSheet()
            ->toArray();

        $header = array_map('trim', $rows[0]);

        $expected = [
            'Product',
            'Client',
            'Amount',
            'Renewal Date',
            'Deletion Date',
            'Status',
            'Remarks'
        ];

        if ($header !== $expected) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid file format. Headers must match exactly.'
            ], 400);
        }

        $duplicateCount = 0;
        $insertedCount = 0;

        foreach (array_slice($rows, 1) as $row) {

            if (empty($row[0])) continue;

            $renewalDate = \Carbon\Carbon::parse($row[3])->format('Y-m-d');
            $deletionDate = $row[4]
                ? \Carbon\Carbon::parse($row[4])->format('Y-m-d')
                : null;

            // Normalize values (avoid space + case issues)
            $product = trim(strtolower($row[0]));
            $client  = trim(strtolower($row[1]));

            $exists = \App\Models\SubscriptionModel::whereRaw('LOWER(TRIM(product_name)) = ?', [$product])
                ->whereRaw('LOWER(TRIM(client_name)) = ?', [$client])
                ->whereDate('renewal_date', $renewalDate)
                ->exists();

            if ($exists) {
                $duplicateCount++;
                continue;
            }

            \App\Models\SubscriptionModel::create([
                'product_name' => trim($row[0]),
                'client_name' => trim($row[1]),
                'amount' => $row[2],
                'renewal_date' => $renewalDate,
                'deletion_date' => $deletionDate,
                'days_left' => now()->diffInDays($renewalDate, false),
                'days_to_delete' => $deletionDate
                    ? now()->diffInDays($deletionDate, false)
                    : null,
                'status' => $row[5],
                'remarks' => $row[6] ?? null,
            ]);

            $insertedCount++;
        }

        $filePath = $file->store('imports');
        \App\Models\ImportHistory::create([
            'module_name'     => 'SubscriptionModel',
            'action'          => 'import',
            'file_name'       => $file->getClientOriginalName(),
            'file_path'       => $filePath,
            'imported_by'     => 'System / Admin',
            'successful_rows' => $insertedCount,
            'duplicates_count'=> $duplicateCount,
        ]);

        return response()->json([
            'status' => true,
            'message' => "Import completed. Inserted: $insertedCount, Duplicates skipped: $duplicateCount"
        ]);
    }
}
