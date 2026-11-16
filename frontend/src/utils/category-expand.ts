import type { CategoryNode } from '../types'
import { flattenCategories } from './tree'

/**
 * 「知识目录展开层级」持久化：按空间记住用户手动展开过的目录，
 * 重新打开网页 / 返回主页时只恢复这些层级，其他目录保持收起。
 * 无缓存时返回空数组 —— 侧栏只显示 L1 目录。
 */
const STORAGE_KEY = 'inuwiki.categoryExpanded'
const MAX_SAVED = 200

type ExpandedMap = Record<string, number[] | undefined>

function readAll(): ExpandedMap {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (!raw) return {}
    const parsed: unknown = JSON.parse(raw)
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {}
    return parsed as ExpandedMap
  } catch {
    return {}
  }
}

function cleanIds(ids: unknown): number[] {
  if (!Array.isArray(ids)) return []
  const out: number[] = []
  const seen = new Set<number>()
  for (const raw of ids) {
    const id = Number(raw)
    if (!Number.isInteger(id) || id <= 0 || seen.has(id)) continue
    seen.add(id)
    out.push(id)
  }
  return out
}

export function readExpandedCategories(spaceId: number | ''): number[] {
  if (spaceId === '') return []
  return cleanIds(readAll()[String(Number(spaceId))])
}

export function writeExpandedCategories(spaceId: number | '', ids: number[]): void {
  if (spaceId === '') return
  try {
    const all = readAll()
    const saved = cleanIds(ids).slice(0, MAX_SAVED)
    const key = String(Number(spaceId))
    if (saved.length) all[key] = saved
    else delete all[key]
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(all))
  } catch {
    // 存储不可用不影响本次会话
  }
}

/**
 * 丢弃已删除的目录，并补齐每个展开节点的祖先。
 * 例如缓存里只有 L3 节点时，L1 / L2 也必须展开，否则深层节点根本渲染不出来。
 */
export function normalizeExpanded(tree: CategoryNode[], ids: number[]): number[] {
  const flat = flattenCategories(tree)
  const parents = new Map<number, number>()
  for (const item of flat) parents.set(Number(item.id), Number(item.parent_id))
  const kept = new Set<number>()
  for (const raw of ids) {
    const id = Number(raw)
    if (!parents.has(id)) continue
    kept.add(id)
    const seen = new Set<number>([id])
    let cursor = parents.get(id) ?? 0
    while (cursor && parents.has(cursor) && !seen.has(cursor)) {
      seen.add(cursor)
      kept.add(cursor)
      cursor = parents.get(cursor) ?? 0
    }
  }
  return flat.filter((item) => kept.has(Number(item.id))).map((item) => Number(item.id))
}

/** 收起父目录时，连同其所有后代一起收起，避免残留的子节点把父节点又「顶」回展开态 */
export function collapseBranch(tree: CategoryNode[], ids: number[], id: number): number[] {
  const flat = flattenCategories(tree)
  const branch = new Set<number>([Number(id)])
  let changed = true
  while (changed) {
    changed = false
    for (const item of flat) {
      const child = Number(item.id)
      if (branch.has(Number(item.parent_id)) && !branch.has(child)) {
        branch.add(child)
        changed = true
      }
    }
  }
  return ids.map(Number).filter((value) => !branch.has(value))
}

/** 展开 / 收起一个目录，返回可直接持久化的新集合 */
export function applyExpandToggle(tree: CategoryNode[], ids: number[], id: number): number[] {
  const target = Number(id)
  if (ids.some((value) => Number(value) === target)) return collapseBranch(tree, ids, target)
  return normalizeExpanded(tree, [...ids, target])
}
