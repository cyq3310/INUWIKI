import { pinyin } from 'pinyin-pro'

/**
 * 空间表单规则（门户 / 后台一致）：
 * - 名称：按显示宽度折算，1 个汉字 = 1.5、其余字符 = 1，总上限 12。
 *   即最多 8 个汉字或 12 个英文字符，中英混排按比例折算。
 * - 编码：最长 16 字符，统一 -project 后缀；超长时裁剪前缀、优先保留后缀。
 *   后端约束 alphaDash|max:16，另有唯一索引兜底。
 */
export const SPACE_NAME_MAX_UNITS = 12
export const MAX_CODE_LENGTH = 16
export const CODE_SUFFIX = '-project'

/** 首字母最多 8 个：8 + '-project'(8) = 16，正好用满上限；
 *  14 字符的 yyhdsc-project 等合法编码不会被误裁 */
const MAX_INITIALS = MAX_CODE_LENGTH - CODE_SUFFIX.length

/** 末尾的「项目 / Project」视为后缀，不再计入首字母 */
const PROJECT_SUFFIX = /[\s_-]*(?:项目|project)$/i
const HAN = /^[\u3400-\u9fff]+$/
/** 汉字与全角标点按 1.5 个英文字符宽计 */
const WIDE_CHAR = /[\u3400-\u9fff\u3000-\u303f\uff00-\uffef]/

/** 名称已占用的宽度额度 */
export function spaceNameUnits(value: string): number {
  let units = 0
  for (const ch of value.trim()) {
    units += WIDE_CHAR.test(ch) ? 1.5 : 1
  }
  return units
}

/** 按额度裁剪名称：原生 maxlength 会把汉字也当 1 个，无法表达「8 汉字 / 12 英文」 */
export function limitSpaceName(value: string): string {
  let units = 0
  let out = ''
  for (const ch of value) {
    const next = units + (WIDE_CHAR.test(ch) ? 1.5 : 1)
    if (next > SPACE_NAME_MAX_UNITS) break
    units = next
    out += ch
  }
  return out
}

/**
 * 把任意编码规范到 16 字符内：优先保留 -project 后缀，只裁剪前缀。
 * 例：abcdefghijklmnop-project → abcdefgh-project
 */
export function clampSpaceCode(code: string): string {
  const cleaned = code.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '')
  if (cleaned.length <= MAX_CODE_LENGTH) return cleaned
  const suffix = cleaned.endsWith(CODE_SUFFIX) ? CODE_SUFFIX : ''
  const budget = Math.max(0, MAX_CODE_LENGTH - suffix.length)
  return cleaned.slice(0, budget) + suffix
}

/**
 * 空间名称 → 空间编码：汉字取拼音首字母，英文单词取首字母，统一后缀 -project。
 * 例如「荣耀仙灵项目」→ ryxl-project；「Glory Fairy」→ gf-project。
 * 无法取到字母数字时返回空串，交由用户手填。
 */
export function spaceCodeFromName(name: string): string {
  const trimmed = name.trim()
  const body = trimmed.replace(PROJECT_SUFFIX, '').trim() || trimmed
  const tokens = body.match(/[\u3400-\u9fff]+|[a-zA-Z0-9]+/g) ?? []
  let initials = ''
  for (const token of tokens) {
    if (HAN.test(token)) {
      const letters = pinyin(token, { pattern: 'first', type: 'array', toneType: 'none' })
      initials += letters.filter((letter) => /^[a-z]$/i.test(letter)).join('').toLowerCase()
    } else {
      // 英文单词取首字母，纯数字段取首位数字
      initials += token[0].toLowerCase()
    }
  }
  initials = initials.replace(/[^a-z0-9]/g, '').slice(0, MAX_INITIALS)
  return initials ? `${initials}${CODE_SUFFIX}` : ''
}

/** 与已有空间编码避重：把 -2、-3 插在前缀里，始终保留 -project 后缀且不超 16 字符 */
export function uniqueSpaceCode(base: string, existingCodes: string[]): string {
  const used = new Set(existingCodes.map((code) => code.toLowerCase()))
  const normalized = clampSpaceCode(base)
  if (!used.has(normalized.toLowerCase())) return normalized
  const withSuffix = normalized.endsWith(CODE_SUFFIX)
  const prefix = withSuffix ? normalized.slice(0, -CODE_SUFFIX.length) : normalized
  const suffix = withSuffix ? CODE_SUFFIX : ''
  for (let i = 2; i < 1000; i += 1) {
    const tail = `-${i}`
    const candidate = `${prefix.slice(0, MAX_CODE_LENGTH - suffix.length - tail.length)}${tail}${suffix}`
    if (!used.has(candidate.toLowerCase())) return candidate
  }
  return normalized
}
