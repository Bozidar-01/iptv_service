import { test, expect } from '@playwright/test';
import { signIn, totp, ADMIN } from './helpers.js';

// One story from set-up to turning it off again: the steps depend on each other
test('two-factor sign-in from set-up to turning it off', async ({ page }) => {
    await signIn(page);
    await page.goto('/admin/security');

    // Set up: QR code and key, wrong code first
    await page.getByRole('button', { name: 'Set up two-factor sign-in' }).click();
    await expect(page.locator('.qr svg')).toBeVisible();
    const secret = (await page.locator('code').first().textContent()).trim();
    expect(secret).toMatch(/^[A-Z2-7]{32}$/);

    await page.getByLabel('Code').fill('000000');
    await page.getByRole('button', { name: 'Turn on' }).click();
    await expect(page.locator('.field-error')).toBeVisible();

    await page.getByLabel('Code').fill(totp(secret));
    await page.getByRole('button', { name: 'Turn on' }).click();
    await expect(page.locator('.alert-success')).toBeVisible();
    const codes = await page.locator('.recovery-codes li').allTextContents();
    expect(codes).toHaveLength(8);

    // Sign out. The password alone no longer signs in; a wrong code is refused
    await page.getByRole('button', { name: 'Sign out' }).click();
    await signIn(page);
    await expect(page).toHaveURL(/\/two-factor$/);
    await page.getByLabel('Code').fill('123456');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('alert')).toContainText('not right');

    // The code of the next 30 seconds is accepted (and is newer than the one used for set-up)
    await page.getByLabel('Code').fill(totp(secret, 1));
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard$/);

    // A recovery code works once
    await page.getByRole('button', { name: 'Sign out' }).click();
    await signIn(page);
    await page.getByLabel('Code').fill(codes[0]);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
    await page.getByRole('button', { name: 'Sign out' }).click();
    await signIn(page);
    await page.getByLabel('Code').fill(codes[0]);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('alert')).toContainText('not right');

    // Turning it off needs the password, and the sign-in history shows what happened
    await page.getByLabel('Code').fill(codes[1]);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.goto('/admin/security');
    await expect(page.locator('table')).toContainText('Wrong code');

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: 'Turn off' }).click();
    await expect(page.locator('.alert-success')).toContainText('off');
    await expect(page.getByRole('button', { name: 'Set up two-factor sign-in' })).toBeVisible();
});
