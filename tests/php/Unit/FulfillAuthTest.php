<?php
declare(strict_types=1);

namespace Launa\WC\Tests\Unit;

use Launa_WC_Fulfill_Auth;
use PHPUnit\Framework\TestCase;

final class FulfillAuthTest extends TestCase
{
    public function test_valid_token(): void
    {
        $this->assertTrue(Launa_WC_Fulfill_Auth::is_valid_token('abc', 'abc'));
        $this->assertFalse(Launa_WC_Fulfill_Auth::is_valid_token('', 'abc'));
        $this->assertFalse(Launa_WC_Fulfill_Auth::is_valid_token('abc', 'abd'));
    }

    public function test_can_fulfill_status(): void
    {
        $this->assertTrue(Launa_WC_Fulfill_Auth::can_fulfill_status('pending'));
        $this->assertTrue(Launa_WC_Fulfill_Auth::can_fulfill_status('on-hold'));
        $this->assertFalse(Launa_WC_Fulfill_Auth::can_fulfill_status('completed'));
        $this->assertFalse(Launa_WC_Fulfill_Auth::can_fulfill_status('processing'));
    }
}
