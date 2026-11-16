/** 卡片标题色带：8 种预设色块（不含黑色），对应 design.css 的 .g1 ~ .g8 */
export const CARD_COLORS = ['g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8'] as const

export type CardColor = (typeof CARD_COLORS)[number]

export function isCardColor(value: unknown): value is CardColor {
  return typeof value === 'string' && (CARD_COLORS as readonly string[]).includes(value)
}

/** 历史文章未设置色带颜色时，按 ID 稳定分配一种色块 */
export function cardColorOf(value: unknown, id: number): CardColor {
  if (isCardColor(value)) return value
  return CARD_COLORS[Math.abs(Number(id) || 0) % CARD_COLORS.length]
}
