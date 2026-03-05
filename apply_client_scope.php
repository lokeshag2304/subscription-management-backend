<?php
/**
 * Batch-apply ClientScopeService to all module controllers.
 * Replaces: index(), store(), update(), destroy() patterns.
 */

$controllers = [
    'HostingController' => [
        'model'   => 'Hosting',
        'module'  => 'Hosting',
    ],
    'EmailController' => [
        'model'   => 'Email',
        'module'  => 'Email',
    ],
    'CounterController' => [
        'model'   => 'Counter',
        'module'  => 'Counter',
    ],
    'DomainController' => [
        'model'   => 'Domain',
        'module'  => 'Domain',
    ],
];

foreach ($controllers as $name => $info) {
    $path = __DIR__ . "/app/Http/Controllers/{$name}.php";
    if (!file_exists($path)) {
        echo "SKIP: $path not found\n";
        continue;
    }

    $content = file_get_contents($path);
    $changed = false;

    // 1. Add ClientScopeService import after ActivityLogger import
    if (strpos($content, 'ClientScopeService') === false) {
        $content = str_replace(
            "use App\\Services\\ActivityLogger;\n",
            "use App\\Services\\ActivityLogger;\nuse App\\Services\\ClientScopeService;\n",
            $content
        );
        $changed = true;
        echo "  [{$name}] Added ClientScopeService import\n";
    }

    // 2. Replace: public function index() → public function index(Request $request)
    //    And add scope line after the first ->withCount or ->latest()
    if (strpos($content, 'public function index(Request $request)') === false) {
        $content = str_replace(
            'public function index()',
            'public function index(Request $request)',
            $content
        );
        $changed = true;
        echo "  [{$name}] Patched index() signature\n";
    }

    // 3. Add scope after ->latest() ->get() chain — inject before ->get()
    // Pattern: ->latest()\n            ->get()
    if (strpos($content, '// ── CLIENT SCOPE') === false) {
        // Find ->latest()  followed eventually by ->get() in index
        $content = preg_replace(
            '/(->withCount\(\'remarkHistories\'\)\s*->latest\(\)\s*)(->get\(\);)/m',
            "$1\n        // ── CLIENT SCOPE: filter to only this client's records ──\n        ClientScopeService::applyScope(\$query, \$request);\n\n        \$records = \$query->get();\n        // placeholder_removed",
            $content,
            1
        );

        // Fix: wrap the query build into $query = ... style
        // This is complex so let's do it more carefully
        // Revert the above and do it right:
        $content = file_get_contents($path); // re-read clean
        $changed2 = false;

        // Approach: Find the index method and rewrite just that portion
        // Look for: $records = Model::with([...])->withCount('remarkHistories')->latest()->get();
        $pattern = '/(\$records\s*=\s*' . $info['model'] . '::with\([^;]+?\)\s*->withCount\([\'"]remarkHistories[\'"]\)\s*->latest\(\)\s*->get\(\);)/s';
        if (preg_match($pattern, $content, $matches)) {
            $original = $matches[1];
            // Build replacement: split into $query = ... + applyScope + ->get()
            $replacement = str_replace('->get();', ';', $original);
            $replacement = str_replace('$records = ', '$query = ', $replacement);
            $replacement .= "\n\n        // ── CLIENT SCOPE: filter to only this client's records ──\n        ClientScopeService::applyScope(\$query, \$request);\n\n        \$records = \$query->get();";
            $content = str_replace($original, $replacement, $content);
            $changed2 = true;
            echo "  [{$name}] Patched index() query with ClientScope\n";
        }

        if ($changed2) $changed = true;
    }

    // 4. Add ClientScopeService::enforceClientId() at top of store()
    if (strpos($content, 'ClientScopeService::enforceClientId') === false) {
        // Insert after: public function store(Request $request)\n    {\n        try {\n
        $content = preg_replace(
            '/(public function store\(Request \$request\)\s*\{.*?try\s*\{)/s',
            "$1\n            // ── CLIENT SCOPE: force client_id from JWT if client ──\n            ClientScopeService::enforceClientId(\$request);\n",
            $content,
            1
        );
        $changed = true;
        echo "  [{$name}] Added enforceClientId() to store()\n";
    }

    // 5. Add assertOwnership to update()
    if (strpos($content, 'ClientScopeService::assertOwnership') === false) {
        // Pattern: find update() method and add after the "Not found" check
        $content = preg_replace(
            '/(public function update\(Request \$request,\s*\$id\)\s*\{.*?\$record\s*=\s*\w+::find\(\$id\);.*?if\s*\(!\$record\)[^\n]+\n)/s',
            "$1\n        // ── OWNERSHIP GUARD: Client can only edit their own records ──\n        ClientScopeService::assertOwnership(\$record, \$request);\n",
            $content,
            1
        );
        $changed = true;
        echo "  [{$name}] Added assertOwnership() to update()\n";
    }

    // 6. Add assertOwnership to destroy()
    $destroyPatched = preg_replace(
        '/(public function destroy\(\$id\)\s*\{.*?\$record\s*=\s*\w+::find\(\$id\);.*?if\s*\(!\$record\)[^\n]+\n)/s',
        "$1\n        // ── OWNERSHIP GUARD: Client can only delete their own records ──\n        ClientScopeService::assertOwnership(\$record, new \\Illuminate\\Http\\Request());\n",
        $content,
        1
    );
    if ($destroyPatched && $destroyPatched !== $content) {
        $content = $destroyPatched;
        $changed = true;
        echo "  [{$name}] Added assertOwnership() to destroy()\n";
    }

    if ($changed) {
        // Add ClientScopeService import if still missing (in case we re-read)
        if (strpos($content, 'ClientScopeService') === false) {
            $content = str_replace(
                "use App\\Services\\ActivityLogger;",
                "use App\\Services\\ActivityLogger;\nuse App\\Services\\ClientScopeService;",
                $content
            );
        }
        file_put_contents($path, $content);
        echo "[{$name}] DONE\n\n";
    } else {
        echo "[{$name}] No changes needed\n\n";
    }
}

echo "All controllers processed.\n";
