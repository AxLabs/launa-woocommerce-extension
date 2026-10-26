<?php
declare(strict_types=1);

namespace Launa\WC\Tests\Unit;

use Launa_WC_Settings;
use PHPUnit\Framework\TestCase;

final class SettingsEnvironmentBaseUrlTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previousGetenv = [];

    /** @var array<string, mixed> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        foreach (['LAUNA_BASE_URL', 'AX402_BASE_URL'] as $name) {
            $this->previousGetenv[$name] = getenv($name);
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
            putenv($name);
            unset($_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['LAUNA_BASE_URL', 'AX402_BASE_URL'] as $name) {
            $previous = $this->previousGetenv[$name];
            if ($previous === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $previous);
            }
            if ($this->previousEnv[$name] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $this->previousEnv[$name];
            }
        }
    }

    public function test_launa_base_url_wins_over_legacy(): void
    {
        putenv('AX402_BASE_URL=https://api.example.test');
        putenv('LAUNA_BASE_URL=https://api.staging.launa.io');
        $this->assertSame('https://api.staging.launa.io', Launa_WC_Settings::environment_base_url());
    }

    public function test_legacy_base_url_still_applies(): void
    {
        putenv('AX402_BASE_URL=https://api.staging.launa.io');
        $this->assertSame('https://api.staging.launa.io', Launa_WC_Settings::environment_base_url());
    }

    public function test_environment_base_url_empty_when_unset(): void
    {
        $this->assertSame('', Launa_WC_Settings::environment_base_url());
    }
}
