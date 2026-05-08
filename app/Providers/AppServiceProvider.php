<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use PhpOffice\PhpSpreadsheet\Shared\File;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Explicitly disable upload temp directory to force sys_get_temp_dir which respects our putenv below
        File::setUseUploadTempDirectory(false);
        // Note: PhpSpreadsheet > 1.18 removed Settings::setZipClass(Settings::PCLZIP) and exclusively uses ZipArchive natively.

        // Set temp directory for PhpSpreadsheet / open_basedir compatibility
        // (Settings::setTempDir does not exist in the currently installed version of PhpSpreadsheet)
        $tempDir = storage_path('app/imports/extracted');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        
        // Force PHP and native zip extensions to use the allowed local path instead of /tmp
        putenv('TMPDIR=' . $tempDir);
        putenv('TMP=' . $tempDir);
        putenv('TEMP=' . $tempDir);
    }
}
