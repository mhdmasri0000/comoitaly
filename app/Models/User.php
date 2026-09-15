<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['id', 'first_name', 'last_name', 'email', 'phone_number', 'password_hash', 'profile_image', 'role', 'language', 'dealer_price_factor', 'fcm_token', 'fcm_platform', 'credentials_generated'])]
#[Hidden(['password_hash', 'remember_token', 'fcm_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Serve profile images as a relative path (or an external URL unchanged).
     */
    protected function profileImage(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => \App\Support\MediaUrl::toRelative($value),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'dealer_price_factor' => 'decimal:2',
            'credentials_generated' => 'boolean',
        ];
    }
}
