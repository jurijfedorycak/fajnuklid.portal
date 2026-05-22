import { Capacitor } from '@capacitor/core'
import { Browser } from '@capacitor/browser'

/**
 * Open an external URL. In a browser this delegates to window.open so the
 * familiar "new tab" UX (including modifier-click) is preserved. In a Capacitor
 * native app it routes through the in-app browser (SFSafariViewController on
 * iOS, Chrome Custom Tabs on Android) which gives the user a Done button to
 * return to the app and satisfies Apple App Review Guideline 4.2.
 *
 * Do not use for in-app routes — push them via the router. Do not use for
 * mailto:/tel: schemes — leave those as plain anchors; the OS handles them.
 */
export async function openExternal(url) {
  if (!url) return
  if (Capacitor.isNativePlatform()) {
    try {
      await Browser.open({ url })
    } catch (err) {
      console.warn('[Browser] open failed:', err)
    }
    return
  }
  window.open(url, '_blank', 'noopener,noreferrer')
}

/**
 * Click handler for `<a target="_blank">` anchors that should also work in a
 * Capacitor app. On web the browser handles target="_blank" natively (with
 * modifier-click support); on native we preventDefault and route through the
 * in-app browser.
 */
export function handleExternalClick(event, url) {
  if (!Capacitor.isNativePlatform()) return
  event.preventDefault()
  openExternal(url)
}
