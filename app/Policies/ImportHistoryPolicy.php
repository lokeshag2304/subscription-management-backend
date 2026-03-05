<?php

namespace App\Policies;

use App\Models\ImportHistory;
use App\Models\Superadmin;
use Illuminate\Auth\Access\Response;

class ImportHistoryPolicy
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
    public function view(Superadmin $superadmin, ImportHistory $importHistory): bool
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
    public function update(Superadmin $superadmin, ImportHistory $importHistory): bool
    {
        //
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(Superadmin $superadmin, ImportHistory $importHistory): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(Superadmin $superadmin, ImportHistory $importHistory): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(Superadmin $superadmin, ImportHistory $importHistory): bool
    {
        //
    }
}
