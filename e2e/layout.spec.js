import { test, expect } from '@playwright/test';
import { signIn } from './helpers.js';

test('keeps the logo on top and the account at the bottom while the menu scrolls', async ({ page }) => {
    // A short window, so the menu is longer than the sidebar
    await page.setViewportSize({ width: 1280, height: 520 });
    await signIn(page);

    const box = async (selector) => page.locator(selector).first().boundingBox();
    const nav = page.locator('.sidebar > nav');

    const metrics = await nav.evaluate((el) => ({ scrollable: el.scrollHeight > el.clientHeight, top: el.scrollTop }));
    expect(metrics.scrollable).toBe(true);

    const before = { brand: await box('.sidebar > .brand'), footer: await box('.sidebar-footer') };
    await nav.evaluate((el) => { el.scrollTop = el.scrollHeight; });
    await page.mouse.wheel(0, 600); // the page itself may scroll too
    const after = { brand: await box('.sidebar > .brand'), footer: await box('.sidebar-footer') };

    // Nothing in the top or bottom zone moved
    // (within a pixel: a scrolled page can shift a sticky box by a fraction of one)
    for (const part of ['brand', 'footer']) {
        expect(Math.abs(after[part].y - before[part].y)).toBeLessThan(1);
    }
    // The account row sits at the bottom of the window, the logo at the top, the whole menu still reachable
    expect(Math.round(after.footer.y + after.footer.height)).toBeLessThanOrEqual(521);
    expect(after.brand.y).toBeGreaterThan(-1);
    await expect(page.getByRole('link', { name: 'Security' })).toBeInViewport();
    await expect(page.locator('.sidebar-footer')).toBeInViewport();
});

test('keeps the top bar with the language, mode and bell in view while the page scrolls', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 520 });
    await signIn(page);
    await page.goto('/admin/channels');

    await page.mouse.wheel(0, 1500);
    await expect(page.locator('.header-bar')).toBeInViewport();
    const bar = await page.locator('.header-bar').boundingBox();
    expect(Math.abs(bar.y)).toBeLessThan(1);
    await expect(page.locator('.header-bar .lang-menu')).toBeInViewport();
    await expect(page.locator('.header-bar .theme-switch')).toBeInViewport();
    await expect(page.locator('.header-bar .notif-menu')).toBeInViewport();
});

test('shrinks the menu to icons that show the page name on hover, and remembers it', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 });
    await signIn(page);
    const toggle = page.getByRole('button', { name: 'Show or hide the menu' });
    const sidebar = page.locator('.sidebar');
    const label = page.locator('.sidebar .nav-label', { hasText: 'Bouquets' });

    await expect(label).toBeVisible();
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect.poll(async () => (await sidebar.boundingBox()).width).toBeLessThan(80);
    await expect(label).toBeHidden();

    // Pointing at an icon shows its name; the strip stays on the next page
    await page.getByRole('link', { name: 'Bouquets' }).hover();
    await expect(page.locator('.nav-tip')).toHaveText('Bouquets');
    await page.goto('/admin/channels');
    await expect.poll(async () => (await sidebar.boundingBox()).width).toBeLessThan(80);

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await page.reload();
    await expect(label).toBeVisible();
});

test('on a phone the opened menu keeps the logo and the account in view too', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 560 });
    await signIn(page);

    await page.getByRole('button', { name: 'Open menu' }).click();
    await expect(page.locator('.sidebar > .brand')).toBeInViewport();
    await expect(page.locator('.sidebar-footer')).toBeInViewport();
    await page.locator('.sidebar > nav').evaluate((el) => { el.scrollTop = el.scrollHeight; });
    await expect(page.locator('.sidebar-footer')).toBeInViewport();
    await expect(page.getByRole('link', { name: 'Security' })).toBeInViewport();
});
