<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $table = 'import_logs';

    protected $fillable = [
        'module',
        'file_name',
        'total_rows',
        'inserted',
        'duplicate',
        'failed',
        'duplicate_file',
        'imported_by',
        'client_id',
    ];
}
