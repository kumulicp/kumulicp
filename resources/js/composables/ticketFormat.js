// Small formatting helpers shared by the support desk screens.

const dateTime = { dateStyle: 'medium', timeStyle: 'short' }

export function formatDate (value) {
  if (!value) return ''
  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString(undefined, dateTime)
}

export function formatSize (bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
