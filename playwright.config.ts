import { defineConfig } from '@playwright/test';

export default defineConfig({
  // Output folder for test artifacts (screenshots, videos, traces)
  outputDir: './playwright-output/test-results',

  // Reporter configuration
  reporter: [
    ['html', { outputFolder: './playwright-output/report' }],
    ['list']
  ],

  use: {
    // Base URL for the application
    baseURL: 'http://localhost:5173',

    // Screenshot on failure
    screenshot: 'only-on-failure',

    // Trace on failure for debugging
    trace: 'on-first-retry',

    // Video on failure
    video: 'on-first-retry',
  },

  // Test directory
  testDir: './tests',

  // Retry failed tests once
  retries: 1,

  // Run tests in parallel
  fullyParallel: true,

  // Timeout for form operations (60 seconds)
  timeout: 60000,
});
