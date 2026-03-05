<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_name',
        'client_name',
        'amount',
        'renewal_date',
        'deletion_date',
        'days_left',
        'days_to_delete',
        'status',
        'remarks',
    ];
}
