<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role_id', 'status', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'last_login_at' => 'datetime'];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->status === 'Active' && $this->role?->slug === $role;
    }

    public function hasAnyRole(array|string $roles): bool
    {
        foreach ((array) $roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->status === 'Active' && (bool) $this->role?->permissions()->where('name', $permission)->exists();
    }

    public function hasAnyPermission(array|string $permissions): bool
    {
        foreach ((array) $permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function clients()
    {
        return $this->hasMany(Client::class, 'created_by');
    }

    public function assignedClients()
    {
        return $this->hasMany(Client::class, 'assigned_to');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class, 'created_by');
    }

    public function knowledgeArticles()
    {
        return $this->hasMany(KnowledgeArticle::class, 'author_id');
    }
}
