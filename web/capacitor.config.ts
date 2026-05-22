import type { CapacitorConfig } from '@capacitor/cli'

// Native shell for the Fajn Úklid client portal. Wraps the existing Vite-built
// SPA under dist/. iosScheme is forced to 'https' so both platforms share a
// single WebView origin (https://localhost) — that keeps the backend CORS
// allowlist to one entry and keeps axios baseURL behaviour identical across
// iOS and Android.
const config: CapacitorConfig = {
  appId: 'cz.fajnuklid.portal',
  appName: 'Fajn Úklid Portal',
  webDir: 'dist',
  server: {
    iosScheme: 'https',
    androidScheme: 'https',
  },
  ios: {
    contentInset: 'always',
  },
  android: {
    allowMixedContent: false,
  },
  plugins: {
    SplashScreen: {
      // launchAutoHide:false keeps the splash visible until main.js explicitly
      // calls SplashScreen.hide() after Vue mounts. Auto-hide can fire before
      // checkAuth() resolves on slow networks, briefly exposing a blank screen.
      launchAutoHide: false,
      backgroundColor: '#162438',
      showSpinner: false,
    },
    StatusBar: {
      style: 'DARK',
      backgroundColor: '#162438',
      overlaysWebView: false,
    },
  },
}

export default config
