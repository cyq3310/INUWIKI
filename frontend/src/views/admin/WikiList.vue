<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Search } from 'lucide-vue-next'
import KbNavTree from '../../components/KbNavTree.vue'
import { articleApi, directoryApi, docTypeApi, kbStatsApi } from '../../api'
import { useWikiStore } from '../../stores/wiki'
import { useUserStore } from '../../stores/user'
import { extractMediaRefs } from '../../utils/content'
import { formatBytes, formatCount, formatShortDateTime } from '../../utils/format'
import { fetchAllSpaceArticles } from '../../utils/space-nav'
import { cardColorOf } from '../../utils/card'
import { useSidebarWidth } from '../../composables/useSidebarWidth'
import type { ArticleItem, KbStats } from '../../types'

/**
 * 后台 · 知识管理列表
 * 左侧：空间列表（含受限标记）+ 目录树；右侧：文章表格（色块/标题/文档类型/状态/可见范围/视频/浏览/更新时间/操作）
 * 页脚：存储容量条 + 转码队列状态（统计接口失败时降级显示占位）。
 */


const STATUS_META: Record<string, { label: string; pill: string }> = {
  draft: { label: '草稿', pill: 'gray' },
  published: { label: '已发布', pill: 'green' },
  offline: { label: '已下架', pill: 'amber' },
  deleted: { label: '已删除', pill: 'red' },
}
const SCOPE_META: Record<string, { label: string; pill: string }> = {
  public: { label: '公开', pill: 'blue' },
  space: { label: '空间内', pill: 'blue' },
  restricted: { label: '🔒 指定范围', pill: 'amber' },
}

const router = useRouter()
const wiki = useWikiStore()
const store = useUserStore()
const { sidebarWidth, minWidth, defaultWidth, maxWidth, startResize, onResizeKeydown, resetWidth } = useSidebarWidth()

/** 当前空间由顶栏下拉统一控制 */
const spaceId = computed({
  get: () => wiki.adminSpaceId,
  set: (value: number | '') => wiki.setAdminSpaceId(value),
})
const categoryId = ref<number | ''>('')
const docTypeId = ref<number | ''>('')
const status = ref('')
const keyword = ref('')
const page = ref(1)
const size = ref(10)
const total = ref(0)
const rows = ref<ArticleItem[]>([])
const loading = ref(false)
/** 文档类型下拉选项：每个空间各自一套；「全部空间」时合并并加空间名前缀 */
const docTypes = ref<{ id: number; name: string }[]>([])

/** 存储 / 转码统计（接口失败时为 null，页脚降级为占位） */
const kbStats = ref<KbStats | null>(null)

/** 侧栏目录树：当前空间的目录与全部文章（后台口径含草稿/下架） */
const navArticles = ref<ArticleItem[]>([])
const navLoading = ref(false)
let navSeq = 0

const currentCategories = computed(() =>
  spaceId.value === '' ? [] : wiki.adminCategories[Number(spaceId.value)] ?? [],
)

async function loadNavArticles(id: number | ''): Promise<void> {
  const seq = ++navSeq
  if (!id) {
    navArticles.value = []
    return
  }
  navLoading.value = true
  try {
    const rows = await fetchAllSpaceArticles(
      (page, size) =>
        articleApi.list({ space_id: Number(id), page, size }).then((res) => ({
          list: res.data.list ?? [],
          total: Number(res.data.total || 0),
        })),
      100,
    )
    if (seq === navSeq) navArticles.value = rows
  } catch {
    if (seq === navSeq) navArticles.value = []
  } finally {
    if (seq === navSeq) navLoading.value = false
  }
}

const canCreate = computed(() => store.hasPermission('kb:article:create'))
const canPurge = computed(() => store.hasPermission('kb:article:purge'))

/** 对象级：本人默认可编辑 / 删除 / 发布下架自己的文章，他人需对应权限码 */
function canEditRow(row: { author_id: number }, code: string): boolean {
  return store.hasPermission(code) || Number(row.author_id) === Number(store.profile?.id ?? 0)
}

const currentSpace = computed(() =>
  spaceId.value === '' ? null : wiki.adminSpaces.find((s) => Number(s.id) === Number(spaceId.value)) ?? null,
)

const storagePercent = computed(() => {
  const data = kbStats.value
  if (!data || !data.total_bytes) return 0
  return Math.min(100, Math.round((data.used_bytes / data.total_bytes) * 100))
})

const gradientOf = (row: ArticleItem) => cardColorOf(row.band_color, row.id)
const isVideo = (row: ArticleItem) => extractMediaRefs(row.content || '').videoIds.length > 0
const statusMeta = (row: ArticleItem) => STATUS_META[row.status] ?? { label: row.status, pill: 'gray' }
const scopeMeta = (row: ArticleItem) => SCOPE_META[row.view_scope] ?? { label: row.view_scope, pill: 'blue' }

/** 作者姓名：从 kb_subject 本地快照按 ID 解析；快照未同步到该用户时保留 #ID */
const authorNames = ref<Record<number, string>>({})
async function resolveAuthorNames(list: ArticleItem[]): Promise<void> {
  const ids = [...new Set(list.map((r) => Number(r.author_id)).filter((id) => id > 0))]
  if (!ids.length) return
  try {
    const res = await directoryApi.resolve('user', ids)
    for (const subject of res.data?.subjects ?? []) {
      const id = Number(subject.subject_id)
      if (id > 0 && subject.name) authorNames.value[id] = subject.name
    }
  } catch {
    // 目录快照不可用时降级为「作者 #ID」
  }
}
const authorLabel = (row: ArticleItem): string => {
  const id = Number(row.author_id)
  return `作者 ${authorNames.value[id] || `#${id}`}`
}

/** 文档类型下拉：当前空间用本空间的一套；「全部空间」并发汇总各空间类型 */
async function loadDocTypes(id: number | ''): Promise<void> {
  if (id !== '') {
    try {
      const res = await docTypeApi.bySpace(Number(id))
      docTypes.value = (res.data?.doc_types ?? []).map((dt) => ({ id: Number(dt.id), name: dt.name }))
    } catch {
      docTypes.value = []
    }
    return
  }
  const spaces = wiki.adminSpaces.length ? wiki.adminSpaces : wiki.spaces
  const lists = await Promise.all(
    spaces.map(async (space) => {
      try {
        const res = await docTypeApi.bySpace(Number(space.id))
        return (res.data?.doc_types ?? []).map((dt) => ({
          id: Number(dt.id),
          name: `${space.name} · ${dt.name}`,
        }))
      } catch {
        return [] as { id: number; name: string }[]
      }
    }),
  )
  docTypes.value = lists.flat()
}

async function fetchRows(): Promise<void> {
  loading.value = true
  try {
    const res = await articleApi.list({
      // 0 表示「全部空间」，与后端 get('space_id', 0) 一致
      space_id: spaceId.value === '' ? 0 : spaceId.value,
      category_id: categoryId.value,
      doc_type_id: docTypeId.value,
      status: status.value,
      keyword: keyword.value.trim(),
      page: page.value,
      size: size.value,
    })
    rows.value = res.data.list ?? []
    total.value = Number(res.data.total || 0)
    void resolveAuthorNames(rows.value)
  } catch {
    rows.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

function fetchKbStats(): void {
  kbStatsApi
    .fetch()
    .then((res) => {
      kbStats.value = res.data ?? null
    })
    .catch(() => {
      kbStats.value = null
    })
}

/** 切换空间：由 watch(spaceId) 统一驱动，避免多条入口各自触发请求 */
async function loadSpaceScope(id: number): Promise<void> {
  await Promise.all([
    wiki.loadAdminCategories(id).catch(() => undefined),
    loadDocTypes(id),
  ])
}

onMounted(async () => {
  await wiki.loadAdminSpaces().catch(() => undefined)
  fetchKbStats()
  // 当前空间由本地记忆恢复；无记忆时保持「全部空间」
  void loadNavArticles(spaceId.value)
  if (spaceId.value !== '') await loadSpaceScope(Number(spaceId.value))
  else await loadDocTypes('')
  void fetchRows()
})

watch(status, resetAndFetch)
watch(page, fetchRows)
watch(size, resetAndFetch)
watch(categoryId, resetAndFetch)
watch(docTypeId, resetAndFetch)
watch(spaceId, async (value) => {
  categoryId.value = ''
  docTypeId.value = ''
  page.value = 1
  if (value === '') {
    void loadNavArticles('')
    await loadDocTypes('')
    void fetchRows()
    return
  }
  void loadNavArticles(value)
  await loadSpaceScope(Number(value))
  await fetchRows()
})

function resetAndFetch(): void {
  page.value = 1
  void fetchRows()
}

function createArticle(): void {
  const id = spaceId.value ? Number(spaceId.value) : ''
  if (!id) {
    router.push({ path: '/admin/wiki/new' })
    return
  }
  const spaceName = wiki.spaceName(id) || wiki.currentSpaceName
  wiki.setAdminSpaceId(id, spaceName)
  router.push({
    path: '/admin/wiki/new',
    query: spaceName ? { space_id: String(id), space_name: spaceName } : { space_id: String(id) },
  })
}

/** 打开门户阅读界面（路由 /wiki/:id） */
function viewArticle(row: ArticleItem): void {
  router.push(`/wiki/${row.id}`)
}

function editArticle(row: ArticleItem): void {
  // 优先带整行快照；刷新编辑页时由 GET /kbapi/admin/articles/:id 兜底
  wiki.setEditRow(row)
  router.push(`/admin/wiki/${row.id}`)
}

/** 侧栏文章节点：后台列表已返回完整行，直接带去编辑页 */
function openNavArticle(article: ArticleItem): void {
  wiki.setEditRow(article)
  router.push(`/admin/wiki/${article.id}`)
}

function toggleStatus(row: ArticleItem): void {
  const target: 'published' | 'offline' = row.status === 'published' ? 'offline' : 'published'
  const tip = target === 'published' ? '发布' : '下架'
  ElMessageBox.confirm(`确定${tip}「${row.title}」吗？`, `${tip}确认`, { type: 'warning' })
    .then(async () => {
      await articleApi.setStatus(row.id, target)
      ElMessage.success(`已${tip}`)
      wiki.loadAdminSpaces(true).catch(() => undefined)
      void fetchRows()
    })
    .catch(() => undefined)
}

function removeArticle(row: ArticleItem): void {
  ElMessageBox.confirm(
    `删除后文章移入回收站，正文、附件与图片都会保留；可在「已删除」状态中恢复。确定删除「${row.title}」吗？`,
    '移入回收站',
    { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
  )
    .then(async () => {
      await articleApi.remove(row.id)
      ElMessage.success('已移入回收站')
      wiki.loadAdminSpaces(true).catch(() => undefined)
      void fetchRows()
    })
    .catch(() => undefined)
}

function restoreArticle(row: ArticleItem): void {
  ElMessageBox.confirm(`确定恢复「${row.title}」吗？将回到删除前的状态与目录。`, '恢复确认', {
    type: 'info',
    confirmButtonText: '恢复',
    cancelButtonText: '取消',
  })
    .then(async () => {
      await articleApi.restore(row.id)
      ElMessage.success('已恢复')
      wiki.loadAdminSpaces(true).catch(() => undefined)
      void fetchRows()
    })
    .catch(() => undefined)
}

function purgeArticle(row: ArticleItem): void {
  ElMessageBox.confirm(
    `「${row.title}」将从列表彻底移除，并立即物理删除其附件、图片、封面与历史版本，不可恢复。确定清除吗？`,
    '彻底清除',
    { type: 'error', confirmButtonText: '清除', cancelButtonText: '取消' },
  )
    .then(async () => {
      await articleApi.purge(row.id)
      ElMessage.success('已彻底清除')
      wiki.loadAdminSpaces(true).catch(() => undefined)
      void fetchRows()
    })
    .catch(() => undefined)
}
</script>

<template>
  <div class="layout page-wide" style="padding-top: 6px">
    <aside class="sidebar sidebar-resizable" :style="{ width: sidebarWidth + 'px' }">
      <div class="card-solid rise d1">
        <div class="panel-title">知识目录</div>
        <KbNavTree
          v-model:category-id="categoryId"
          :space-id="spaceId"
          :categories="currentCategories"
          :articles="navArticles"
          :loading="navLoading"
          @open-article="openNavArticle"
        />

        <div class="side-divider"></div>
        <div class="side-meta">空间编码：{{ currentSpace?.code || '—' }}</div>
      </div>
      <div
        class="sidebar-resizer"
        role="separator"
        aria-orientation="vertical"
        :aria-valuenow="sidebarWidth"
        :aria-valuemin="minWidth"
        :aria-valuemax="maxWidth"
        tabindex="0"
        :title="`拖动调整宽度（${minWidth}–${maxWidth}px，默认 ${defaultWidth}px），双击恢复`"
        @pointerdown="startResize"
        @keydown="onResizeKeydown"
        @dblclick="resetWidth"
      ></div>
    </aside>

    <main class="main">
      <div class="toolbar rise d2">
        <div class="search" style="width: 240px">
          <Search :size="14" color="#8A93A6" />
          <input v-model="keyword" placeholder="搜索标题…" @keyup.enter="resetAndFetch" />
        </div>

        <span class="toolbar-divider"></span>

        <select v-model="docTypeId" class="select" style="width: 170px; height: 36px" title="按文档类型筛选">
          <option value="">全部文档类型</option>
          <option v-for="dt in docTypes" :key="dt.id" :value="dt.id">{{ dt.name }}</option>
        </select>

        <select v-model="status" class="select" style="width: 120px; height: 36px">
          <option value="">全部状态</option>
          <option value="published">已发布</option>
          <option value="draft">草稿</option>
          <option value="offline">已下架</option>
          <option value="deleted">已删除</option>
        </select>

        <div class="sp"></div>
        <button v-if="canCreate" type="button" class="btn btn-primary" @click="createArticle">
          <Plus :size="15" /> 新建文章
        </button>
      </div>

      <div class="card-solid rise d2" style="padding: 0; overflow: hidden">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 80px">封面</th>
              <th>标题</th>
              <th style="width: 110px">文档类型</th>
              <th style="width: 92px">状态</th>
              <th style="width: 112px">可见范围</th>
              <th style="width: 76px">视频</th>
              <th style="width: 76px">浏览</th>
              <th style="width: 116px">更新时间</th>
              <th style="width: 190px">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td>
                <div class="thumb">
                  <div class="ph" :class="gradientOf(row)"></div>
                </div>
              </td>
              <td>
                <div class="cell-title truncate" style="max-width: 420px">{{ row.title }}</div>
                <div class="cell-sub">{{ authorLabel(row) }} · {{ row.edit_mode === 'open' ? '开放编辑' : '指定人可改' }}</div>
              </td>
              <td>{{ row.doc_type_name || '—' }}</td>
              <td><span class="pill" :class="statusMeta(row).pill">{{ statusMeta(row).label }}</span></td>
              <td><span class="pill" :class="scopeMeta(row).pill">{{ scopeMeta(row).label }}</span></td>
              <td>
                <span v-if="isVideo(row)" class="pill blue">视频</span>
                <span v-else class="tag">图文</span>
              </td>
              <td class="tabular">{{ row.status === 'published' ? formatCount(row.view_count) : '—' }}</td>
              <td class="tabular">{{ formatShortDateTime(row.updated_at) }}</td>
              <td>
                <div class="link-btns">
                  <template v-if="row.status === 'deleted'">
                    <button v-if="canEditRow(row, 'kb:article:delete')" type="button" class="link" @click="restoreArticle(row)">恢复</button>
                    <button v-if="canPurge" type="button" class="link danger" @click="purgeArticle(row)">清除</button>
                  </template>
                  <template v-else>
                    <button type="button" class="link" @click="viewArticle(row)">查看</button>
                    <button v-if="canEditRow(row, 'kb:article:update')" type="button" class="link" @click="editArticle(row)">编辑</button>
                    <button v-if="canEditRow(row, 'kb:article:publish')" type="button" class="link" @click="toggleStatus(row)">
                      {{ row.status === 'published' ? '下架' : '发布' }}
                    </button>
                    <button v-if="canEditRow(row, 'kb:article:delete')" type="button" class="link danger" @click="removeArticle(row)">删除</button>
                  </template>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && !rows.length">
              <td colspan="9">
                <div class="kb-empty">
                  <div class="kb-empty-icon">📄</div>
                  <div style="margin-top: 10px">该筛选条件下暂无文章</div>
                  <div style="margin-top: 6px; font-size: 12px">
                    试试切换空间 / 目录 / 文档类型 / 状态，或新建一篇文章。
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="loading" class="kb-empty" style="padding: 24px">加载中…</div>
      </div>

      <div class="statusbar rise d3">
        <span>
          存储已用
          <b>{{ kbStats ? formatBytes(kbStats.used_bytes) : '—' }}</b>
          {{ kbStats && kbStats.total_bytes ? `/ ${formatBytes(kbStats.total_bytes)}` : '' }}
        </span>
        <div class="meter"><i :style="{ width: `${storagePercent || 38}%` }"></i></div>
        <span>转码队列：<b style="color: var(--amber)">{{ kbStats ? `${kbStats.transcoding} 个进行中` : '暂无统计' }}</b></span>
        <span>失败：<b style="color: var(--red)">{{ kbStats ? `${kbStats.failed} 个` : '—' }}</b></span>
        <span style="margin-left: auto; color: var(--text-3)">共 {{ total }} 条 · 每页 {{ size }} 条</span>
      </div>

      <div style="margin-top: 12px; display: flex; justify-content: flex-end">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="size"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          background
        />
      </div>

      <div v-if="!kbStats" class="cell-sub" style="margin-top: 8px">
        说明：统计接口 GET /kbapi/admin/kb/stats 暂不可用，存储容量与转码队列显示占位值。
      </div>
    </main>
  </div>
</template>
