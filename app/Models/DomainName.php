<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainName extends Model
{
    protected $table = 'domain';
    public $timestamps = false;
    protected $fillable = ['name', 'client_id'];

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
