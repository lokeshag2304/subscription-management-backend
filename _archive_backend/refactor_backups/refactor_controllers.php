<?php

$controllers = [
    ['file' => 'DomainController.php', 'model' => 'Domain', 'table' => 'domains', 'default_product_id' => 46, 'type' => 'domain'],
    ['file' => 'HostingController.php', 'model' => 'Hosting', 'table' => 'hostings', 'default_product_id' => 44, 'type' => 'hosting'],
    ['file' => 'EmailController.php', 'model' => 'Email', 'table' => 'emails', 'default_product_id' => 48, 'type' => 'email'],
];

$dir = __DIR__ . '/app/Http/Controllers/';

foreach ($controllers as $info) {
    $path = $dir . $info['file'];
    if (!file_exists($path)) continue;

    $content = file_get_contents($path);

    // Replace use App\Models\Subscription with Use App\Models\{Model}
    $content = str_replace('use App\Models\Subscription;', "use App\Models\\{$info['model']};\nuse App\Models\ImportExportHistory;\nuse Illuminate\Support\Facades\DB;", $content);
    
    // Replace Subscription:: with Model::
    $content = str_replace('Subscription::', "{$info['model']}::", $content);

    // Remove ->whereIn('product_id', $this->productIds)
    $content = preg_replace("/\-\>whereIn\(\'product_id\'\,\s*\\\$this\-\>productIds\)/", '', $content);
    
    // Change INDEX success response to have 'success' instead of 'status'
    $content = str_replace("'status' => true,", "'success' => true,", $content);

    // The logic inside store(), show(), update(), destroy() might need History logging
    // Let's systematically reconstruct the class content based on the verified SSL logic, but tailored.
    
    $template = <<<EOD
<?php

namespace App\Http\Controllers;

use App\Models\\{$info['model']};
use App\Models\ImportExportHistory;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class {$info['file']} extends Controller
{
    // Default fallback if payload is missing
    protected array \$productIds = [{$info['default_product_id']}];

    private function logActivity(\$action, \$record, \$type = '{$info['type']}')
    {
        try {
            Activity::create([
                'name' => 'System / ' . auth()->id(),
                'client_name' => \$record->client->name ?? 'Unknown',
                'amount' => \$record->amount,
                'start_date' => now()->format('Y-m-d'),
                'expiry_date' => \$record->renewal_date,
                'status' => \$record->status,
                'remarks' => ucfirst(\$action) . " " . ucfirst(\$type) . " Record",
            ]);
        } catch (\Exception \$e) {
            \Illuminate\Support\Facades\Log::error("Failed to log activity: " . \$e->getMessage());
        }
    }

    public function index()
    {
        \$records = {$info['model']}::with(['product', 'vendor', 'client'])
            ->latest()
            ->get()
            ->map(function (\$sub) {
                \$productName = optional(\$sub->product)->name;
                \$vendorName  = optional(\$sub->vendor)->name;
                \$clientName  = optional(\$sub->client)->name;

                if (\$productName && base64_decode(\$productName, true) !== false) {
                    \$productName = base64_decode(\$productName, true);
                }

                return [
                    'id'             => \$sub->id,
                    'domain_name'    => \$productName ?? 'N/A',
                    'client'         => \$clientName  ?? 'N/A',
                    'product'        => \$productName ?? 'N/A',
                    'vendor'         => \$vendorName  ?? 'N/A',
                    'amount'         => \$sub->amount,
                    'renewal_date'   => \$sub->renewal_date   ?? null,
                    'deletion_date'  => \$sub->deletion_date  ?? null,
                    'days_left'      => \$sub->renewal_date ? now()->startOfDay()->diffInDays(Carbon::parse(\$sub->renewal_date)->startOfDay(), false) : null,
                    'days_to_delete' => \$sub->deletion_date ? now()->startOfDay()->diffInDays(Carbon::parse(\$sub->deletion_date)->startOfDay(), false) : 0,
                    'status'         => \$sub->status,
                    'remarks'        => \$sub->remarks,
                    'last_updated'   => \$sub->updated_at,
                    'created_at'     => \$sub->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data'   => \$records,
        ]);
    }

    public function store(Request \$request)
    {
        \$data = \$request->all();

        foreach (\$data as \$key => \$value) {
            if (\$value === '') {
                \$data[\$key] = null;
            }
        }

        foreach (['renewal_date', 'deletion_date'] as \$dateField) {
            if (!empty(\$data[\$dateField])) {
                try {
                    \$data[\$dateField] = Carbon::parse(\$data[\$dateField])->format('Y-m-d');
                } catch (\Exception \$e) {
                    \$data[\$dateField] = null;
                }
            } else {
                \$data[\$dateField] = null;
            }
        }

        \$today = now()->startOfDay();
        
        \$daysLeft = null;
        if (!empty(\$data['renewal_date'])) {
            try {
                \$daysLeft = \$today->diffInDays(Carbon::parse(\$data['renewal_date'])->startOfDay(), false);
            } catch (\Exception \$e) {}
        }
        \$data['days_left'] = \$daysLeft;

        \$daysToDelete = null;
        if (!empty(\$data['deletion_date'])) {
            try {
                \$daysToDelete = \$today->diffInDays(Carbon::parse(\$data['deletion_date'])->startOfDay(), false);
            } catch (\Exception \$e) {}
        }
        \$data['days_to_delete'] = \$daysToDelete ?? 0;

        \$request->replace(\$data);

        \$validator = \Illuminate\Support\Facades\Validator::make(\$request->all(), [
            'product'        => 'nullable',
            'product_id'     => 'nullable',
            'client'         => 'nullable',
            'client_id'      => 'nullable',
            'vendor'         => 'nullable',
            'vendor_id'      => 'nullable',
            'amount'         => 'nullable|numeric',
            'renewal_date'   => 'nullable|date',
            'deletion_date'  => 'nullable|date',
            'days_to_delete' => 'nullable|integer',
            'status'         => 'nullable',
            'remarks'        => 'nullable|string',
        ]);

        if (\$validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => \$validator->errors()
            ], 422);
        }

        try {
            \$productId = \$request->input('product') ?? \$request->input('product_id') ?? (\$this->productIds[0] ?? 0);
            \$clientId = \$request->input('client') ?? \$request->input('client_id');
            \$vendorId = \$request->input('vendor') ?? \$request->input('vendor_id') ?? auth()->id() ?? 1;

            \$recordData = [
                'product_id'     => is_numeric(\$productId) ? \$productId : (\$this->productIds[0] ?? 0),
                'client_id'      => is_numeric(\$clientId) ? \$clientId : null,
                'vendor_id'      => is_numeric(\$vendorId) ? \$vendorId : 1,
                'amount'         => \$request->input('amount'),
                'renewal_date'   => \$request->input('renewal_date'),
                'deletion_date'  => \$request->input('deletion_date'),
                'days_to_delete' => \$request->input('days_to_delete'),
                'status'         => \$request->input('status') ?? 1,
                'remarks'        => \$request->input('remarks'),
            ];

            \$record = {$info['model']}::create(\$recordData);
            
            \$record->refresh();
            \$record->load(['product', 'client', 'vendor']);
            \$record->setAttribute('days_left', \$daysLeft);

            \$this->logActivity('create', \$record);

            return response()->json([
                'success' => true,
                'data'    => \$record
            ], 200);

        } catch (\Exception \$e) {
            return response()->json([
                'success' => false,
                'error'   => \$e->getMessage()
            ], 500);
        }
    }

    public function show(\$id)
    {
        \$record = {$info['model']}::with(['product:id,name', 'vendor:id,name', 'client:id,name'])->find(\$id);

        if (!\$record) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Fetched successfully', 'data' => \$record]);
    }

    public function update(Request \$request, \$id)
    {
        \$record = {$info['model']}::find(\$id);

        if (!\$record) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        \$validated = \$request->validate([
            'product_id'    => 'nullable|integer',
            'client_id'     => 'nullable|integer',
            'vendor_id'     => 'nullable|integer',
            'amount'        => 'nullable|numeric',
            'renewal_date'  => 'nullable|date',
            'deletion_date' => 'nullable|date',
            'status'         => 'nullable|boolean',
            'remarks'       => 'nullable|string',
        ]);

        \$record->update(\$validated);
        
        \$this->logActivity('update', \$record);

        return response()->json(['success' => true, 'message' => 'Updated successfully', 'data' => \$record]);
    }

    public function destroy(\$id)
    {
        \$record = {$info['model']}::find(\$id);

        if (!\$record) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        \$record->delete();
        
        \$this->logActivity('delete', \$record);

        return response()->json(['success' => true, 'message' => 'Deleted successfully', 'data' => null]);
    }

    public function import(Request \$request)
    {
        \$request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt'
        ]);

        try {
            \$data = \Maatwebsite\Excel\Facades\Excel::toArray(new class {}, \$request->file('file'));
            if (empty(\$data) || empty(\$data[0])) return response()->json(['success' => false, 'message' => 'Empty file provided.'], 400);

            \$rows = \$data[0];
            \$insertedRecords = [];
            
            // Map product, client, vendor names to IDs (simplified implementation matching expectations)
            // ...
            
            DB::transaction(function() use (&\$insertedRecords, \$rows) {
                foreach (array_slice(\$rows, 1) as \$row) {
                    if (empty(array_filter(\$row))) continue;
                    
                    \$renewalDate = !empty(\$row[3]) ? Carbon::parse(\$row[3])->format('Y-m-d') : null;
                    \$deletionDate = !empty(\$row[5]) ? Carbon::parse(\$row[5])->format('Y-m-d') : null;
                    
                    \$rec = {$info['model']}::create([
                        'product_id'    => is_numeric(\$row[0]) ? \$row[0] : {$info['default_product_id']},
                        'client_id'     => is_numeric(\$row[1]) ? \$row[1] : null,
                        'vendor_id'     => is_numeric(\$row[2]) ? \$row[2] : 1,
                        'renewal_date'  => \$renewalDate,
                        'amount'        => (float) str_replace([',', ' '], '', \$row[4] ?? 0),
                        'deletion_date' => \$deletionDate,
                        'days_to_delete'=> !empty(\$deletionDate) ? now()->startOfDay()->diffInDays(Carbon::parse(\$deletionDate)->startOfDay(), false) : 0,
                        'status'        => strtolower(trim((string)(\$row[7] ?? ''))) === 'inactive' || \$row[7] === '0' ? 0 : 1,
                        'remarks'       => \$row[8] ?? null,
                    ]);
                    
                    \$rec->refresh();
                    \$rec->load(['product', 'client', 'vendor']);
                    \$rec->setAttribute('days_left', \$renewalDate ? now()->startOfDay()->diffInDays(Carbon::parse(\$renewalDate)->startOfDay(), false) : null);
                    \$insertedRecords[] = \$rec;
                }
            });

            ImportExportHistory::create([
                'user_id' => auth()->id() ?? 1,
                'action' => 'import',
                'file_name' => \$request->file('file')->getClientOriginalName()
            ]);
            
            Activity::create([
                'name' => 'System / ' . (auth()->id() ?? 1),
                'client_name' => 'Various',
                'amount' => 0,
                'start_date' => now()->format('Y-m-d'),
                'expiry_date' => null,
                'status' => 1,
                'remarks' => "Imported " . count(\$insertedRecords) . " {$info['type']} records",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Import completed',
                'inserted_data' => array_reverse(\$insertedRecords)
            ], 200);
            
        } catch (\Exception \$e) {
            \Illuminate\Support\Facades\Log::error("Import process blocked: " . \$e->getMessage());
            return response()->json(['success' => false, 'message' => 'System error: ' . \$e->getMessage()], 500);
        }
    }
}
EOD;

    // Replace class name depending on the loop
    $template = str_replace("class {$info['file']}Controller", "class {$info['file']}", $template);
    
    file_put_contents($path, $template);
    echo "Refactored {$info['file']}\n";
}
?>
