<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads a grouped money field back off a form (FR-M7-05).
 *
 * Money::field() writes "4,500,000" into the box and money-input.js keeps it
 * grouped as the user types, so what arrives at the server has commas in it —
 * and "4,500,000" is not `numeric`. Every form that renders <x-money-input>
 * has to run its fields through here before validate(), or the validator
 * rejects a perfectly good price.
 *
 * Doing it in the controllers, by name, rather than in a middleware that
 * strips commas from everything: a middleware would silently rewrite values in
 * fields nobody meant to be money — a listing title, a memo, an address — and
 * the damage would be invisible until someone noticed their address had lost
 * its comma. The field list is short and belongs next to the rules it feeds.
 *
 * The deliberate trade-off is that forgetting this call breaks the field
 * loudly, on the first submission, for everybody. That is the failure we want:
 * stripping commas in JavaScript on submit as well would leave only the people
 * without JavaScript broken, which is nobody in testing and somebody in
 * production.
 */
final class MoneyInput
{
    /**
     * Normalise the named fields in place. Paths are dot notation and may use
     * `*` for a wildcard, exactly as validation rules spell the same fields:
     *
     *     MoneyInput::clean($request, 'unit.price', 'fees.*.amount');
     */
    public static function clean(Request $request, string ...$paths): void
    {
        // input() rather than all(): all() folds uploaded files into the array,
        // and merging those back into the input bag puts UploadedFile objects
        // where the rest of the request expects strings.
        $input = $request->input();

        foreach ($paths as $path) {
            self::walk($input, explode('.', $path));
        }

        $request->merge($input);
    }

    /**
     * One value, with the grouping removed.
     *
     * Only a correctly grouped number is touched. "12,34" is not one — it is
     * either a typo or someone using a comma as a decimal point — and guessing
     * between 1,234 and 12.34 would be inventing a figure the user never typed.
     * Left alone, it fails `numeric` and they are asked to fix it, which on a
     * price field is the only honest outcome.
     */
    public static function value(mixed $raw): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        $trimmed = trim($raw);

        if (! preg_match('/^-?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?$/', $trimmed)) {
            return $raw;
        }

        return str_replace(',', '', $trimmed);
    }

    /**
     * @param  array<array-key,mixed>  $data
     * @param  list<string>  $path
     */
    private static function walk(array &$data, array $path): void
    {
        $key = array_shift($path);

        if ($key === '*') {
            foreach ($data as &$item) {
                if ($path === []) {
                    $item = self::value($item);
                } elseif (is_array($item)) {
                    self::walk($item, $path);
                }
            }

            unset($item);

            return;
        }

        // A field that was not submitted stays not submitted: creating it here
        // as an empty string would turn a missing value into a present one and
        // change what `required` and `nullable` mean for it.
        if (! array_key_exists($key, $data)) {
            return;
        }

        if ($path === []) {
            $data[$key] = self::value($data[$key]);

            return;
        }

        if (is_array($data[$key])) {
            self::walk($data[$key], $path);
        }
    }
}
