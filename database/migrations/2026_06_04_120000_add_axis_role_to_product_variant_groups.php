<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an explicit, user-assigned axis role to each variant group so the
 * retail family variant model no longer has to *guess* main / sub-main / common
 * from the group name or type.
 *
 *   axis_role = 'main'      the primary differentiator (e.g. Length)
 *   axis_role = 'sub_main'  the secondary differentiator (e.g. Colour)
 *   axis_role = 'common'    pinned to a single family-wide value (e.g. Pack 3x)
 *   axis_role = NULL        unset -> fall back to the legacy heuristic
 *
 * Stored as a nullable string (not an ENUM) so the SQLite test database and the
 * MySQL production database behave identically. Existing families are left NULL
 * on purpose: they keep working through the heuristic fallback until a role is
 * assigned in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variant_groups', function (Blueprint $table): void {
            $table->string('axis_role', 20)->nullable()->after('variant_type');
        });
    }

    public function down(): void
    {
        Schema::table('product_variant_groups', function (Blueprint $table): void {
            $table->dropColumn('axis_role');
        });
    }
};
