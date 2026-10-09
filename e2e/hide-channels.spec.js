import { test, expect } from '@playwright/test';
import { signIn } from './helpers.js';

test.beforeEach(async ({ page }) => {
    await signIn(page);
    await page.goto('/admin/hide-channels');
});

test('hides a channel for my users and shows it again', async ({ page }) => {
    const row = page.locator('tr', { has: page.getByLabel('Select Sky Sports PL') });
    await expect(row.getByText('Shown')).toBeVisible();

    await page.getByLabel('Select Sky Sports PL').check();
    await expect(page.locator('#bulk-form')).toBeVisible();
    await page.locator('#bulk-form select[name=action]').selectOption('hide');
    await page.getByRole('button', { name: 'Apply' }).click();

    await expect(page.locator('.alert-success')).toContainText('1 channel hidden');
    await expect(row.getByText('Hidden', { exact: true })).toBeVisible();

    await page.getByLabel('Select Sky Sports PL').check();
    await page.locator('#bulk-form select[name=action]').selectOption('show');
    await page.getByRole('button', { name: 'Apply' }).click();

    await expect(page.locator('.alert-success')).toContainText('1 channel shown again');
    await expect(row.getByText('Shown')).toBeVisible();
});
