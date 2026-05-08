<?php

namespace App\Traits;

trait DataNormalizer
{
    /**
     * Standardization Map for known spelling mistakes and inconsistent naming.
     */
    protected static $normalizationMap = [
        'weels' => 'Reels',
        'instagwam' => 'Instagram',
        'agawwal' => 'Agarwal',
        'agarwal' => 'Agarwal',
        'insta' => 'Instagram',
        'fb' => 'Facebook',
        'yt' => 'YouTube',
        'youtube' => 'YouTube',
        'cloudflare' => 'Cloudflare',
        'godaddy' => 'GoDaddy',
        'namecheap' => 'Namecheap',
        'prouduct' => 'Product',
        'clent' => 'Client',
        'doman' => 'Domain',
        'hostng' => 'Hosting',
        'ssll' => 'SSL',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'expired' => 'Expired',
        'pending' => 'Pending',
        'creater' => 'Creator',
        'maneger' => 'Manager',
        'suparadmin' => 'Superadmin',
        'supperadmin' => 'Superadmin',
        'instgram' => 'Instagram',
        'facebuk' => 'Facebook',
        'twiter' => 'Twitter',
        'linkdin' => 'LinkedIn',
    ];

    /**
     * Normalize a string value based on global mapping and field context.
     */
    public static function normalizeData(?string $value, string $field = ''): ?string
    {
        if ($value === null || $value === '')
            return $value;

        // 1. Basic Cleaning
        $clean = trim($value);
        $lower = strtolower($clean);

        // 2. Exact Match Replacement
        if (isset(self::$normalizationMap[$lower])) {
            return self::$normalizationMap[$lower];
        }

        // 3. Partial Replacement (e.g., "Instagwam Post" -> "Instagram Post")
        foreach (self::$normalizationMap as $wrong => $right) {
            if (str_contains($lower, $wrong)) {
                // Only replace if it's a standalone word or specifically identified
                $clean = preg_replace('/\b' . preg_quote($wrong, '/') . '\b/i', $right, $clean);
            }
        }

        // 4. Field-Context Capitalization
        $field = strtolower($field);
        $isNameField = str_contains($field, 'name') ||
            str_contains($field, 'client') ||
            str_contains($field, 'vendor') ||
            str_contains($field, 'product') ||
            str_contains($field, 'creator') ||
            str_contains($field, 'user');

        if ($isNameField && !empty($clean)) {
            // Title Case for names and labels
            return ucwords(strtolower($clean));
        }

        // 5. Default: Just ensure trimmed
        return $clean;
    }


    /**
     * Robustly parse various date formats commonly found in imports.
     */
    public static function robustParseDate($val)
    {
        if (!$val || trim($val) === '' || trim($val) === '--' || trim($val) === 'N/A' || str_contains($val, '#'))
            return null;
        $val = trim((string) $val);

        // Handle Excel numeric date (serial number)
        if (is_numeric($val) && (float) $val > 30000 && (float) $val < 60000) {
            try {
                // PHP's date() and strtotime() might work, but this is the standard Excel conversion
                $parsed = date('Y-m-d', (int) (((float) $val - 25569) * 86400));
                if ($parsed && $parsed !== '1970-01-01') return $parsed;
            } catch (\Exception $e) {
            }
        }

        // Normalize separators
        $normalized = str_replace(['/', '.'], '-', $val);

        // DD-MM-YYYY
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $normalized, $m)) {
            $d = (int)$m[1]; $m_val = (int)$m[2]; $y = (int)$m[3];
            if (checkdate($m_val, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $m_val, $d);
            }
            return null;
        }

        // YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $normalized, $m)) {
            $y = (int)$m[1]; $m_val = (int)$m[2]; $d = (int)$m[3];
            if ($y > 0 && checkdate($m_val, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $m_val, $d);
            }
            return null;
        }

        // Fallback to Carbon for other formats
        try {
            $dt = \Illuminate\Support\Carbon::parse($val);
            if ($dt->year > 1900 && $dt->year < 2100) {
                return $dt->format('Y-m-d');
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}

