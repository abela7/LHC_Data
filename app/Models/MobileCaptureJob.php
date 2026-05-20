<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MobileCaptureJob extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public static function newToken(): string
    {
        do {
            $token = Str::random(40);
        } while (self::query()->where('token', $token)->exists());

        return $token;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING
            && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
