<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'is_active',
        'last_login_at',
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

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            $defaults = config('nadi.defaults');

            $user->settings()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'locale' => $defaults['locale'] ?? 'id',
                    'appearance' => $defaults['appearance'] ?? 'system',
                    'density' => $defaults['density'] ?? 'comfortable',
                    'analysis_mode' => $defaults['analysis_mode'] ?? 'simple',
                    'default_currency' => $defaults['currency'] ?? 'IDR',
                    'default_market' => $defaults['market'] ?? 'IDX',
                    'default_timeframe' => $defaults['timeframe'] ?? '1Y',
                    'timezone' => $defaults['timezone'] ?? 'Asia/Jakarta',
                ],
            );

            $defaultRole = Role::query()->where('slug', 'user')->first();
            if ($defaultRole) {
                $user->roles()->syncWithoutDetaching([$defaultRole->id]);
            }
        });
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('created_at');
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function savedScreeners(): HasMany
    {
        return $this->hasMany(SavedScreener::class)->orderByDesc('updated_at');
    }

    public function hasRole(string $slug): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains('slug', $slug);
        }

        return $this->roles()->where('slug', $slug)->exists();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function hasAbility(string $ability): bool
    {
        $roleAbilities = config('nadi_permissions.roles', []);
        $roles = $this->relationLoaded('roles')
            ? $this->roles->pluck('slug')->all()
            : $this->roles()->pluck('slug')->all();

        foreach ($roles as $slug) {
            if (in_array($ability, $roleAbilities[$slug] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function ensureDefaultWatchlist(): Watchlist
    {
        return $this->watchlists()->firstOrCreate(
            ['is_default' => true],
            ['name' => 'Watchlist Utama'],
        );
    }
}
