import { test, expect } from '@playwright/test';
import { signIn, ADMIN } from './helpers.js';

// From the admin giving a reseller credits to the reseller paying for a user, with the price shown while typing.
test('a reseller with credits sees the price and pays for a new user', async ({ page }) => {
    // The admin creates a reseller with 3 credits and one bouquet to hand out
    await signIn(page);
    await page.goto('/admin/users/create');
    await page.getByLabel('Username').fill('credit-reseller');
    await page.getByLabel('Password').first().fill('reseller-pass-1');
    await page.getByLabel('Role').selectOption('reseller');
    await page.getByLabel('Starting credits').fill('3');
    await page.getByText('Sports Pack').first().click();
    await page.getByRole('button', { name: 'Create user' }).click();
    await expect(page.locator('.alert-success')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Credits' })).toBeVisible();
    await expect(page.locator('.stat-value').first()).toHaveText('3');

    // ... then signs out, and the reseller signs in
    await page.getByRole('button', { name: 'Sign out' }).click();
    await signIn(page, { username: 'credit-reseller', password: 'reseller-pass-1' });
    await expect(page.getByText('Your credits')).toBeVisible();

    // The price follows the end date: 1 month = 1 credit, 3 months = 3
    await page.goto('/admin/users/create');
    await page.getByLabel('Username').fill('paid-user');
    await page.getByLabel('Password').fill('user-pass-1');
    await page.getByText('Sports Pack').first().click();
    await expect(page.getByRole('button', { name: 'Never' })).toHaveCount(0);

    // (the shortcuts add to the date already in the field, which starts as +1 month: clear it before each try)
    const clear = () => page.locator('#expiration_date').fill('');
    await expect(page.locator('#credit-cost')).toHaveText('This costs 1 credit. You have 3.'); // the pre-filled date
    await clear();
    await page.getByRole('button', { name: '+1 month' }).click();
    await expect(page.locator('#credit-cost')).toHaveText('This costs 1 credit. You have 3.');
    await clear();
    await page.getByRole('button', { name: '+3 months' }).click();
    await expect(page.locator('#credit-cost')).toHaveText('This costs 3 credits. You have 3.');
    await clear();
    await page.getByRole('button', { name: '+1 year' }).click();
    await expect(page.locator('#credit-cost')).toHaveText('This costs 12 credits. You have 3.');

    // Too expensive: refused, nothing created
    await page.getByRole('button', { name: 'Create user' }).click();
    await expect(page.locator('.alert-danger')).toContainText('Not enough credits');

    // Affordable: created, 1 credit used, 2 left (a refused form comes back without the password)
    await page.getByLabel('Password').fill('user-pass-1');
    await clear();
    await page.getByRole('button', { name: '+1 month' }).click();
    await page.getByRole('button', { name: 'Create user' }).click();
    await expect(page.locator('.alert-success')).toContainText('1 credit used, 2 left');

    await page.goto('/admin/credits');
    await expect(page.locator('table')).toContainText('New subscription');
    await expect(page.locator('table')).toContainText('paid-user');
    await expect(page.locator('.stat-value')).toHaveText('2');
});
