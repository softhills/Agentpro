@props([
    'name',
    'value' => null,
])

{{--
    A naira field (FR-M7-05).

    Renders the input only, not a label or a fieldset, because the four forms
    that need one wrap their fields differently — a grid row, a stacked
    fieldset, an inline form — and a component that insisted on its own wrapper
    would have to be fought in three of them.

    type="text", not type="number", and that is the whole reason this exists.
    A number input cannot hold "4,500,000": the browser treats a comma as
    invalid, and reading .value back gives an empty string, so the grouping
    this market reads prices by is simply not available there. What is given up
    with it — the spinner, and min/max enforced in the browser — is worth
    little here. Nobody steps a rent up by one naira at a time, and every one
    of these fields is bounded by validation rules on the server, which is
    where the bound has to hold anyway.

    inputmode="decimal" keeps the numeric keypad on a phone, which is the part
    of type="number" that actually mattered.
--}}
<input
    name="{{ $name }}"
    type="text"
    inputmode="decimal"
    autocomplete="off"
    data-money
    value="{{ \App\Support\Money::field($value) }}"
    {{ $attributes->merge(['class' => 'finput']) }}
>

{{-- Once per page however many fields are on it, so a form can add a money
     field without having to remember to add a script tag too. --}}
@pushOnce('scripts', 'money-input')
<script src="{{ \App\Support\Asset::url('js/money-input.js') }}" defer></script>
@endPushOnce
