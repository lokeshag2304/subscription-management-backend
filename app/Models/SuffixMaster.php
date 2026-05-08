<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuffixMaster extends Model
{
    protected $table = 'suffix_masters';
    public $timestamps = true;
    protected $fillable = ['suffix'];
}
