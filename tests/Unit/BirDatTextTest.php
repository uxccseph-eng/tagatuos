<?php

namespace Tests\Unit;

use App\Services\BirDatText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BirDatTextTest extends TestCase
{
    #[DataProvider('textCases')]
    public function test_names_and_addresses_use_only_plain_dat_characters(?string $source, string $expected): void
    {
        $actual = BirDatText::clean($source);
        $this->assertSame($expected, $actual);
        $this->assertMatchesRegularExpression('/^[A-Z0-9 ]*$/D', $actual);
        $this->assertSame($actual, BirDatText::clean($actual));
    }

    /** @return array<string, array{?string, string}> */
    public static function textCases(): array
    {
        return [
            'company' => [' Peña & Co., Inc. ', 'PENA AND CO INC'],
            'individual' => ['O’Brian-Santos', 'OBRIAN SANTOS'],
            'address' => ['Unit #12-B / Main St. (East)', 'UNIT 12 B MAIN ST EAST'],
            'whitespace' => ["Brgy.\r\nSan\tJosé\u{00a0}🚚", 'BRGY SAN JOSE'],
            'separators' => ['A,B;C|D"E<F>G\\H', 'A B C D E F G H'],
            'symbols only' => ['&%$#!!!🚚', ''],
            'empty' => ['', ''],
            'null' => [null, ''],
        ];
    }
}
