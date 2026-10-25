<?php

namespace App\Support;

/** Display formatting for minor-unit amounts (emails). Never used for maths. */
class Money
{
    private const SYMBOLS = ['NGN' => '₦', 'USD' => '$', 'GBP' => '£'];

    public static function format(int $minor, string $currency): string
    {
        $symbol = self::SYMBOLS[strtoupper($currency)] ?? strtoupper($currency).' ';

        return $symbol.number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
