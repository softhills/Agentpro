<?php

namespace Tests\Unit;

use App\Support\Money;
use App\Support\MoneyInput;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The round trip a money field makes: Money::field() writes it into the box,
 * MoneyInput::clean() reads it back out. The two have to agree, because
 * between them sits a price somebody is going to be held to.
 */
class MoneyFieldTest extends TestCase
{
    public function test_a_figure_is_grouped_for_the_box(): void
    {
        $this->assertSame('4,500,000', Money::field(4500000));
        $this->assertSame('950', Money::field(950));
        $this->assertSame('0', Money::field(0));
    }

    public function test_kobo_show_only_when_there_are_any(): void
    {
        $this->assertSame('125,000', Money::field(125000.00));
        $this->assertSame('125,000.50', Money::field(125000.5));
        $this->assertSame('125,000.05', Money::field('125000.05'));
    }

    public function test_an_empty_field_stays_empty(): void
    {
        $this->assertSame('', Money::field(null));
        $this->assertSame('', Money::field(''));
    }

    /** A rejected submission has to come back as typed, not as a guess. */
    public function test_something_that_is_not_a_number_is_handed_back_untouched(): void
    {
        $this->assertSame('4,5OO,OOO', Money::field('4,5OO,OOO'));
        $this->assertSame('about 4m', Money::field('about 4m'));
    }

    public function test_grouping_is_stripped_before_validation(): void
    {
        $this->assertSame('4500000', MoneyInput::value('4,500,000'));
        $this->assertSame('125000.50', MoneyInput::value('125,000.50'));
        $this->assertSame('950', MoneyInput::value('950'));
        $this->assertSame('4500000', MoneyInput::value(' 4,500,000 '));
    }

    /**
     * The whole point of the strict grouping check: "12,34" is either a typo
     * or a comma used as a decimal point, and 1234 and 12.34 are both plausible
     * readings. Left alone it fails `numeric` and the user is asked; guessed
     * at, it silently becomes a figure they never typed.
     */
    public function test_a_badly_grouped_figure_is_left_for_the_validator(): void
    {
        $this->assertSame('12,34', MoneyInput::value('12,34'));
        $this->assertSame('1,2345', MoneyInput::value('1,2345'));
        $this->assertSame('4,500,000x', MoneyInput::value('4,500,000x'));
        $this->assertSame('', MoneyInput::value(''));
    }

    public function test_non_strings_pass_through(): void
    {
        $this->assertNull(MoneyInput::value(null));
        $this->assertSame(4500000, MoneyInput::value(4500000));
        $this->assertSame([], MoneyInput::value([]));
    }

    public function test_the_round_trip_preserves_the_figure(): void
    {
        foreach ([0, 950, 4500000, 125000.5, 99999999999] as $amount) {
            $this->assertSame(
                (float) $amount,
                (float) MoneyInput::value(Money::field($amount)),
                'round trip changed '.$amount,
            );
        }
    }

    public function test_nested_and_wildcard_paths_are_cleaned(): void
    {
        $request = Request::create('/', 'POST', [
            'unit' => ['price' => '4,500,000', 'bedrooms' => '3'],
            'fees' => [
                ['label' => 'Agency fee', 'amount' => '1,125,000'],
                ['label' => 'Legal fee', 'amount' => '500,000'],
                ['label' => 'Caution deposit', 'amount' => ''],
            ],
        ]);

        MoneyInput::clean($request, 'unit.price', 'fees.*.amount');

        $this->assertSame('4500000', $request->input('unit.price'));
        $this->assertSame('3', $request->input('unit.bedrooms'));
        $this->assertSame('1125000', $request->input('fees.0.amount'));
        $this->assertSame('500000', $request->input('fees.1.amount'));
        $this->assertSame('', $request->input('fees.2.amount'));
        $this->assertSame('Agency fee', $request->input('fees.0.label'));
    }

    /** A field nobody submitted must not appear, or `required` stops meaning it. */
    public function test_a_missing_field_is_not_invented(): void
    {
        $request = Request::create('/', 'POST', ['reason' => 'duplicate']);

        MoneyInput::clean($request, 'amount', 'unit.price', 'fees.*.amount');

        $this->assertFalse($request->has('amount'));
        $this->assertFalse($request->has('unit'));
        $this->assertSame(['reason' => 'duplicate'], $request->input());
    }

    public function test_uploaded_files_are_not_dragged_into_the_input_bag(): void
    {
        $request = Request::create('/', 'POST', ['amount' => '125,000'], [], [
            'photo' => new \Illuminate\Http\UploadedFile(__FILE__, 'photo.jpg', 'image/jpeg', null, true),
        ]);

        MoneyInput::clean($request, 'amount');

        $this->assertSame('125000', $request->input('amount'));
        // has() reads files as well, so ask the input bag itself: a merge that
        // wrote the UploadedFile back would leave an object here.
        $this->assertArrayNotHasKey('photo', $request->input());
        $this->assertNotNull($request->file('photo'));
    }
}
