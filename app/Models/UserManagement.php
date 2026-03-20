<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserManagement extends Model
{
    use HasFactory, \App\Traits\GracePeriodTrait;
    protected $table = 'user_management';
    protected $guarded = [];

    public function remarkHistories()
    {
        return $this->hasMany(RemarkHistory::class, 'record_id')->where('module', 'UserManagement');
    }
}
