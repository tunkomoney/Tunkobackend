<?php

namespace App\Services;

use InvalidArgumentException;

/** Money helpers that operate on integer minor units; floats are never used. */
final class TransferMoney
{
    public static function scale(string $currency): int
    {
        return (int) config('money.currency_scales.' . strtoupper($currency), 2);
    }

    public static function units(string|int|float $amount, string $currency): int
    {
        $value = trim((string) $amount);
        if (!preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Amount must be a positive decimal value.');
        }
        $scale = self::scale($currency);
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw new InvalidArgumentException("{$currency} amount has too many decimal places.");
        }
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $factor = $scale === 0 ? 1 : 100;
        $units = ((int) $matches[1] * $factor) + ($scale === 0 ? 0 : (int) $fraction);
        if ($units <= 0) {
            throw new InvalidArgumentException('Transfer amount must be greater than zero.');
        }
        return $units;
    }

    public static function fee(int $amountUnits, int $percent): int
    {
        // Half-up to the currency's smallest unit, using integers only.
        return intdiv(($amountUnits * $percent) + 50, 100);
    }

    public static function decimal(int $units, string $currency): string
    {
        $scale = self::scale($currency);
        if ($scale === 0) return (string) $units;
        return intdiv($units, 100) . '.' . str_pad((string) ($units % 100), 2, '0', STR_PAD_LEFT);
    }
}
