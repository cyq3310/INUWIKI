/**
 * 后端 think-orm 把 biginteger 主键经 readTransform 读成字符串（实测 kb_space.id、
 * kb_category.id / parent_id、kb_article.space_id 都是 "2"），而前端类型声明、
 * `===` 比较与 Map<number,…> 全部按 number 写。统一在数据入口归一化，
 * 避免每个消费点各自踩坑。只转「纯数字字符串」的白名单字段，不动 code / 名称 / 正文。
 */
export const ID_FIELDS = new Set([
  'id', 'parent_id', 'space_id', 'category_id', 'article_id',
  'author_id', 'created_by', 'user_id', 'attachment_id',
])

export function normalizeIds(value: unknown): void {
  if (!value || typeof value !== 'object') return
  if (Array.isArray(value)) {
    for (const item of value) normalizeIds(item)
    return
  }
  const record = value as Record<string, unknown>
  for (const [key, child] of Object.entries(record)) {
    if (ID_FIELDS.has(key) && typeof child === 'string' && /^\d+$/.test(child)) {
      const numeric = Number(child)
      if (Number.isSafeInteger(numeric)) record[key] = numeric
    } else if (child && typeof child === 'object') {
      normalizeIds(child)
    }
  }
}
