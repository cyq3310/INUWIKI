import { defineStore } from 'pinia'
import { categoryApi, portalApi, spaceApi } from '../api'
import { categoryPath, findCategory } from '../utils/tree'
import { readCurrentSpace, writeCurrentSpace } from '../utils/space-selection'
import type { ArticleItem, CategoryNode, SpaceItem } from '../types'

/**
 * 知识库公共数据：空间、分类树、门户搜索词。
 * 门户与后台共用同一份缓存，避免重复请求；切换空间时按需拉取分类树。
 */
export const useWikiStore = defineStore('wiki', {
  state: () => ({
    /** 门户可见空间（GET /kbapi/spaces） */
    spaces: [] as SpaceItem[],
    /** 后台可管理空间（GET /kbapi/admin/spaces） */
    adminSpaces: [] as SpaceItem[],
    /** 门户分类树缓存：spaceId → tree */
    categories: {} as Record<number, CategoryNode[]>,
    /** 后台分类树缓存：spaceId → tree */
    adminCategories: {} as Record<number, CategoryNode[]>,
    /** 顶栏搜索词（门户顶栏 → 列表页） */
    keyword: '',
    /** 当前空间：门户与后台共用，从本地记忆恢复；'' 表示「全部空间」 */
    portalSpaceId: readCurrentSpace(),
    adminSpaceId: readCurrentSpace(),
    /** 当前空间名称快照：列表接口暂不可用时不至于显示「当前空间」占位 */
    currentSpaceName: '',
    /**
     * 编辑页的文章快照：后端 route/app.php 没有 GET /kbapi/admin/articles/:id，
     * 从后台列表点「编辑」时把整行带过来，避免编辑草稿时无数据来源。
     */
    editRow: null as ArticleItem | null,
  }),
  actions: {
    async loadSpaces(force = false) {
      if (this.spaces.length && !force) return this.spaces
      const res = await portalApi.spaces()
      this.spaces = res.data.spaces ?? []
      this.restoreCurrentSpaceName()
      return this.spaces
    },
    /**
     * /admin/spaces 需要 kb:space:manage，普通创建者会 403；
     * 门户 /spaces 只需登录，用它兜底，保证空间名与空间选择始终可用。
     * 探测请求静默：403 是预期降级，全局拦截器不应弹「无权限执行该操作」。
     */
    async loadAdminSpaces(force = false) {
      if (this.adminSpaces.length && !force) return this.adminSpaces
      try {
        const res = await spaceApi.list(true)
        this.adminSpaces = res.data.spaces ?? []
      } catch {
        const res = await portalApi.spaces()
        this.adminSpaces = res.data.spaces ?? []
      }
      this.restoreCurrentSpaceName()
      return this.adminSpaces
    },
    async loadCategories(spaceId: number, force = false): Promise<CategoryNode[]> {
      if (!spaceId) return []
      if (this.categories[spaceId] && !force) return this.categories[spaceId]
      const res = await portalApi.categories(spaceId)
      this.categories[spaceId] = res.data.categories ?? []
      return this.categories[spaceId]
    },
    /** /admin/categories 需要 kb:category:manage，403 时静默退回门户目录树（口径为已发布文章） */
    async loadAdminCategories(spaceId: number, force = false): Promise<CategoryNode[]> {
      if (!spaceId) return []
      if (this.adminCategories[spaceId] && !force) return this.adminCategories[spaceId]
      try {
        const res = await categoryApi.list(spaceId, true)
        this.adminCategories[spaceId] = res.data.categories ?? []
      } catch {
        const res = await portalApi.categories(spaceId)
        this.adminCategories[spaceId] = res.data.categories ?? []
      }
      return this.adminCategories[spaceId]
    },
    spaceName(spaceId: number | string): string {
      const target = Number(spaceId)
      const hit =
        this.spaces.find((s) => Number(s.id) === target) ||
        this.adminSpaces.find((s) => Number(s.id) === target)
      return hit?.name || ''
    },
    categoryName(spaceId: number | string, categoryId: number | string): string {
      const tree = this.categories[Number(spaceId)] ?? this.adminCategories[Number(spaceId)] ?? []
      const node = findCategory(tree, Number(categoryId))
      return node?.name || ''
    },
    categoryFullPath(spaceId: number | string, categoryId: number | string): string {
      const tree = this.categories[Number(spaceId)] ?? this.adminCategories[Number(spaceId)] ?? []
      return categoryPath(tree, Number(categoryId))
    },
    setKeyword(value: string) {
      this.keyword = value
    },
    /** 刷新后本地只存了 id，列表加载完补回名称（不改写当前空间） */
    restoreCurrentSpaceName() {
      if (this.portalSpaceId !== '' && !this.currentSpaceName) {
        this.currentSpaceName = this.spaceName(this.portalSpaceId)
      }
    },
    /** 门户与后台共用同一个「当前空间」，避免跨端切换后各显示一个空间 */
    setPortalSpaceId(value: number | '', name?: string) {
      this.setCurrentSpaceId(value, name)
    },
    setAdminSpaceId(value: number | '', name?: string) {
      this.setCurrentSpaceId(value, name)
    },
    /**
     * 只由用户主动切换 / 创建页 URL 写入。加载空间列表绝不改写这里，
     * 否则两份列表口径不同就会把「上次打开的空间」清成全部空间。
     */
    setCurrentSpaceId(value: number | '', name?: string) {
      const id = value === '' ? '' : Number(value)
      this.portalSpaceId = id
      this.adminSpaceId = id
      this.currentSpaceName = id === '' ? '' : name || this.spaceName(id) || this.currentSpaceName
      writeCurrentSpace(id)
    },
    /** 目录变更后让门户 / 后台分类树缓存失效，避免侧栏仍显示旧结构 */
    invalidateCategories(spaceId: number) {
      delete this.categories[spaceId]
      delete this.adminCategories[spaceId]
    },
    setEditRow(row: ArticleItem | null) {
      this.editRow = row ? { ...row } : null
    },
  },
})
