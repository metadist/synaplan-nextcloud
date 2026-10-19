import { defineConfig, devices } from '@playwright/test'

/**
 * Journeys against a real Nextcloud (docker-compose.ci.yml) and the Synaplan
 * test stack. The files run in order with one worker: they share the app's
 * mode and the person's Synaplan link. See README, section Development.
 */
export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: './tests/e2e/global-setup.ts',
	fullyParallel: false,
	workers: 1,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	timeout: 90_000,
	expect: { timeout: 30_000 },
	reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
	use: {
		baseURL: process.env.NC_URL ?? 'http://localhost:8081',
		locale: 'en-US',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
