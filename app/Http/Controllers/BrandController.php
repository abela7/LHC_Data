<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\RedirectResponse;

class BrandController extends Controller
{
    public function destroy(Brand $brand): RedirectResponse
    {
        $linkedFamilies = $brand->families()->count();
        $brandName = $brand->name;

        $brand->delete();

        $status = "Brand '{$brandName}' deleted.";

        if ($linkedFamilies > 0) {
            $status .= " {$linkedFamilies} linked family record(s) were left in place and unassigned from the deleted brand.";
        }

        return redirect()
            ->route('review.index')
            ->with('status', $status);
    }
}
