import { test, expect } from '@playwright/test'

test.describe('Phase 7 critical journeys', () => {
  test('portal redirects to login when unauthenticated', async ({ page }) => {
    await page.goto('/portal')
    await expect(page.getByRole('heading', { name: 'Customer Portal' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible()
  })

  test('admin redirects to login when unauthenticated', async ({ page }) => {
    await page.goto('/admin')
    await expect(page.getByRole('heading', { name: 'Administration' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible()
  })

  test('portal login shows dashboard after authentication', async ({ page }) => {
    await page.goto('/portal/login')
    await page.getByLabel('Email').fill('customer@tittawin.local')
    await page.getByLabel('Password').fill('ChangeMe123!')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByRole('heading', { name: 'Welcome to Tittawin' })).toBeVisible({ timeout: 15000 })
    await expect(page.getByText('Active orders')).toBeVisible()
  })

  test('admin login shows operations dashboard', async ({ page }) => {
    await page.goto('/admin/login')
    await page.getByLabel('Email').fill('admin@tittawin.local')
    await page.getByLabel('Password').fill('ChangeMe123!')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByRole('heading', { name: 'Operations dashboard' })).toBeVisible({ timeout: 15000 })
    await expect(page.getByText('Pending orders')).toBeVisible()
  })
})
