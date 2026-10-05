<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The validator speaks to listers, not to the schema.
 *
 * "The lng field must be between -180 and 180." is what a lister saw on the
 * listing form, at the top of the page, with no way to tell which box it meant.
 * The field is labelled Longitude; the message named the column. A refusal
 * nobody can act on is a form that cannot be saved.
 */
class ValidationMessagesTest extends TestCase
{
    private function messageFor(array $data, array $rules): string
    {
        return Validator::make($data, $rules)->errors()->first();
    }

    public function test_a_coordinate_is_called_what_the_form_calls_it(): void
    {
        $message = $this->messageFor(
            ['lng' => 7000],
            ['lng' => ['required', 'numeric', 'between:-180,180']],
        );

        $this->assertStringContainsString('Longitude', $message);
        $this->assertStringNotContainsString('lng', $message);
    }

    /** The two mistakes that actually produce these refusals. */
    public function test_a_coordinate_refusal_says_where_the_numbers_come_from(): void
    {
        // Eastings and northings off a survey plan: metres, six figures.
        $this->assertStringContainsString(
            'survey plan',
            $this->messageFor(['lat' => 712345], ['lat' => ['numeric', 'between:-90,90']]),
        );

        // The pair pasted into one box.
        $this->assertStringContainsString(
            'both numbers together',
            $this->messageFor(['lng' => '6.4584, 7.5464'], ['lng' => ['numeric', 'between:-180,180']]),
        );
    }

    public function test_the_rest_of_the_listing_form_is_named_in_plain_words(): void
    {
        foreach ([
            'area_id'       => 'area',
            'address_line'  => 'street address',
            'what3words'    => 'three-word address',
            'unit.price'    => 'price',
            // fees.*.amount is not here: a wildcard over an array that was not
            // submitted has no rows to walk and so produces no message at all.
            // It has its own test below, with a row in it.
        ] as $field => $expected) {
            $message = $this->messageFor([], [$field => ['required']]);

            $this->assertStringContainsString($expected, $message, $field.' is not named in plain words');
            $this->assertStringNotContainsString('_', $message, $field.' leaked a column name');
        }
    }

    /**
     * A row of the fee table is "fee amount", not "fees.2.amount". Laravel
     * resolves the wildcard itself; this is here because that only holds while
     * the key in the attributes list matches how the rule spells it.
     */
    public function test_a_numbered_row_is_not_reported_by_its_index(): void
    {
        $message = $this->messageFor(
            ['fees' => [['amount' => 'x']]],
            ['fees.*.amount' => ['numeric']],
        );

        $this->assertStringContainsString('fee amount', $message);
        $this->assertStringNotContainsString('fees.0', $message);
    }

    /** Everything not listed still comes from the framework's own file. */
    public function test_the_framework_messages_are_not_lost(): void
    {
        $this->assertSame(
            'The title field is required.',
            $this->messageFor([], ['title' => ['required']]),
        );
    }
}
