<?php

$controllers = [
    'SSLController' => ['model' => 'SSL', 'productIds' => '[42, 43]'],
    'DomainController' => ['model' => 'Domain', 'productIds' => '[46]'],
    'HostingController' => ['model' => 'Hosting', 'productIds' => '[44]'],
    'EmailController' => ['model' => 'Email', 'productIds' => '[45]'],
    'CounterController' => ['model' => 'Counter', 'productIds' => '[47]']
];

foreach ($controllers as $name => $cfg) {
    $path = "app/Http/Controllers/{$name}.php";
    if (!file_exists($path)) continue;

    $model = $cfg['model'];
    $productIds = $cfg['productIds'];

    $content = <<<PHP
<?php

namespace App\Http\Controllers;

use App\Models\\{$model};
use App\Models\Activity;
use App\Models\ImportExportHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class {$name} extends Controller
{
    protected array \$productIds = {$productIds};

    private function formatDate(\$date)
    {
        if (empty(\$date)) return null;
        try {
            // Handle dd-mm-yyyy or yyyy-mm-dd
            return Carbon::parse(\$date)->format('Y-m-d');
        } catch (\Exception \$e) {
            return null;
        }
    }

    private function calculateFields(&\$data)
    {
        \$today = now()->startOfDay();
        
        if (!empty(\$data['renewal_date'])) {
            \$renewal = Carbon::parse(\$data['renewal_date'])->startOfDay();
            \$data['days_left'] = \$today->diffInDays(\$renewal, false);
        } else {
            \$data['days_left'] = null;
        }

        if (!empty(\$data['deletion_date'])) {
            \$deletion = Carbon::parse(\$data['deletion_date'])->startOfDay();
            \$data['days_to_delete'] = \$today->diffInDays(\$deletion, false);
        } else {
            \$data['days_to_delete'] = null;
        }
    }

    private function logActivity(\$action, \$record)
    {
        try {
            Activity::create([
                'name' => 'System / ' . (auth()->id() ?? 1),
                'client_name' => \$record->client->name ?? 'N/A',
                'amount' => \$record->amount ?? 0,
                'start_date' => now()->format('Y-m-d'),
                'expiry_date' => \$record->renewal_date ?? \$record->expiry_date,
                'status' => \$record->status ?? 1,
                'remarks' => ucfirst(\$action) . " {$model} Record: " . (\$record->domain_name ?? \$record->id),
            ]);
        } catch (\Exception \$e) {}
    }

    public function index()
    {
        \$records = {$model}::with(['product', 'vendor', 'client'])
            ->latest()
            ->get()
            ->map(function (\$item) {
                \$today = now()->startOfDay();
                \$item->days_left = \$item->renewal_date ? \$today->diffInDays(Carbon::parse(\$item->renewal_date)->startOfDay(), false) : null;
                \$item->days_to_delete = \$item->deletion_date ? \$today->diffInDays(Carbon::parse(\$item->deletion_date)->startOfDay(), false) : null;
                return \$item;
            });

        return response()->json([
            'success' => true,
            'data' => \$records
        ]);
    }

    public function store(Request \$request)
    {
        \$data = \$request->all();
        
        // Nullable safe
        foreach (\$data as \$key => \$value) {
            if (\$value === '') \$data[\$key] = null;
        }

        // Date Handling
        \$data['renewal_date'] = \$this->formatDate(\$data['renewal_date'] ?? \$data['expiry_date'] ?? null);
        \$data['deletion_date'] = \$this->formatDate(\$data['deletion_date'] ?? null);

        // Auto calculate
        \$this->calculateFields(\$data);

        \$record = {$model}::create([
            'product_id'    => \$data['product_id'] ?? (\$this->productIds[0] ?? 1),
            'client_id'     => \$data['client_id'] ?? 1,
            'vendor_id'     => \$data['vendor_id'] ?? \$data['s_id'] ?? auth()->id() ?? 1,
            'amount'        => \$data['amount'] ?? 0,
            'renewal_date'  => \$data['renewal_date'],
            'deletion_date' => \$data['deletion_date'],
            'days_to_delete'=> \$data['days_to_delete'],
            'status'        => \$data['status'] ?? 1,
            'remarks'       => \$data['remarks'] ?? null,
        ]);

        \$record->refresh()->load(['product', 'client', 'vendor']);
        \$this->logActivity('created', \$record);

        return response()->json([
            'success' => true,
            'message' => 'Record created successfully',
            'data' => \$record
        ], 201);
    }

    public function update(Request \$request, \$id)
    {
        \$record = {$model}::find(\$id);
        if (!\$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        \$data = \$request->all();
        foreach (\$data as \$key => \$value) {
            if (\$value === '') \$data[\$key] = null;
        }

        if (isset(\$data['renewal_date'])) \$data['renewal_date'] = \$this->formatDate(\$data['renewal_date']);
        if (isset(\$data['deletion_date'])) \$data['deletion_date'] = \$this->formatDate(\$data['deletion_date']);

        \$record->update(\$data);
        \$record->refresh()->load(['product', 'client', 'vendor']);
        
        \$this->logActivity('updated', \$record);

        return response()->json([
            'success' => true,
            'message' => 'Record updated successfully',
            'data' => \$record
        ]);
    }

    public function destroy(\$id)
    {
        \$record = {$model}::find(\$id);
        if (!\$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        \$this->logActivity('deleted', \$record);
        \$record->delete();

        return response()->json([
            'success' => true,
            'message' => 'Record deleted successfully'
        ]);
    }

    public function import(Request \$request)
    {
        \$request->validate(['file' => 'required|file']);
        
        try {
            \$rows = \Maatwebsite\Excel\Facades\Excel::toArray(new class {}, \$request->file('file'))[0];
            \$inserted = [];

            DB::transaction(function() use (&\$inserted, \$rows) {
                foreach (array_slice(\$rows, 1) as \$row) {
                    if (empty(array_filter(\$row))) continue;

                    \$renewalDate = \$this->formatDate(\$row[3] ?? null);
                    \$deletionDate = \$this->formatDate(\$row[5] ?? null);
                    
                    \$data = [
                        'renewal_date' => \$renewalDate,
                        'deletion_date' => \$deletionDate
                    ];
                    \$this->calculateFields(\$data);

                    \$rec = {$model}::create([
                        'product_id'    => is_numeric(\$row[0]) ? \$row[0] : (\$this->productIds[0] ?? 1),
                        'client_id'     => is_numeric(\$row[1]) ? \$row[1] : 1,
                        'vendor_id'     => is_numeric(\$row[2]) ? \$row[2] : 1,
                        'renewal_date'  => \$renewalDate,
                        'amount'        => (float) str_replace([',', ' '], '', \$row[4] ?? 0),
                        'deletion_date' => \$deletionDate,
                        'days_to_delete'=> \$data['days_to_delete'],
                        'status'        => 1,
                        'remarks'       => \$row[8] ?? null,
                    ]);
                    
                    \$rec->refresh()->load(['product', 'client', 'vendor']);
                    \$inserted[] = \$rec;
                }
            });

            ImportExportHistory::create([
                'user_id' => auth()->id() ?? 1,
                'action' => 'import',
                'file_name' => \$request->file('file')->getClientOriginalName()
            ]);

            return response()->json([
                'success' => true,
                'message' => count(\$inserted) . ' records imported',
                'inserted_data' => array_reverse(\$inserted)
            ]);
        } catch (\Exception \$e) {
            return response()->json(['success' => false, 'message' => \$e->getMessage()], 500);
        }
    }
}
PHP;

    file_put_contents($path, $content);
    echo "Refactored {$name}\n";
}
