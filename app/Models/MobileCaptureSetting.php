<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MobileCaptureSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'camera_tested_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'is_enabled' => false,
            'access_token' => Str::random(40),
            'camera_status' => 'untested',
        ]);
    }

    public function regenerateToken(): void
    {
        $this->update([
            'access_token' => Str::random(40),
            'last_seen_at' => null,
            'last_ip' => null,
            'last_user_agent' => null,
            'camera_status' => 'untested',
            'camera_error' => null,
            'camera_tested_at' => null,
        ]);
    }
}
