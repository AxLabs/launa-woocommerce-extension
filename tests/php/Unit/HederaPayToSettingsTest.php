<?php
declare(strict_types=1);

namespace Launa\WC\Tests\Unit;

use Launa_WC_Settings;
use PHPUnit\Framework\TestCase;

final class HederaPayToSettingsTest extends TestCase
{
    public function test_valid_hedera_account_id(): void
    {
        $this->assertTrue(Launa_WC_Settings::is_valid_hedera_account_id('0.0.123456'));
        $this->assertTrue(Launa_WC_Settings::is_valid_hedera_account_id('0.0.1'));
        $this->assertFalse(Launa_WC_Settings::is_valid_hedera_account_id('0xabc'));
        $this->assertFalse(Launa_WC_Settings::is_valid_hedera_account_id('not-an-id'));
        $this->assertFalse(Launa_WC_Settings::is_valid_hedera_account_id(''));
        $this->assertTrue(Launa_WC_Settings::is_valid_hedera_account_id('0.0.12345-abcde'));
        $parsed = Launa_WC_Settings::parse_hedera_account_id('0.0.12345-abcde');
        $this->assertTrue($parsed['ok']);
        $this->assertSame('0.0.12345', $parsed['value']);
        $this->assertSame('evm', Launa_WC_Settings::parse_hedera_account_id('0xabc')['error']);
    }
}
