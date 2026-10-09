import { test, expect } from '@playwright/test';
import { signIn } from './helpers.js';

test.beforeEach(async ({ page }) => {
    await signIn(page);
});

test('copies the playlist link and says so', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto('/admin/users');
    await page.getByRole('link', { name: 'user2' }).first().click();

    const button = page.locator('[data-copy="#playlist-url"]');
    await button.click();

    await expect(button.locator('[data-copy-label]')).toHaveText('Copied');
    const link = await page.locator('#playlist-url').inputValue();
    expect(link).toContain('/playlist/');
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(link);

    await expect(button.locator('[data-copy-label]')).toHaveText('Copy');
});

test('asks before deleting a category and only deletes on yes', async ({ page }) => {
    await page.goto('/admin/categories');
    const row = page.locator('tr', { hasText: 'News' });
    await expect(row).toBeVisible();

    page.once('dialog', (dialog) => dialog.dismiss());
    await row.getByRole('button', { name: /delete/i }).click();
    await expect(row).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await row.getByRole('button', { name: /delete/i }).click();
    await expect(page.locator('.alert-success')).toBeVisible();
    await expect(page.locator('tr', { hasText: 'News' })).toHaveCount(0);
});

test('shows and copies the password on the user page', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto('/admin/users/create');
    await page.getByLabel('Username').fill('reveal-user');
    await page.getByLabel('Password').fill('reveal-pass-1');
    await page.getByRole('button', { name: 'Create user' }).click();

    const field = page.locator('#xtream-password');
    await expect(field).toHaveAttribute('type', 'password');

    await page.getByRole('button', { name: 'Show current password' }).click();
    await expect(field).toHaveAttribute('type', 'text');
    await expect(field).toHaveValue('reveal-pass-1');

    await page.getByRole('button', { name: 'Hide current password' }).click();
    await expect(field).toHaveAttribute('type', 'password');

    await page.locator('[data-copy="#xtream-password"]').click();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('reveal-pass-1');
});

test('makes a trial and shows the message to send', async ({ page }) => {
    await page.goto('/admin/users/trial');
    await page.locator('input[name="bouquets[]"]').first().check();
    await page.getByRole('button', { name: 'Create trial' }).click();

    await expect(page.locator('.alert-success')).toContainText('Trial created');
    const message = page.locator('#login-message');
    await expect(message).toHaveValue(/Username: trial\d{5}/);
    await expect(message).toHaveValue(/Password: [a-z0-9]{8}/);
    await expect(page.getByText('Trial', { exact: true }).first()).toBeVisible();
});

test('renews a user for three months and the trial badge goes away', async ({ page }) => {
    await page.goto('/admin/users/trial');
    await page.locator('input[name="bouquets[]"]').first().check();
    await page.getByRole('button', { name: 'Create trial' }).click();
    await expect(page.locator('.alert-success')).toContainText('Trial created');

    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('select[name="months"]').selectOption('3');
    await page.getByRole('button', { name: 'Renew', exact: true }).click();

    await expect(page.locator('.alert-success')).toContainText('Renewed for 3 months');
    await expect(page.locator('h1 + p .badge', { hasText: 'Trial' })).toHaveCount(0);
});
