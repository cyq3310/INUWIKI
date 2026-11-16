import type { SpaceItem } from '../types'

export type SpaceOption = Pick<SpaceItem, 'id' | 'name'>

/**
 * 新建文章的默认空间：URL 上的 space_id 是唯一权威来源。
 * 旧实现命中不到就回退 adminSpaces[0]，于是从 A 空间点「新增文章」会落到列表第一个空间。
 * 两套空间缓存（后台可管理 / 门户可见）都参与匹配，任一命中即采用；
 * 即便都未命中也保留 URL 指定的 ID，绝不静默换成别的空间。
 */
export function resolveCreateSpaceId(
  querySpaceId: number,
  adminSpaces: SpaceOption[],
  portalSpaces: SpaceOption[],
  currentSpaceId: number | '' = '',
): number | '' {
  if (Number.isInteger(querySpaceId) && querySpaceId > 0) {
    const hit = [...adminSpaces, ...portalSpaces].find((space) => Number(space.id) === querySpaceId)
    return hit ? Number(hit.id) : querySpaceId
  }
  // 第二通道：URL 参数在跳转中丢失时，仍以顶栏选中的当前空间为准
  if (currentSpaceId) return currentSpaceId
  // 无记忆（全部空间）时不预选任何空间，避免默默落到列表第一个空间
  return ''
}

/** 合并两套空间列表，保证「所属空间」下拉能显示 URL 指定空间的名称 */
export function mergeSpaceOptions(adminSpaces: SpaceOption[], portalSpaces: SpaceOption[]): SpaceOption[] {
  const map = new Map<number, SpaceOption>()
  const put = (space: SpaceOption) => {
    const id = Number(space.id)
    if (!map.has(id)) map.set(id, { id, name: space.name })
  }
  for (const space of adminSpaces) put(space)
  for (const space of portalSpaces) put(space)
  return [...map.values()]
}
