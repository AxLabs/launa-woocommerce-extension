import { test, expect } from '@playwright/test';

/**
 * UI smoke: requires wp-env running with seeded products + configured gateway.
 * MetaMask signing is manual — this only asserts the pay page shell renders.
 */
test.describe('Launa pay page shell', () => {
  test.skip(!process.env.E2E_ORDER_KEY, 'Set E2E_ORDER_KEY to a pending Launa order key');

  test('renders pay root', async ({ page }) => {
    const key = process.env.E2E_ORDER_KEY as string;
    await page.goto(`/checkout/?launa_pay=1&key=${encodeURIComponent(key)}`);
    await expect(page.locator('#launa-pay-root')).toBeVisible();
    await expect(page.getByRole('heading', { name: /Pay securely with your wallet/i })).toBeVisible();
  });
});
