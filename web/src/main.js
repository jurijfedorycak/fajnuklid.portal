import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import router from './router'
import { useAuth } from './stores/auth'
import { Capacitor } from '@capacitor/core'
import { StatusBar, Style } from '@capacitor/status-bar'
import { SplashScreen } from '@capacitor/splash-screen'
import { App as CapacitorApp } from '@capacitor/app'

async function initNative() {
  if (!Capacitor.isNativePlatform()) return

  // Re-apply at runtime in addition to the config-level defaults — the
  // StatusBar plugin reads config on launch, but the JS API is the source
  // of truth if anything ever dynamically tints the bar.
  try {
    await StatusBar.setStyle({ style: Style.Dark })
    await StatusBar.setBackgroundColor({ color: '#162438' })
  } catch (err) {
    console.warn('[StatusBar] init failed:', err)
  }

  // Without this, Android's hardware back always exits the app. The plugin
  // emits canGoBack=false on the root route; honour it to avoid a no-op back.
  CapacitorApp.addListener('backButton', ({ canGoBack }) => {
    if (!canGoBack || router.currentRoute.value.name === 'Login') {
      CapacitorApp.exitApp()
      return
    }
    router.back()
  })

  // launchAutoHide is false in capacitor.config.ts, so the splash stays until
  // this call fires — guaranteeing Vue is mounted and the first paint is real.
  try {
    await SplashScreen.hide()
  } catch (err) {
    console.warn('[SplashScreen] hide failed:', err)
  }
}

// Cap how long the launch waits for the optimistic /auth/me revalidation.
// On a hung network we'd otherwise sit on the splash forever (launchAutoHide
// is off so the native shell can't bail us out). The race timer doesn't abort
// the axios call — it just stops blocking the mount; checkAuth's catch path
// in stores/auth.js will still tidy up if the response eventually 401s.
const CHECK_AUTH_MAX_MS = 5000

async function bootstrap() {
  const { isAuthenticated, checkAuth } = useAuth()
  if (isAuthenticated.value) {
    await Promise.race([
      checkAuth(),
      new Promise((resolve) => setTimeout(resolve, CHECK_AUTH_MAX_MS)),
    ])
  }
  try {
    createApp(App).use(router).mount('#app')
  } finally {
    // initNative() runs even if mount throws so the splash can't strand the
    // user on a frozen launch screen — they at least see the underlying error.
    await initNative()
  }
}

bootstrap()
