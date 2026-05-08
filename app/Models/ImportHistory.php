<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role',
        'module_name',
        'action',
        'file_name',
        'file_path',
        'imported_by',
        'successful_rows',
        'failed_rows',
        'duplicates_count',
        'duplicate_file',
        'total_rows',
        'client_id',
        'data_snapshot'
    ];
}
