import { defineConfig } from '@playwright/test';

// Browser tests for the admin panel's JavaScript (bulk selection, dialogs, theme, copy button, two-factor).
// Run with `npm run e2e`. Uses the installed Google Chrome (no browser download); needs PHP and the
// composer dependencies on this machine. The app runs on its own SQLite file (see e2e/serve.sh).
export default defineConfig({
    testDir: 'e2e',
    testMatch: '*.spec.js',
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8765',
        channel: 'chrome',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'sh e2e/serve.sh',
        url: 'http://127.0.0.1:8765/login',
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
