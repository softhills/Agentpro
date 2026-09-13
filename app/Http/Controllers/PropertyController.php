<?php

namespace App\Http\Controllers;

use App\Enums\LifecycleState;
use App\Models\Property;
use App\Support\Analytics;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PropertyController extends Controller
{
    public function show(Property $property)
    {
        // A draft or rejected listing is not merely hidden from search — it must
        // 404 on direct URL access too, or the uuid becomes a bypass (SEC-03).
        if (! in_array($property->lifecycle_state->value, LifecycleState::publiclyVisible(), true)) {
            throw new NotFoundHttpException();
        }

        /*
         * FR-M13-01. Recorded after the 404 check above, so a probe for a draft
         * uuid cannot inflate anybody's numbers, and before the page is built,
         * so a slow render does not lose the view.
         */
        Analytics::listingViewed($property);

        $property->load([
            'units.feeLines',
            'units.priceHistory',
            'media',
            'titleClaims',
            'realsureRecords.officer:id,name',
            'amenities',
            'area',
            'lister:id,name,verification_state,category',
        ]);

        return view('pages.show', [
            'property' => $property,
            'unit'     => $property->headlineUnit(),
        ]);
    }
}
