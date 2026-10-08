import { test, expect } from '@playwright/test';
import { signIn } from './helpers.js';

test.beforeEach(async ({ page }) => {
    await signIn(page);
    await page.goto('/admin/channels');
});

test('shows the bulk bar once something is selected', async ({ page }) => {
    const bar = page.locator('#bulk-form');
    await expect(bar).toBeHidden();

    await page.getByLabel('Select Sky Sports PL').check();
    await expect(bar).toBeVisible();
    await expect(page.locator('#bulk-count')).toHaveText('1 selected');

    await page.getByLabel('Select HBO HD').check();
    await expect(page.locator('#bulk-count')).toHaveText('2 selected');

    await page.getByLabel('Select Sky Sports PL').uncheck();
    await page.getByLabel('Select HBO HD').uncheck();
    await expect(bar).toBeHidden();
});

test('selects every channel on the page and clears them again', async ({ page }) => {
    const rows = page.locator('.row-check');
    const total = await rows.count();
    expect(total).toBeGreaterThan(5);

    await page.getByLabel('Select all on this page').check();
    await expect(page.locator('#bulk-count')).toHaveText(`${total} selected`);
    await expect(rows.first()).toBeChecked();

    await page.getByLabel('Select all on this page').uncheck();
    await expect(page.locator('#bulk-form')).toBeHidden();
});

test('asks for a bouquet only for bouquet actions', async ({ page }) => {
    await page.getByLabel('Select EuroSport').check();
    const bouquet = page.locator('#bulk-bouquet');

    await expect(bouquet).toBeHidden();
    await page.getByLabel('Bulk action').selectOption('add_to_bouquet');
    await expect(bouquet).toBeVisible();
    await page.getByLabel('Bulk action').selectOption('disable');
    await expect(bouquet).toBeHidden();
});

test('disables a selected channel', async ({ page }) => {
    await page.getByLabel('Select Discovery').check();
    await page.getByLabel('Bulk action').selectOption('disable');
    await page.getByRole('button', { name: 'Apply' }).click();

    await expect(page.locator('.alert-success')).toBeVisible();
    await expect(page.locator('tr', { hasText: 'Discovery' })).toContainText('Disabled');
});

test('asks before deleting, and keeps the channel when you say no', async ({ page }) => {
    const row = page.locator('tr', { hasText: 'RTE Sport' });
    await page.getByLabel('Select RTE Sport').check();
    await page.getByLabel('Bulk action').selectOption('delete');

    let asked = '';
    page.once('dialog', (dialog) => { asked = dialog.message(); dialog.dismiss(); });
    await page.getByRole('button', { name: 'Apply' }).click();

    expect(asked).not.toBe('');
    await expect(row).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.locator('.alert-success')).toBeVisible();
    await expect(page.locator('tr', { hasText: 'RTE Sport' })).toHaveCount(0);
});
