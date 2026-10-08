import { test, expect } from '@playwright/test';
import { signIn } from './helpers.js';

test('remembers dark and light mode across pages and reloads', async ({ page }) => {
    await signIn(page);
    const html = page.locator('html');

    await page.getByRole('button', { name: 'Dark' }).click();
    await expect(html).toHaveAttribute('data-theme', 'dark');
    await expect(page.getByRole('button', { name: 'Dark' })).toHaveAttribute('aria-pressed', 'true');

    await page.goto('/admin/channels');
    await expect(html).toHaveAttribute('data-theme', 'dark');

    await page.getByRole('button', { name: 'Light' }).click();
    await page.reload();
    await expect(html).toHaveAttribute('data-theme', 'light');
});

test('switches the panel to Macedonian and back', async ({ page }) => {
    await signIn(page);

    await page.locator('.lang-menu summary').click();
    await page.getByRole('button', { name: /Македонски/ }).click();
    await expect(page.locator('.sidebar nav')).toContainText('Канали');

    await page.locator('.lang-menu summary').click();
    await page.getByRole('button', { name: /English/ }).click();
    await expect(page.locator('.sidebar nav')).toContainText('Channels');
});
