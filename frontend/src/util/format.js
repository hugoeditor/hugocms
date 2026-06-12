export function formatSize(bytes) {
  if (!bytes) return '–'
  const units = ['B', 'KB', 'MB', 'GB']
  let value = bytes
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`
}

import { i18n } from '../i18n'

export function formatDate(epochSeconds) {
  if (!epochSeconds) return '–'
  return new Date(epochSeconds * 1000).toLocaleString(i18n.global.locale.value, {
    dateStyle: 'short',
    timeStyle: 'short',
  })
}

export function iconFor(entry) {
  if (entry.type === 'dir') return 'mdi-folder'
  if (entry.image) return 'mdi-file-image'
  if (entry.editable) return 'mdi-file-document-edit'
  return 'mdi-file'
}
