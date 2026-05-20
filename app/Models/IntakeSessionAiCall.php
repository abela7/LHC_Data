<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeSessionAiCall extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'call_index' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IntakeSession::class, 'intake_session_id');
    }

    public function request(): array
    {
        $decoded = json_decode((string) $this->request_json, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function response(): array
    {
        $decoded = json_decode((string) $this->response_json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
