<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tool extends Model
{
    use HasFactory, \App\Traits\GracePeriodTrait;
    protected $table = 'tools';
    protected $guarded = [];
}
