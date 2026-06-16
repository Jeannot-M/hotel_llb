<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable // implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone_number',
        'is_admin',
        'role',
        'can_access_terrasse',
        'can_access_hebergement',
        'password',
    ];

    /**
     * User Roles
     */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_RECEPTIONIST = 'receptionist';
    public const ROLE_ACCOUNTANT = 'accountant';

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Check if user is an admin
     */
    public function isAdmin(): bool
    {
        return $this->is_admin || $this->role === self::ROLE_ADMIN;
    }

    /**
     * Check if user is a receptionist
     */
    public function isReceptionist(): bool
    {
        return $this->role === self::ROLE_RECEPTIONIST || $this->isAdmin();
    }

    /**
     * Check if user is an accountant
     */
    public function isAccountant(): bool
    {
        return $this->role === self::ROLE_ACCOUNTANT || $this->isAdmin();
    }

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
            'is_admin' => 'boolean',
            'can_access_terrasse' => 'boolean',
            'can_access_hebergement' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
