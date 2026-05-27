<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per mutating tool call. Written by App\Services\AuditLogger; never
 * by tools directly. Read-only thereafter (queried via gworkspace:audit).
 */
class AuditLog extends Model
{
    protected $table = 'mcp_audit_log';

    /** The schema uses a single `timestamp` column, not created/updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'timestamp',
        'account',
        'tool',
        'params',
        'thread_ids',
        'result_status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'params' => 'array',
            'thread_ids' => 'array',
        ];
    }
}
