<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainName extends Model
{
    protected $table = 'domain_master';
    public $timestamps = true;
    protected $fillable = ['domain_name', 'name', 'client_id'];

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
