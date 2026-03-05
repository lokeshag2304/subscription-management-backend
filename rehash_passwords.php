<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Services\CryptService;

// Re-hash all clients/users who don't have bcrypt passwords
$users = DB::table('superadmins')->get();
$fixed = 0;

foreach ($users as $u) {
    // Skip if already bcrypt
    if (!empty($u->password) && str_starts_with($u->password, '$2y$')) {
        continue;
    }

    // Try to get plain-text password from d_password
    $plain = null;
    try {
        $plain = CryptService::decryptData($u->d_password);
    } catch (\Exception $e) {
        $plain = $u->password; // use stored value if decrypt fails
    }

    if (!empty($plain)) {
        DB::table('superadmins')->where('id', $u->id)->update([
            'password' => Hash::make($plain)
        ]);
        $name = '';
        try { $name = CryptService::decryptData($u->name); } catch(\Exception $e) { $name = "ID:{$u->id}"; }
        echo "Fixed: $name (login_type={$u->login_type})\n";
        $fixed++;
    }
}

echo "\nTotal fixed: $fixed accounts\n";
