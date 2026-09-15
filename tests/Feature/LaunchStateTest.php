<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the home page says when there is nothing on it.
 *
 * It used to print "Run `php artisan migrate:fresh --seed` to load the
 * development inventory" — a note to a developer, on the public home page of a
 * live site, instructing anybody who read it to destroy the database. Above it
 * sat the heading "Every listing below is published by a verified lister", with
 * nothing below it.
 *
 * A launch-stage marketplace is empty for a while; that is survivable and worth
 * saying plainly. Leaking operational instructions to the public is not.
 */
class LaunchStateTest extends TestCase
{
    use RefreshDatabase;

    /** The bug, pinned. */
    public function test_the_public_home_page_never_prints_operational_instructions(): void
    {
        app()['env'] = 'production';

        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('artisan', $html);
        $this->assertStringNotContainsString('migrate:fresh', $html);
        $this->assertStringNotContainsString('development inventory', $html);
    }

    /**
     * An empty marketplace says so, and offers the one action that changes it:
     * the person most likely to be reading a property site with no property on
     * it is somebody who has property.
     */
    public function test_an_empty_home_page_says_so_and_offers_somewhere_to_go(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertSee('Nothing is live yet');
        $response->assertSee(route('register'), false);
    }

    /**
     * The heading claims every listing below is verified, so it must not appear
     * when there is nothing below it.
     */
    public function test_the_featured_heading_does_not_appear_over_an_empty_grid(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertDontSee('Every listing below is published by a verified lister');
    }

    /** The developer note still exists where it is useful. */
    public function test_the_hint_is_still_there_in_local_development(): void
    {
        app()['env'] = 'local';

        $this->get(route('home'))->assertOk()->assertSee('migrate:fresh', false);
    }
}
