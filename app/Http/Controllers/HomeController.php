<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Property;

class HomeController extends Controller
{
    public function __invoke()
    {
        // Verified stock leads the home page — the trust proposition is the
        // product, so it should not be something a visitor has to filter for.
        $featured = Property::query()
            ->onMarket()
            ->with(['units.feeLines', 'media', 'area', 'lister:id,name,verification_state'])
            ->orderByRaw('realsure_verified_at IS NULL')
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return view('pages.home', [
            'featured' => $featured,
            'areas'    => Area::orderBy('city')->orderBy('name')->get(),
        ]);
    }
}
