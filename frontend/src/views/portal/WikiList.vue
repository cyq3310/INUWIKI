<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { FileText, Inbox, Plus, Search } from 'lucide-vue-next'
import KbArticleCard from '../../components/KbArticleCard.vue'
import KbNavTree from '../../components/KbNavTree.vue'
import { portalApi } from '../../api'
import { useWikiStore } from '../../stores/wiki'
import { useUserStore } from '../../stores/user'
import { getRuntimeConfig } from '../../config/runtime'
import { extractMediaRefs } from '../../utils/content'
import { formatCount } from '../../utils/format'
import { fetchAllSpaceArticles } from '../../utils/space-nav'
import { useSidebarWidth } from '../../composables/useSidebarWidth'
import type { ArticleItem, ArticleStats } from '../../types'

/**
 * 门户 · WIKI 列表页
 * 左侧：空间切换器（受限空间带锁）+ 分类树；右侧：英雄区统计 + 快捷筛选 + 卡片瀑布 + 分页。
 * 数据全部来自「可见空间」作用域，未授权空间与内容后端不会返回。
 */

type ChipKey = 'all' | 'recent' | 'hot' | 'video' | 'doc'

const router = useRouter()
const wiki = useWikiStore()
const user = useUserStore()
const { sidebarWidth, minWidth, defaultWidth, maxWidth, startResize, onResizeKeydown, resetWidth } = useSidebarWidth()

const spacesLoading = ref(false)
const loading = ref(false)
const list = ref<ArticleItem[]>([])
const total = ref(0)
const page = ref(1)
const size = ref(getRuntimeConfig().portal.page_size || 12)

/** 当前空间由顶栏下拉统一控制，列表页只读共享状态 */
const spaceId = computed({
  get: () => wiki.portalSpaceId,
  set: (value: number | '') => wiki.setPortalSpaceId(value),
})
const categoryId = ref<number | ''>('')
const keyword = ref(wiki.keyword)
const chip = ref<ChipKey>('all')

const canCreate = computed(() => user.hasPermission('kb:article:create'))

function createArticle(): void {
  const id = spaceId.value ? Number(spaceId.value) : ''
  if (!id) {
    // 「全部空间」下不预选任何空间，由创建页自己选择
    router.push({ path: '/admin/wiki/new' })
    return
  }
  const spaceName = wiki.spaceName(id) || wiki.currentSpaceName
  // 空间名一并带过去：列表接口取不到时，创建页仍能显示来源空间的真实名称
  wiki.setAdminSpaceId(id, spaceName)
  router.push({
    path: '/admin/wiki/new',
    query: spaceName ? { space_id: String(id), space_name: spaceName } : { space_id: String(id) },
  })
}

const stats = ref<ArticleStats | null>(null)

/** 侧栏目录树：当前空间的分类与全部文章（与主列表分页数据分开） */
const navArticles = ref<ArticleItem[]>([])
const navLoading = ref(false)
let navSeq = 0

const currentCategories = computed(() =>
  spaceId.value === '' ? [] : wiki.categories[Number(spaceId.value)] ?? [],
)

async function loadNavArticles(id: number | ''): Promise<void> {
  const seq = ++navSeq
  if (id === '') {
    navArticles.value = []
    return
  }
  navLoading.value = true
  try {
    const rows = await fetchAllSpaceArticles((page, size) =>
      portalApi.articles({ space_id: Number(id), page, size }).then((res) => ({
        list: res.data.list ?? [],
        total: Number(res.data.total || 0),
      })),
    )
    if (seq === navSeq) navArticles.value = rows
  } catch {
    if (seq === navSeq) navArticles.value = []
  } finally {
    if (seq === navSeq) navLoading.value = false
  }
}

const currentSpace = computed(() =>
  spaceId.value === '' ? null : wiki.spaces.find((s) => Number(s.id) === Number(spaceId.value)) ?? null,
)

const CHIPS: { key: ChipKey; label: string }[] = [
  { key: 'all', label: '全部' },
  { key: 'recent', label: '最近更新' },
  { key: 'hot', label: '最热' },
  { key: 'video', label: '视频课程' },
  { key: 'doc', label: '图文文档' },
]

/**
 * 「最热 / 视频课程 / 图文文档」后端暂无对应参数，
 * 这里在当前页结果内做排序与筛选，并显式提示口径，避免误解为全量结果。
 */
const clientFiltered = computed(() => chip.value === 'video' || chip.value === 'doc')
const clientSorted = computed(() => chip.value === 'hot')

const displayList = computed(() => {
  let rows = [...list.value]
  if (chip.value === 'video') {
    rows = rows.filter((row) => extractMediaRefs(row.content || '').videoIds.length > 0)
  } else if (chip.value === 'doc') {
    rows = rows.filter((row) => extractMediaRefs(row.content || '').videoIds.length === 0)
  } else if (chip.value === 'hot') {
    rows.sort((a, b) => b.view_count - a.view_count)
  }
  return rows
})

async function fetchArticles(): Promise<void> {
  loading.value = true
  try {
    const res = await portalApi.articles({
      space_id: spaceId.value,
      category_id: categoryId.value,
      keyword: keyword.value,
      page: page.value,
      size: size.value,
    })
    list.value = res.data.list ?? []
    total.value = Number(res.data.total || 0)
  } catch {
    list.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

async function fetchStats(): Promise<void> {
  try {
    const res = await portalApi.stats()
    stats.value = res.data ?? null
  } catch {
    stats.value = null
  }
}

async function loadSpaceTree(id: number | ''): Promise<void> {
  if (id === '') return
  await wiki.loadCategories(id).catch(() => undefined)
}

function openArticle(article: ArticleItem): void {
  router.push(`/wiki/${article.id}`)
}

function resetAndFetch(): void {
  page.value = 1
  void fetchArticles()
}

onMounted(async () => {
  spacesLoading.value = true
  try {
    await wiki.loadSpaces()
  } finally {
    spacesLoading.value = false
  }
  // 当前空间由本地记忆恢复；无记忆时保持「全部空间」，不再强制落到第一个空间。
  // 恢复出的 spaceId 不会再触发 watch(spaceId)，首屏必须显式加载分类树，
  // 否则侧栏只有「该空间暂无目录与文章」的空态，点目录也没反应。
  const restored = spaceId.value
  void Promise.all([loadSpaceTree(restored), loadNavArticles(restored)])
  void fetchArticles()
  void fetchStats()
})

watch(spaceId, async (value) => {
  categoryId.value = ''
  await Promise.all([loadSpaceTree(value), loadNavArticles(value)])
  resetAndFetch()
})

watch(categoryId, resetAndFetch)
watch(page, fetchArticles)
watch(size, resetAndFetch)

// 顶栏搜索框 → 列表页
watch(
  () => wiki.keyword,
  (value) => {
    keyword.value = value
    resetAndFetch()
  },
)

const heroTitle = computed(() => currentSpace.value?.name || '全部知识库')
const heroDesc = computed(
  () =>
    currentSpace.value?.description ||
    '沉淀团队经验，支持图文与课程视频。视频上传后自动转码，可在线拖拽播放；支持全文检索，按目录快速定位。',
)
</script>

<template>
  <div class="layout page-max">
    <aside class="sidebar sidebar-resizable" :style="{ width: sidebarWidth + 'px' }">
      <div class="card-solid rise d1">
        <div class="panel-title">知识目录</div>
        <KbNavTree
          v-model:category-id="categoryId"
          :space-id="spaceId"
          :categories="currentCategories"
          :articles="navArticles"
          :loading="navLoading || spacesLoading"
          @open-article="openArticle"
        />
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
      <section class="hero rise d2">
        <div class="hero-main">
          <h1>{{ heroTitle }}</h1>
          <p>{{ heroDesc }}</p>
          <div v-if="canCreate" class="hero-actions">
            <button type="button" class="btn btn-create" @click="createArticle">
              <Plus :size="15" /> 新增文章
            </button>
            <small v-if="spaceId !== ''">将在「{{ heroTitle }}」中创建</small>
            <small v-else>请在创建页选择所属空间</small>
          </div>
        </div>
        <div class="hero-stats">
          <div><span>{{ stats ? formatCount(stats.article_count) : '—' }}</span><small>知识条目</small></div>
          <div><span>{{ stats ? formatCount(stats.video_count) : '—' }}</span><small>视频课程</small></div>
          <div><span>{{ stats ? formatCount(stats.view_count) : '—' }}</span><small>累计浏览</small></div>
          <div><span>{{ formatCount(total) }}</span><small>当前筛选</small></div>
        </div>
      </section>

      <!-- 筛选条：筛选胶囊与「仅当前页生效」口径提示同行，不再单独占一行 -->
      <div class="filter-bar rise d3">
        <div class="chips">
          <div
            v-for="item in CHIPS"
            :key="item.key"
            class="chip"
            :class="{ active: chip === item.key }"
            @click="chip = item.key"
          >
            {{ item.label }}
          </div>
        </div>
        <div v-if="clientFiltered || clientSorted" class="filter-note">
          <Search :size="12" />
          后端暂未提供「{{ clientFiltered ? '视频 / 图文' : '热度' }}」筛选参数，该筛选仅在<b>当前页</b>结果内生效。
        </div>
      </div>

      <div v-if="loading" class="kb-empty">加载中…</div>

      <div v-else-if="displayList.length" class="grid rise d3">
        <KbArticleCard
          v-for="article in displayList"
          :key="article.id"
          :article="article"
          :doc-type-name="article.doc_type_name"
          @open="openArticle"
        />
      </div>

      <div v-else class="kb-empty rise d3">
        <div class="kb-empty-icon"><Inbox :size="40" /></div>
        <div style="margin-top: 10px">该条件下暂无知识</div>
        <div style="margin-top: 6px; font-size: 12px">
          <template v-if="keyword">没有匹配「{{ keyword }}」的内容，试试更换关键词或切换空间。</template>
          <template v-else>换个目录或空间看看，或联系空间管理员发布内容。</template>
        </div>
        <div style="margin-top: 12px; display: flex; justify-content: center; color: var(--text-3); font-size: 12px">
          <FileText :size="13" class="inline-block align-[-2px]" /> 共 {{ total }} 条
        </div>
      </div>

      <div v-if="total > size" style="margin-top: 22px; display: flex; justify-content: flex-end">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="size"
          :total="total"
          :page-sizes="[12, 24, 48]"
          layout="total, sizes, prev, pager, next"
          background
        />
      </div>
    </main>
  </div>
</template>
