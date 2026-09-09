<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /* -----------------------------------------------------------------
     |  Role helpers (foundation only — see App\Providers\AuthServiceProvider
     |  for the Gate definitions that build on these).
     | ----------------------------------------------------------------- */

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function canWriteInventory(): bool
    {
        return $this->is_active && $this->role?->canWriteInventory() === true;
    }

    /* -----------------------------------------------------------------
     |  Domain relationships (Tahap 5.1). Explicit names — a user relates
     |  to assets / logs / aliases / batches through several distinct FKs.
     | ----------------------------------------------------------------- */

    /** @return HasMany<Asset, $this> */
    public function createdAssets(): HasMany
    {
        return $this->hasMany(Asset::class, 'created_by');
    }

    /** @return HasMany<Asset, $this> */
    public function updatedAssets(): HasMany
    {
        return $this->hasMany(Asset::class, 'updated_by');
    }

    /** Mutation logs recorded by this user (`mutation_logs.performed_by`). @return HasMany<MutationLog, $this> */
    public function mutationLogs(): HasMany
    {
        return $this->hasMany(MutationLog::class, 'performed_by');
    }

    /** @return HasMany<RoomAlias, $this> */
    public function createdRoomAliases(): HasMany
    {
        return $this->hasMany(RoomAlias::class, 'created_by');
    }

    /** @return HasMany<ImportBatch, $this> */
    public function uploadedImportBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'uploaded_by');
    }
}
