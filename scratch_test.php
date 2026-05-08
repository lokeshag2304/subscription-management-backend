<?php
require __DIR__ . '/vendor/autoload.php';
use Illuminate\Support\Carbon;

$val = '##########';
try {
    $dt = Carbon::parse($val);
    echo "Parsed: " . $dt->format('Y-m-d') . " Year: " . $dt->year . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
