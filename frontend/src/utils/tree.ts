import type { CategoryNode } from '../types'

/** 知识目录最多 5 层（L1 ~ L5），前后端与预览页共用同一口径 */
export const CATEGORY_MAX_LEVEL = 5

/** 目录名称最多 15 个字，前后端与预览页共用同一口径 */
export const CATEGORY_NAME_MAX_LENGTH = 15

/** 每个空间默认存在、永远置底、不可变更的系统目录名 */
export const SYSTEM_CATEGORY_NAME = '未指定目录'

/**
 * 以服务端的 is_system 标记为准；字段缺失的老数据才用名称兜底，
 * 避免历史上用户自建的同名普通目录被误当作系统目录。
 */
export function isSystemCategory(node: { is_system?: number; name?: string }): boolean {
  if (node.is_system !== undefined) return Number(node.is_system) === 1
  return node.name === SYSTEM_CATEGORY_NAME
}

/** 递归把「未指定目录」排到同级末尾，保证任何列表里都永远置底 */
export function sortSystemLast(nodes: CategoryNode[]): CategoryNode[] {
  const sorted = [...(nodes ?? [])].sort(
    (a, b) => Number(isSystemCategory(a)) - Number(isSystemCategory(b)),
  )
  return sorted.map((node) =>
    node.children?.length ? { ...node, children: sortSystemLast(node.children) } : node,
  )
}

/** 当前空间的「未指定目录」ID；用于把 category_id=0 的存量文章归一化归属 */
export function systemCategoryId(nodes: CategoryNode[]): number {
  for (const node of nodes ?? []) {
    // 只认服务端标记，名称兜底仅用于 UI 展示
    if (Number(node.is_system ?? 0) === 1) return Number(node.id)
    const found = node.children?.length ? systemCategoryId(node.children) : 0
    if (found) return found
  }
  return 0
}

/** depth 从 0 开始；父目录层级为 depth + 1，其下新建目录为 depth + 2 */
export function canAddChildAt(depth: number): boolean {
  return depth + 2 <= CATEGORY_MAX_LEVEL
}

export interface FlatCategory {
  id: number
  name: string
  parent_id: number
  /** 层级深度，0 为一级 */
  depth: number
  article_count?: number
  sort?: number
  is_system?: number
}

/** 分类树摊平（保留层级，供下拉与面包屑使用） */
export function flattenCategories(tree: CategoryNode[], depth = 0): FlatCategory[] {
  const out: FlatCategory[] = []
  for (const node of tree ?? []) {
    // 兼容后端 biginteger 主键读成字符串的情况，摊平后统一为 number
    out.push({
      id: Number(node.id),
      name: node.name,
      parent_id: Number(node.parent_id ?? 0),
      depth,
      article_count: Number(node.article_count ?? 0) || 0,
      sort: Number(node.sort ?? 0) || 0,
      is_system: Number(node.is_system ?? 0) || 0,
    })
    if (node.children?.length) {
      out.push(...flattenCategories(node.children, depth + 1))
    }
  }
  return out
}

/** 在分类树中查找节点（含子节点） */
export function findCategory(tree: CategoryNode[], id: number | string): CategoryNode | null {
  const target = Number(id)
  for (const node of tree ?? []) {
    if (Number(node.id) === target) return node
    const hit = findCategory(node.children ?? [], target)
    if (hit) return hit
  }
  return null
}

/** 分类全路径：新人入职 / 入职流程 */
export function categoryPath(tree: CategoryNode[], id: number): string {
  const node = findCategory(tree, id)
  if (!node) return ''
  const parent = node.parent_id ? findCategory(tree, node.parent_id) : null
  return parent ? `${parent.name} / ${node.name}` : node.name
}

/**
 * 从一级目录到目标目录的完整链路（不限层级），供面包屑与「第几级」计算使用。
 * 用 parent_id 反查而非递归下钻，父子数据不一致时也能安全退出（seen 防环）。
 */
export function categoryTrail(tree: CategoryNode[], id: number | string | ''): FlatCategory[] {
  if (id === '' || id === null || id === undefined) return []
  const target = Number(id)
  if (!Number.isFinite(target) || target <= 0) return []
  const map = new Map<number, FlatCategory>()
  for (const item of flattenCategories(tree)) map.set(item.id, item)
  const trail: FlatCategory[] = []
  const seen = new Set<number>()
  let cursor = map.get(target)
  while (cursor && !seen.has(cursor.id)) {
    seen.add(cursor.id)
    trail.unshift(cursor)
    cursor = cursor.parent_id ? map.get(cursor.parent_id) : undefined
  }
  return trail
}

/**
 * 目录选择器的显示链路：树命中优先；树异步加载 / 接口降级为空时，
 * 用「点击当时记录的链路」兜底，并校验末项 ID 与当前选中值一致，避免张冠李戴。
 */
export function resolveCategoryTrail(
  tree: CategoryNode[],
  id: number | string | '',
  cached: FlatCategory[] | null = null,
): FlatCategory[] {
  const fromTree = categoryTrail(tree, id)
  if (fromTree.length) return fromTree
  if (id === '' && !cached?.length) return []
  if (cached?.length && Number(cached[cached.length - 1].id) === Number(id)) return cached
  return []
}
