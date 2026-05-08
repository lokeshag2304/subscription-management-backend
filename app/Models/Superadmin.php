<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Superadmin extends Authenticatable implements JWTSubject
{
    protected $table = 'superadmins';

    protected $fillable = [
        'name', 'email', 'password', 'number', 'address', 'profile', 'domain_id', 'otp_enabled', 'login_type', 'status', 'added_by', 'd_password',
        'country', 'country_code', 'dial_code', 'phone_number'
    ];

    // JWT methods
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
}
