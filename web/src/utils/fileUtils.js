/**
 * Extract a human-readable filename from an R2 URL or storage key.
 * R2 keys follow the pattern: {folder}/{safeName}_{16hexchars}.{ext}
 * This strips the folder and the unique-ID suffix.
 *
 * Examples:
 *   "https://cdn.example.com/employee-photos/jan_novak_abc123def456gh78.jpg" → "jan_novak.jpg"
 *   "employee-photos/report_a1b2c3d4e5f6g7h8.pdf" → "report.pdf"
 *   "my_file.jpg" → "my_file.jpg"  (no unique suffix detected)
 *   null → ""
 */
export function extractFilename(urlOrKey) {
  if (!urlOrKey) return ''

  // Get the last path segment (handles full URLs and keys)
  let filename
  try {
    const url = new URL(urlOrKey)
    const segments = url.pathname.split('/').filter(Boolean)
    filename = segments[segments.length - 1] || ''
  } catch {
    // Not a valid URL — treat as a key or plain filename
    const segments = urlOrKey.split('/').filter(Boolean)
    filename = segments[segments.length - 1] || urlOrKey
  }

  // Decode URI-encoded characters
  filename = decodeURIComponent(filename)

  // Strip the _{16hexchars} unique-ID suffix before the extension
  // Pattern: name_[0-9a-f]{16}.ext → name.ext
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

  // If it looks like a MIME type
  if (urlOrMime.includes('/') && !urlOrMime.includes('.')) {
    const sub = urlOrMime.split('/')[1] || ''
    return sub.toLowerCase()
  }

  // Extract extension from URL or filename
  try {
    const url = new URL(urlOrMime)
    const path = url.pathname
    const ext = path.split('.').pop()
    return (ext || '').toLowerCase()
  } catch {
    const ext = urlOrMime.split('.').pop()
    return (ext || '').toLowerCase()
  }
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
