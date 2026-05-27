<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The user's BYOGCP OAuth client (Client ID + Secret), stored encrypted.
 *
 * Note on style: Eloquent in Laravel 13 still configures casts via the
 * casts() method (there is no #[Cast]/#[Table] attribute), so we use that.
 */
class OauthCredential extends Model
{
    protected $fillable = [
        'client_id',
        'client_secret',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
        ];
    }

    /**
     * The active OAuth client. v1 expects a single row; we take the newest.
     */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    public static function configured(): bool
    {
        return static::query()->exists();
    }
}
