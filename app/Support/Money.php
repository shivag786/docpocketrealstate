<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic for reward calculations.
 *
 * docs/05_CALCULATION_ENGINE_SPEC.md §F requires exact decimal arithmetic. PHP
 * floats cannot represent most decimal fractions, so every value here is passed
 * around as a STRING and computed with bcmath. A float must never appear between
 * reading Sq.Ft. from the database and writing an amount to the ledger.
 *
 * Division rounds half-up to the money scale — client-confirmed 2026-08-15
 * ("round off"). Because each share is rounded independently, the shares may not
 * re-sum to exactly the pool: ₹50,000 split three ways gives 3 × ₹16,666.67 =
 * ₹50,000.01. That residual is real money and must never be silently swallowed,
 * so callers are expected to record it (see UplineRewardService).
 */
final class Money
{
    public const SCALE = 2;

    /**
     * Multiply two decimal values, truncating to the money scale.
     *
     * Sq.Ft. carries 2 decimals and the rates are whole rupees, so a direct
     * reward is always exact at 2 decimals and nothing is lost here.
     */
    public static function multiply(string $a, string $b, int $scale = self::SCALE): string
    {
        self::assertNumeric($a);
        self::assertNumeric($b);

        return bcmul($a, $b, $scale);
    }

    /**
     * Divide, rounding half-up to the money scale.
     *
     * Client-confirmed rounding rule: "round off". ₹50,000 / 3 = ₹16,666.6667
     * becomes ₹16,666.67.
     *
     * @throws InvalidArgumentException when dividing by zero
     */
    public static function divide(string $a, string $b, int $scale = self::SCALE): string
    {
        self::assertNumeric($a);
        self::assertNumeric($b);

        if (bccomp($b, '0', 12) === 0) {
            throw new InvalidArgumentException('Division by zero in money arithmetic.');
        }

        // Extra precision first, then round — bcdiv alone truncates.
        return self::round(bcdiv($a, $b, $scale + 6), $scale);
    }

    /**
     * Round half-up to the given scale.
     *
     * bcadd truncates at its scale, so adding a half-unit first turns truncation
     * into half-up rounding without ever touching a float.
     */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        self::assertNumeric($value);

        $negative = bccomp($value, '0', $scale + 6) < 0;
        $absolute = $negative ? bcmul($value, '-1', $scale + 6) : $value;

        $half = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($absolute, $half, $scale);

        return $negative && bccomp($rounded, '0', $scale) !== 0
            ? bcmul($rounded, '-1', $scale)
            : $rounded;
    }

    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        self::assertNumeric($a);
        self::assertNumeric($b);

        return bcadd($a, $b, $scale);
    }

    public static function subtract(string $a, string $b, int $scale = self::SCALE): string
    {
        self::assertNumeric($a);
        self::assertNumeric($b);

        return bcsub($a, $b, $scale);
    }

    /**
     * Sum a list of decimal strings.
     *
     * @param  iterable<int, string>  $values
     */
    public static function sum(iterable $values, int $scale = self::SCALE): string
    {
        $total = self::zero($scale);

        foreach ($values as $value) {
            $total = self::add($total, (string) $value, $scale);
        }

        return $total;
    }

    /** -1, 0 or 1 */
    public static function compare(string $a, string $b, int $scale = self::SCALE): int
    {
        self::assertNumeric($a);
        self::assertNumeric($b);

        return bccomp($a, $b, $scale);
    }

    public static function isZero(string $value, int $scale = self::SCALE): bool
    {
        return self::compare($value, self::zero($scale), $scale) === 0;
    }

    public static function isPositive(string $value, int $scale = self::SCALE): bool
    {
        return self::compare($value, self::zero($scale), $scale) > 0;
    }

    public static function zero(int $scale = self::SCALE): string
    {
        return number_format(0, $scale, '.', '');
    }

    /**
     * Normalise any numeric input to a fixed-scale decimal string.
     */
    public static function of(int|float|string $value, int $scale = self::SCALE): string
    {
        $value = is_float($value) ? sprintf('%.'.($scale + 4).'F', $value) : (string) $value;

        self::assertNumeric($value);

        return bcadd($value, self::zero($scale), $scale);
    }

    public static function format(string $value): string
    {
        return number_format((float) $value, self::SCALE);
    }

    private static function assertNumeric(string $value): void
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Non-numeric value in money arithmetic: [{$value}].");
        }
    }
}
