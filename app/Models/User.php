<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
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
        'email',
        'password',
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
    public function role()
    {
        return $this->belongsTo(\App\Models\Role::class);
    }

    public function hasRole(string $slug): bool
    {
        return optional($this->role)->slug === $slug;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }
    public function metaPages()
    {
        return $this->belongsToMany(MetaPage::class, 'meta_page_user')
            ->withPivot(['page_access_token', 'social_account_id', 'expires_at', 'is_active'])
            ->withTimestamps();
    }
}
