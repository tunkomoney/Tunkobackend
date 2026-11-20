<?php

namespace Tests\Unit;

use App\Services\TransferMoney;
use InvalidArgumentException;
use Tests\TestCase;

class TransferMoneyTest extends TestCase
{
    public function test_wallet_fee_rounds_to_minor_unit_without_floats(): void
    {
        $amount = TransferMoney::units('10000.00', 'NGN');
        self::assertSame('100.00', TransferMoney::decimal(TransferMoney::fee($amount, 1), 'NGN'));
        self::assertSame('10200.00', TransferMoney::decimal($amount + TransferMoney::fee($amount, 2), 'NGN'));
    }

    public function test_xof_is_rounded_and_stored_as_whole_units(): void
    {
        self::assertSame(10000, TransferMoney::units('10000', 'XOF'));
        self::assertSame('200', TransferMoney::decimal(TransferMoney::fee(10000, 2), 'XOF'));
    }

    public function test_xof_rejects_fractional_units(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TransferMoney::units('12.50', 'XOF');
    }
}
