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
