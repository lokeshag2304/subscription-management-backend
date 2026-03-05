<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportExportHistory extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'file_name'
    ];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
