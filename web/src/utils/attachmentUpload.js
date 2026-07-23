// Uploads run one-by-one after the request row exists; a failed file must not block
// the rest nor the navigation to the created request, so failures are only logged.
export async function uploadAttachmentsSequentially(files, uploadFn) {
  for (const f of files) {
    try {
      await uploadFn(f)
    } catch (e) {
      console.error('Attachment upload failed', e)
    }
  }
}
