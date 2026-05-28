<?php

namespace App\Console\Commands;

use App\Models\ProductFamily;
use App\Services\PinkCommerceBridge;
use Illuminate\Console\Command;

/**
 * Push published product families to the Pink-Commerce (Railway) API + R2.
 *
 *   php artisan pink:push           # push every published family (backfill)
 *   php artisan pink:push 123       # push one family by id (handy for testing)
 */
class PinkPushCommand extends Command
{
    protected $signature = 'pink:push {family? : ProductFamily id (omit to push all published families)}';

    protected $description = 'Push published product families to the Pink-Commerce API + R2';

    public function handle(PinkCommerceBridge $bridge): int
    {
        if (! $bridge->isEnabled()) {
            $this->error('Pink-Commerce bridge is disabled. Set PINKCOMMERCE_ENABLED=true, PINKCOMMERCE_API_URL and PINKCOMMERCE_INGEST_TOKEN in .env.');

            return self::FAILURE;
        }

        $query = ProductFamily::query();
        if ($id = $this->argument('family')) {
            $query->whereKey($id);
        } else {
            $query->whereNotNull('published_at');
        }

        $families = $query->get();
        if ($families->isEmpty()) {
            $this->warn('No families to push.');

            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;
        foreach ($families as $family) {
            $result = $bridge->pushFamily($family);
            if ($result['ok'] ?? false) {
                $ok++;
                $this->info("✓ #{$family->id} {$family->family_name}");
            } else {
                $fail++;
                $this->error("✗ #{$family->id} ".json_encode($result));
            }
        }

        $this->line("Done. {$ok} ok, {$fail} failed.");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
