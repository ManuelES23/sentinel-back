<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'photo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
        ];
    }

    /**
     * Get the enterprises for the user.
     */
    public function enterprises(): BelongsToMany
    {
        return $this->belongsToMany(Enterprise::class, 'user_enterprises')
                    ->withPivot(['role', 'is_active', 'granted_at', 'expires_at'])
                    ->withTimestamps();
    }

    /**
     * Get the applications for the user.
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'user_applications')
                    ->withPivot(['permissions', 'is_active', 'granted_at', 'expires_at'])
                    ->withTimestamps();
    }

    /**
     * Get active enterprises for the user.
     */
    public function activeEnterprises(): BelongsToMany
    {
        return $this->enterprises()->wherePivot('is_active', true);
    }

    /**
     * Get active applications for the user.
     */
    public function activeApplications(): BelongsToMany
    {
        return $this->applications()->wherePivot('is_active', true);
    }

    /**
     * Check if user has access to an enterprise.
     */
    public function hasEnterpriseAccess(string $enterpriseSlug): bool
    {
        return $this->activeEnterprises()
                   ->where('enterprises.slug', $enterpriseSlug)
                   ->exists();
    }

    /**
     * Check if user has access to an application.
     */
    public function hasApplicationAccess(string $enterpriseSlug, string $applicationSlug): bool
    {
        return $this->activeApplications()
                   ->whereHas('enterprise', function ($query) use ($enterpriseSlug) {
                       $query->where('slug', $enterpriseSlug);
                   })
                   ->where('applications.slug', $applicationSlug)
                   ->exists();
    }

    /**
     * Get the submodules permissions for the user.
     */
    public function submodules(): BelongsToMany
    {
        return $this->belongsToMany(Submodule::class, 'user_submodule_permissions')
                    ->withPivot(['can_view', 'can_create', 'can_edit', 'can_delete', 'is_active', 'granted_at', 'expires_at'])
                    ->withTimestamps();
    }

    /**
     * Get the employee record linked to this user.
     */
    public function employee()
    {
        return $this->hasOne(Employee::class);
    }
}
