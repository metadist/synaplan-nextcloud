import { expect, test } from '@playwright/test'

test('the login page loads none of the app scripts', async ({ page }) => {
	await page.goto('/index.php/login')
	await expect(page.locator('#user')).toBeVisible()
	await expect(page.locator('script[src*="synaplan_integration"]')).toHaveCount(0)
	await expect(
		page.getByRole('button', { name: 'Chat with Synaplan AI' }),
	).toHaveCount(0)
})
