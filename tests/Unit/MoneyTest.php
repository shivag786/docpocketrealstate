<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic every reward amount passes through.
 *
 * A float error here would silently corrupt payouts across all four engines, so
 * these run as plain unit tests with no framework or database involved.
 */
class MoneyTest extends TestCase
{
    #[Test]
    public function it_multiplies_sqft_by_a_rate_exactly(): void
    {
        // docs/06_TESTING_AND_ACCEPTANCE.md: 1,500 × 40 = 60,000
        $this->assertSame('60000.00', Money::multiply('1500.00', '40'));
    }

    /**
     * @return array<int, array{string, string, string}>
     */
    public static function multiplicationCases(): array
    {
        return [
            ['1500.00', '40', '60000.00'],
            ['1500.00', '50', '75000.00'],
            ['5000.00', '30', '150000.00'],   // Target 1 reward
            ['0.01', '40', '0.40'],
            ['1234.56', '40', '49382.40'],
            ['999999.99', '40', '39999999.60'],
        ];
    }

    #[Test]
    #[DataProvider('multiplicationCases')]
    public function multiplication_is_exact(string $sqft, string $rate, string $expected): void
    {
        $this->assertSame($expected, Money::multiply($sqft, $rate));
    }

    #[Test]
    public function it_avoids_the_classic_float_error(): void
    {
        // 0.1 + 0.2 !== 0.3 in binary floating point. It must here.
        $this->assertSame('0.30', Money::add('0.10', '0.20'));

        // A float would give 1104.9999999999998 for this product.
        $this->assertSame('1105.00', Money::multiply('27.625', '40', 2));
    }

    #[Test]
    public function it_sums_a_list_without_drift(): void
    {
        $values = array_fill(0, 100, '0.01');

        $this->assertSame('1.00', Money::sum($values));
    }

    #[Test]
    public function it_sums_realistic_sale_totals(): void
    {
        $this->assertSame(
            '6000.50',
            Money::sum(['1000.00', '2000.00', '3000.50'])
        );
    }

    #[Test]
    public function it_normalises_mixed_input_types(): void
    {
        $this->assertSame('1500.00', Money::of(1500));
        $this->assertSame('1500.50', Money::of('1500.5'));
        $this->assertSame('1500.50', Money::of(1500.50));
    }

    #[Test]
    public function it_compares_values(): void
    {
        $this->assertSame(0, Money::compare('5000.00', '5000.00'));
        $this->assertSame(-1, Money::compare('4999.99', '5000.00'));
        $this->assertSame(1, Money::compare('5000.01', '5000.00'));
    }

    #[Test]
    public function it_identifies_zero_and_positive_values(): void
    {
        $this->assertTrue(Money::isZero('0.00'));
        $this->assertTrue(Money::isZero(Money::zero()));
        $this->assertFalse(Money::isZero('0.01'));

        $this->assertTrue(Money::isPositive('0.01'));
        $this->assertFalse(Money::isPositive('0.00'));
        $this->assertFalse(Money::isPositive('-1.00'));
    }

    #[Test]
    public function it_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::multiply('not-a-number', '40');
    }

    #[Test]
    public function division_is_deliberately_absent(): void
    {
        // The upline rounding rule is unconfirmed; adding divide() before the
        // client answers would bake a guess about money into the system.
        $this->assertFalse(
            method_exists(Money::class, 'divide'),
            'Money::divide() must not exist until the upline rounding rule is confirmed.'
        );
    }
}
