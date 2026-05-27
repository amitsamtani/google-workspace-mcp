<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A connected Google Workspace account. The `email` is the canonical handle
 * every gmail_* tool addresses via its required `account` parameter.
 */
class EmailAccount extends Model
{
    /** Uses added_at / last_used_at instead of Laravel's created/updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'email',
        'display_name',
        'refresh_token',
        'scopes',
        'added_at',
        'last_used_at',
    ];

    protected $hidden = [
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'added_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    /** @return list<string> the canonical set of connected account emails */
    public static function emails(): array
    {
        return static::query()->orderBy('email')->pluck('email')->all();
    }
}
