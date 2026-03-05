<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemarkHistory extends Model
{
    protected $table = 'remark_histories';
    protected $fillable = ['module', 'record_id', 'remark', 'user_name'];
}
