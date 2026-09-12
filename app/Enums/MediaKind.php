<?php

namespace App\Enums;

/**
 * Media types on a listing (M3).
 *
 * Ranking matters: a card shows at most one media glyph (design §card anatomy),
 * because a card advertising every medium it holds stops communicating anything.
 */
enum MediaKind: string
{
    case Photo      = 'photo';
    case Video      = 'video';
    case Tour3d     = 'tour_3d';
    case Pano360    = 'pano_360';
    case FloorPlan  = 'floor_plan';
    case Drone      = 'drone';
    case StreetView = 'street_view';

    public function label(): string
    {
        return match ($this) {
            self::Photo      => 'Photos',
            self::Video      => 'Video',
            self::Tour3d     => '3D tour',
            self::Pano360    => '360°',
            self::FloorPlan  => 'Floor plan',
            self::Drone      => 'Drone',
            self::StreetView => 'Street View',
        };
    }

    /** Short label for the single glyph shown on a property card. */
    public function glyph(): string
    {
        return match ($this) {
            self::Tour3d    => '3D',
            self::Video     => 'Video',
            self::Pano360   => '360°',
            self::FloorPlan => 'Floor plan',
            self::Drone     => 'Drone survey',
            default         => '',
        };
    }

    /**
     * Which medium wins the one glyph slot on a card, best first.
     * @return array<int,self>
     */
    public static function glyphPriority(): array
    {
        return [self::Tour3d, self::Video, self::Pano360, self::Drone, self::FloorPlan];
    }

    /**
     * Media that must never autoplay or preload — it loads only on tap (NFR-02).
     * A seeker on a metered connection must be able to read a whole listing
     * without spending a naira on media they did not ask for.
     */
    public function isGated(): bool
    {
        return in_array($this, [self::Video, self::Tour3d, self::Pano360, self::StreetView], true);
    }

    /** Order of the tabs in the media viewer. */
    public static function viewerOrder(): array
    {
        return [self::Photo, self::Video, self::Tour3d, self::Pano360, self::StreetView, self::FloorPlan];
    }
}
