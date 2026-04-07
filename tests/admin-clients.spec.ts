import { test, expect } from '@playwright/test';

// Generate random test data
function generateTestData() {
  const ts = Date.now();
  const suffix = Math.random().toString(36).substring(2, 6).toUpperCase();
  return {
    clientId: `TEST-${ts.toString(36).slice(-4).toUpperCase()}${Math.floor(Math.random() * 900 + 100)}`,
    displayName: `Test Firma ${suffix} s.r.o.`,
    email: `test-${ts}@example.cz`,
    password: `TestPass${suffix}123!`,
    ico: String(Math.floor(10000000 + Math.random() * 90000000)), // 8 digits
    contactName: `Jan Testovac ${suffix}`,
    contactPhone: `+420 ${Math.floor(600000000 + Math.random() * 99999999)}`,
    contactEmail: `contact-${ts}@example.cz`,
    contactRole: 'Facility Manager',
  };
}

test.describe('Admin Client Management', () => {
  let testData: ReturnType<typeof generateTestData>;

  test.beforeAll(() => {
    testData = generateTestData();
  });

  test('Create complete client and verify persistence', async ({ page }) => {
    // Step 1: Login as admin
    const adminEmail = process.env.ADMIN_EMAIL || 'admin@fajnuklid.cz';
    const adminPassword = process.env.ADMIN_PASSWORD;
    if (!adminPassword) throw new Error('ADMIN_PASSWORD env variable required');

    await page.goto('/#/login');
    await page.fill('#login-email-input', adminEmail);
    await page.fill('#login-password-input', adminPassword);
    await page.click('#login-submit-btn');
    await page.waitForURL('**/#/admin/clients');

    // Step 2: Navigate to new client form (use stable ID selector)
    await page.click('#btn-add-client');
    await page.waitForURL('**/#/admin/clients/new');
    await page.waitForSelector('#sec-basic');

    // Step 3: Fill basic info (already has IDs)
    await page.fill('#input-displayName', testData.displayName);
    await page.fill('#input-clientId', testData.clientId);

    // Step 4: Add login account (use stable ID selectors)
    await page.click('#nav-sec-logins');
    await page.click('#btn-add-login');
    await page.waitForSelector('#login-email-0');
    await page.fill('#login-email-0', testData.email);
    await page.fill('#login-password-0', testData.password);

    // Step 5: Add ICO (use stable ID selectors)
    await page.click('#nav-sec-icos');
    await page.click('#btn-add-ico');
    await page.waitForSelector('#ico-number-0');
    await page.fill('#ico-number-0', testData.ico);
    await page.fill('#ico-name-0', testData.displayName);

    // Step 6: Add contact (use stable ID selectors)
    await page.click('#nav-sec-contacts');
    await page.click('#btn-add-contact');
    await page.waitForSelector('#contact-name-0');
    await page.fill('#contact-name-0', testData.contactName);
    await page.fill('#contact-role-0', testData.contactRole);
    await page.fill('#contact-phone-0', testData.contactPhone);
    await page.fill('#contact-email-0', testData.contactEmail);

    // Step 7: Add staff (with proper waiting to avoid race conditions)
    await page.click('#nav-sec-staff');
    await page.waitForSelector('#sec-staff');
    const addStaffBtn = page.locator('#btn-add-staff');
    const isEnabled = await addStaffBtn.isEnabled({ timeout: 2000 }).catch(() => false);
    if (isEnabled) {
      await addStaffBtn.click();
      const picker = page.locator('#employee-picker-modal');
      await picker.waitFor({ state: 'visible', timeout: 5000 });
      const firstEmployee = picker.locator('.picker-item').first();
      const hasEmployees = await firstEmployee.isVisible({ timeout: 2000 }).catch(() => false);
      if (hasEmployees) {
        await firstEmployee.click();
      } else {
        await picker.locator('button[aria-label="Zavřít"]').click();
      }
    }

    // Step 8: Save (use stable ID selector)
    await page.click('#btn-save-client');
    await expect(page.locator('.saved-msg').first()).toBeVisible({ timeout: 10000 });
    // Wait for URL to change (SPA navigation - don't wait for load event)
    await page.waitForURL(/\/admin\/clients\/[^n]/, { waitUntil: 'commit' });

    // Step 9: Refresh and verify persistence
    await page.reload();
    await page.waitForSelector('#sec-basic');

    // Verify basic info
    await expect(page.locator('#input-displayName')).toHaveValue(testData.displayName);
    await expect(page.locator('#input-clientId')).toHaveValue(testData.clientId);
    await expect(page.locator('#input-clientId')).toBeDisabled();

    // Verify login (use stable ID selector)
    await expect(page.locator('#login-email-0')).toHaveValue(testData.email);

    // Verify ICO (expand first, then check with stable ID selector)
    await page.locator('.ico-card-header').first().click();
    await expect(page.locator('#ico-number-0')).toHaveValue(testData.ico);

    // Verify contact (use stable ID selector)
    await expect(page.locator('#contact-name-0')).toHaveValue(testData.contactName);
  });
});
