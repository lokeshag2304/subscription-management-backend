<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Product;
use App\Models\Superadmin;
use App\Models\Vendor;

class Hosting extends Model
{
    use HasFactory, \App\Traits\GracePeriodTrait;
    
    protected $table = 'hostings';

    protected $fillable = [
        'domain_master_id',
        'product_id',
        'client_id',
        'vendor_id',
        'bill_type',
        'start_date',
        'amount',
        'renewal_date',
        'deletion_date',
        'days_left',
        'days_to_delete',
        'grace_period',
        'due_date',
        'status',
        'currency',
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
        return $this->hasMany(RemarkHistory::class, 'record_id')->where('module', 'Hosting');
    }
}
