/**
 * Pull the R2 storage key out of a value that might be a key, a legacy R2 URL, or
 * one of our stable proxy URLs (/storage/file?key=...&sig=...). Returns the original
 * input untouched when it's neither a parseable URL nor empty.
 */
function storageKeyFromAny(urlOrKey) {
  if (!urlOrKey) return ''
  try {
    const url = new URL(urlOrKey, 'http://localhost')
    const keyParam = url.searchParams.get('key')
    if (keyParam) return keyParam
    return url.pathname.replace(/^\/+/, '')
  } catch {
    return urlOrKey
  }
}

/**
 * Extract a human-readable filename from an R2 URL, proxy URL, or storage key.
 * R2 keys follow the pattern: {folder}/{safeName}_{16hexchars}.{ext}
 * This strips the folder and the unique-ID suffix.
 */
export function extractFilename(urlOrKey) {
  if (!urlOrKey) return ''

  const key = storageKeyFromAny(urlOrKey)
  const segments = key.split('/').filter(Boolean)
  let filename = segments[segments.length - 1] || urlOrKey

  filename = decodeURIComponent(filename)

  // Strip the _{16hexchars} unique-ID suffix before the extension
  const match = filename.match(/^(.+)_[0-9a-f]{16}(\.\w+)$/i)
  if (match) {
    return match[1] + match[2]
  }

  return filename
}

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'heic', 'heif', 'bmp', 'avif']
const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogg', 'mov', 'avi']
const PDF_EXTENSIONS = ['pdf']

function getExtension(urlOrMime) {
  if (!urlOrMime) return ''

  // MIME-type shape: "image/jpeg" → "jpeg"
  if (urlOrMime.includes('/') && !urlOrMime.includes('.')) {
    const sub = urlOrMime.split('/')[1] || ''
    return sub.toLowerCase()
  }

  // Resolve to the underlying storage key so proxy URLs (/storage/file?key=...)
  // still yield the right extension.
  const filename = extractFilename(urlOrMime)
  const ext = filename.split('.').pop()
  return (ext || '').toLowerCase()
}

export function isImageUrl(urlOrMime) {
  if (!urlOrMime) return false
  if (typeof urlOrMime === 'string' && urlOrMime.startsWith('image/')) return true
  const ext = getExtension(urlOrMime)
  return IMAGE_EXTENSIONS.includes(ext)
}

export function isVideoUrl(urlOrMime) {
  if (!urlOrMime) return false
  if (typeof urlOrMime === 'string' && urlOrMime.startsWith('video/')) return true
  const ext = getExtension(urlOrMime)
  return VIDEO_EXTENSIONS.includes(ext)
}

export function isPdfUrl(urlOrMime) {
  if (!urlOrMime) return false
  if (urlOrMime === 'application/pdf') return true
  const ext = getExtension(urlOrMime)
  return PDF_EXTENSIONS.includes(ext)
}

/**
 * Trigger a file download in the browser.
 */
export async function downloadFile(url, filename) {
  try {
    const response = await fetch(url)
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    const blob = await response.blob()
    const objectUrl = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = objectUrl
    link.download = filename || extractFilename(url) || 'download'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(objectUrl)
  } catch {
    window.open(url, '_blank')
  }
}
