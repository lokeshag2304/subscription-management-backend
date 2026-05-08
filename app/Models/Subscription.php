<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Product;
use App\Models\Superadmin;
use App\Models\Vendor;
use App\Models\RemarkHistory;

class Subscription extends Model
{
    use HasFactory, \App\Traits\GracePeriodTrait;

    protected $fillable = [
        'domain_master_id',
        'product_id',
        'client_id',
        'vendor_id',
        'amount',
        'currency',
        'renewal_date',
        'deletion_date',
        'days_left',
        'days_to_delete',
        'grace_period',
        'due_date',
        'status',
        'remarks',
    ];

    public function domainMaster()
    {
        return $this->belongsTo(DomainName::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function client()
    {
        return $this->belongsTo(Superadmin::class, 'client_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function remarkHistories()
    {
        return $this->hasMany(RemarkHistory::class, 'record_id')->where('module', 'Subscription');
    }
}
