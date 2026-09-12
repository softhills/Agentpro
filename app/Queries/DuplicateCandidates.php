<?php

namespace App\Queries;

use App\Enums\LifecycleState;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * FR-M2-12: duplicate detection at review time.
 *
 * This raises a flag for a human rather than blocking a submission, because the
 * false-positive case is real and common — a developer legitimately lists eight
 * units in one block, and two agents may both hold a genuine mandate on the same
 * property. Blocking those automatically would drive supply away; flagging them
 * costs a moderator ten seconds.
 *
 * Proximity search reuses the spatial index the map search already depends on,
 * so this costs nothing extra to run.
 */
class DuplicateCandidates
{
    /** Two listings within this distance are worth a second look. */
    private const RADIUS_METRES = 120;

    public function for(Property $property): Collection
    {
        $nearby = Property::query()
            ->where('id', '!=', $property->id)
            ->whereIn('lifecycle_state', [
                LifecycleState::Published->value,
                LifecycleState::Submitted->value,
                LifecycleState::UnderReview->value,
            ])
            ->withinMetres($property->lat, $property->lng, self::RADIUS_METRES)
            ->with(['units', 'lister:id,name'])
            ->limit(10)
            ->get();

        return $nearby->map(function (Property $candidate) use ($property) {
            return [
                'property' => $candidate,
                'reasons'  => $this->reasons($property, $candidate),
            ];
        })->filter(fn ($row) => $row['reasons'] !== [])->values();
    }

    /**
     * Why this candidate is being surfaced. Stated explicitly so the moderator
     * can judge rather than trust a score they cannot interrogate.
     *
     * @return list<string>
     */
    private function reasons(Property $subject, Property $candidate): array
    {
        $reasons = [];

        $metres = $this->distance($subject, $candidate);

        if ($metres < 25) {
            $reasons[] = sprintf('Same location (within %dm)', max(1, (int) $metres));
        } else {
            $reasons[] = sprintf('%dm away', (int) $metres);
        }

        if ($candidate->lister_id !== $subject->lister_id) {
            $reasons[] = 'Different lister';
        }

        similar_text(
            mb_strtolower($subject->title),
            mb_strtolower($candidate->title),
            $titleSimilarity
        );

        if ($titleSimilarity > 70) {
            $reasons[] = sprintf('Title %d%% similar', (int) $titleSimilarity);
        }

        $subjectUnit   = $subject->headlineUnit();
        $candidateUnit = $candidate->headlineUnit();

        if ($subjectUnit && $candidateUnit) {
            if ($subjectUnit->bedrooms !== null
                && $subjectUnit->bedrooms === $candidateUnit->bedrooms
                && (float) $subjectUnit->price === (float) $candidateUnit->price) {
                $reasons[] = 'Identical bedrooms and price';
            }
        }

        // A bare distance reading on its own is not a duplicate signal — in
        // Lekki that would flag half the street. Something else has to match.
        return count($reasons) > 1 ? $reasons : [];
    }

    private function distance(Property $a, Property $b): float
    {
        $earth = 6_371_000;
        $dLat  = deg2rad($b->lat - $a->lat);
        $dLng  = deg2rad($b->lng - $a->lng);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($a->lat)) * cos(deg2rad($b->lat)) * sin($dLng / 2) ** 2;

        return $earth * 2 * asin(min(1.0, sqrt($h)));
    }
}
