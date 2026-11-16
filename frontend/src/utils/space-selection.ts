/**
 * 「当前空间」持久化：门户与后台共用同一个选择，打开 / 刷新 / 创建文章返回都回到它。
 * 无缓存（或缓存失效）时返回 '' —— 顶栏的「全部空间」选项。
 */
const STORAGE_KEY = 'inuwiki.currentSpace'

export function readCurrentSpace(): number | '' {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (raw === null || raw === '' || raw === 'all') return ''
    const id = Number(raw)
    return Number.isInteger(id) && id > 0 ? id : ''
  } catch {
    // 隐私模式 / 禁用存储：按无缓存处理
    return ''
  }
}

export function writeCurrentSpace(value: number | ''): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, value === '' ? 'all' : String(value))
  } catch {
    // 存储不可用不影响本次会话
  }
}
