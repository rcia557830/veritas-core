<?php

namespace Tests\Unit;

use App\Services\Accounting\LedgerBalance;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LedgerBalanceTest extends TestCase
{
    public function test_signed_integer_limits_are_exact_without_float_conversion(): void
    {
        $this->assertSame(PHP_INT_MAX, Money::addCents(PHP_INT_MAX - 1, 1));
        $this->assertSame(PHP_INT_MIN, Money::subtractCents(PHP_INT_MIN + 1, 1));
        $this->assertSame(0, Money::subtractCents(PHP_INT_MIN, PHP_INT_MIN));
        $this->assertSame(-1, Money::addCents(PHP_INT_MAX, PHP_INT_MIN));
        $this->assertSame('₱92,233,720,368,547,758.08 Cr', Money::balance(PHP_INT_MIN));
        $balance = new LedgerBalance;
        $balance->apply(0, PHP_INT_MAX, true);
        $balance->apply(0, 1, true);
        $this->assertSame(PHP_INT_MIN, $balance->opening);
        $balance->apply(PHP_INT_MAX, 0, false);
        $this->assertSame(-1, $balance->closing);
    }

    public static function overflows(): array
    {
        return [['addCents', PHP_INT_MAX, 1], ['addCents', PHP_INT_MIN, -1], ['subtractCents', PHP_INT_MIN, 1], ['subtractCents', PHP_INT_MAX, -1], ['subtractCents', 0, PHP_INT_MIN]];
    }

    #[DataProvider('overflows')]
    public function test_overflow_fails_explicitly(string $operation, int $left, int $right): void
    {
        $this->expectException(ValidationException::class);
        Money::$operation($left, $right);
    }

    public function test_gross_total_overflow_is_rejected_even_if_net_balance_is_small(): void
    {
        $balance = new LedgerBalance;
        $balance->apply(PHP_INT_MAX, 0, false);
        $balance->apply(0, PHP_INT_MAX, false);
        $this->assertSame(0, $balance->closing);
        $this->expectException(ValidationException::class);
        $balance->apply(1, 0, false);
    }

    public function test_opening_plus_movement_overflow_is_rejected(): void
    {
        $balance = new LedgerBalance;
        $balance->apply(PHP_INT_MAX, 0, true);
        $this->expectException(ValidationException::class);
        $balance->apply(1, 0, false);
    }
}
