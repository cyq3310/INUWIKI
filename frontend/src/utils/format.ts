/** 字节数格式化 */
export function formatBytes(bytes: number): string {
  const size = Number(bytes) || 0
  if (size < 1024) return `${size} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = size / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit += 1
  }
  return `${value < 10 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`
}

/** 秒 → mm:ss / hh:mm:ss */
export function formatDuration(seconds: number): string {
  const total = Math.max(0, Math.floor(Number(seconds) || 0))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const pad = (n: number) => String(n).padStart(2, '0')
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}

/** 计数格式化：1.2k / 1.2w */
export function formatCount(value: number): string {
  const n = Number(value) || 0
  if (n < 1000) return String(n)
  if (n < 10000) return `${(n / 1000).toFixed(1).replace(/\.0$/, '')}k`
  return `${(n / 10000).toFixed(1).replace(/\.0$/, '')}w`
}

/** 补零 */
const pad = (n: number) => String(n).padStart(2, '0')

/** YYYY-MM-DD HH:mm */
export function formatDateTime(input?: string | null): string {
  if (!input) return '—'
  const date = new Date(input.replace(/-/g, '/'))
  if (Number.isNaN(date.getTime())) return String(input)
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** MM-DD HH:mm（后台表格用） */
export function formatShortDateTime(input?: string | null): string {
  if (!input) return '—'
  const date = new Date(input.replace(/-/g, '/'))
  if (Number.isNaN(date.getTime())) return String(input)
  return `${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** 相对时间：x 分钟前 / x 天前 */
export function formatRelativeTime(input?: string | null): string {
  if (!input) return '—'
  const date = new Date(input.replace(/-/g, '/'))
  if (Number.isNaN(date.getTime())) return String(input)
  const diff = Date.now() - date.getTime()
  if (diff < 0) return formatDateTime(input)
  const minutes = Math.floor(diff / 60000)
  if (minutes < 1) return '刚刚'
  if (minutes < 60) return `${minutes} 分钟前`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours} 小时前`
  const days = Math.floor(hours / 24)
  if (days < 30) return `${days} 天前`
  const months = Math.floor(days / 30)
  if (months < 12) return `${months} 个月前`
  return `${Math.floor(months / 12)} 年前`
}

/** 文件名扩展名（小写，不含点） */
export function fileExt(name: string): string {
  const idx = String(name).lastIndexOf('.')
  return idx > -1 ? String(name).slice(idx + 1).toLowerCase() : ''
}
