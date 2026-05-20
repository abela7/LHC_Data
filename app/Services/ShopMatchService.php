<?php

namespace App\Services;

use App\Models\ShopMatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ShopMatchService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function sync(Model $matchable, array $data, ?User $actor = null): ShopMatch
    {
        $existing = ShopMatch::query()->whereMorphedTo('matchable', $matchable)->first();

        $payload = [
            'shop_match_status' => $data['shop_match_status'] ?? $existing?->shop_match_status ?? 'unknown',
            'confidence' => $data['confidence'] ?? null,
            'confirmation_method' => $data['confirmation_method'] ?? null,
            'confirmed_by' => $data['confirmed_by'] ?? $actor?->id,
            'confirmed_at' => $data['confirmed_at'] ?? (filled($data['confirmation_method'] ?? null) || filled($data['shop_match_status'] ?? null) ? now() : null),
            'notes' => $data['notes'] ?? null,
            'updated_by' => $actor?->id,
        ];

        if (! $existing) {
            $payload['created_by'] = $actor?->id;
        } elseif ($existing->created_by) {
            $payload['created_by'] = $existing->created_by;
        }

        return $matchable->shopMatch()->updateOrCreate([], $payload);
    }
}
