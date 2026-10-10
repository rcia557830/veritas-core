<?php

namespace Tests\Unit;

use App\Support\Money;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_decimal_formats_integer_cents_exactly(int $cents, string $expected): void
    {
        $this->assertSame($expected, Money::decimal($cents));
    }

    public static function amounts(): array
    {
        return [
            [0, '0.00'], [1, '0.01'], [-1, '-0.01'], [99, '0.99'], [-99, '-0.99'],
            [100, '1.00'], [-100, '-1.00'], [123, '1.23'], [-123, '-1.23'],
            [99999999999, '999999999.99'], [-99999999999, '-999999999.99'],
            [PHP_INT_MAX, PHP_INT_SIZE === 8 ? '92233720368547758.07' : '21474836.47'],
            [PHP_INT_MIN, PHP_INT_SIZE === 8 ? '-92233720368547758.08' : '-21474836.48'],
        ];
    }

    public function test_non_negative_input_validation_is_preserved(): void
    {
        $this->expectException(ValidationException::class);
        Money::cents('-0.01');
    }
}
