<?php

namespace App\Policies;

use App\Models\SSL;
use App\Models\Superadmin;
use Illuminate\Auth\Access\Response;

class SSLPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(Superadmin $superadmin): bool
    {
        //
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(Superadmin $superadmin, SSL $sSL): bool
    {
        //
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(Superadmin $superadmin): bool
    {
        //
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(Superadmin $superadmin, SSL $sSL): bool
    {
        //
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(Superadmin $superadmin, SSL $sSL): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(Superadmin $superadmin, SSL $sSL): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(Superadmin $superadmin, SSL $sSL): bool
    {
        //
    }
}
