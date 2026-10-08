import { test, expect } from '@playwright/test';
import { signIn, ADMIN } from './helpers.js';

test('sends guests to the sign-in page', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});

test('refuses a wrong password', async ({ page }) => {
    await signIn(page, { username: 'admin', password: 'wrong' });

    await expect(page.getByRole('alert')).toContainText('do not match');
    await expect(page).toHaveURL(/\/login$/);
});

test('signs in and out', async ({ page }) => {
    await signIn(page);
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
    await expect(page.locator('.sidebar-user strong')).toHaveText(ADMIN.username);

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login$/);

    await page.goto('/admin/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});
