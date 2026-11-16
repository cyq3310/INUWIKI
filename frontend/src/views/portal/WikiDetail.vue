<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Download, History, Pencil } from 'lucide-vue-next'
import ArticleHistoryDrawer from '../../components/ArticleHistoryDrawer.vue'
import KbArticleCard from '../../components/KbArticleCard.vue'
import KbContentRenderer from '../../components/KbContentRenderer.vue'
import { portalApi } from '../../api'
import { useUserStore } from '../../stores/user'
import { useWikiStore } from '../../stores/wiki'
import { parseContent, type TocItem } from '../../utils/content'
import { mediaPlayUrl } from '../../utils/media'
import { fileExt, formatBytes, formatCount, formatDateTime } from '../../utils/format'
import type { ArticleItem } from '../../types'

/**
 * 门户 · 知识详情页
 * 面包屑（WIKI / 空间 / 分类 / 标题）→ 正文（占位节点原地渲染）→ 文末附件 → 上下篇 → 相关推荐。
 * 右侧目录锚点跟随滚动高亮；右侧所属空间卡片。
 */

const route = useRoute()
const router = useRouter()
const store = useUserStore()
const wiki = useWikiStore()

const loading = ref(true)
const article = ref<ArticleItem | null>(null)
const historyOpen = ref(false)
const toc = ref<TocItem[]>([])
const activeToc = ref('')

const prevArticle = ref<ArticleItem | null>(null)
const nextArticle = ref<ArticleItem | null>(null)
const related = ref<ArticleItem[]>([])

const articleId = computed(() => Number(route.params.id) || 0)
const parsed = computed(() => parseContent(article.value?.content || ''))

const EXT_GRADIENT: Record<string, string> = {
  pdf: 'g3', doc: 'g1', docx: 'g1', xls: 'g2', xlsx: 'g2',
  ppt: 'g6', pptx: 'g6', zip: 'g4', png: 'g5', jpg: 'g5',
  jpeg: 'g5', webp: 'g5', gif: 'g5', txt: 'g1',
}
const gradientOf = (name: string) => EXT_GRADIENT[fileExt(name)] || 'g1'

/** 文末附件列表：图片作为正文配图展示，不重复出现在附件区 */
const attachmentsAll = computed(() => (article.value?.attachments ?? []).filter((row) => row.type !== 'image'))

const spaceName = computed(() => (article.value ? wiki.spaceName(article.value.space_id) || `空间 #${article.value.space_id}` : ''))
const categoryName = computed(() =>
  article.value ? wiki.categoryFullPath(article.value.space_id, article.value.category_id) : '',
)

/** 编辑按钮由后端 canEditArticle 判定：前端只有权限码，无法复现空间授权与编辑名单 */
const canEdit = computed(() => article.value?.can_edit === true)

function editArticle(): void {
  const row = article.value
  if (!row) return
  // 后端没有 GET /kbapi/admin/articles/:id，带整行快照才能编辑草稿 / 下架文章
  wiki.setEditRow(row)
  void router.push(`/admin/wiki/${row.id}`)
}

/** 后端列表 / 详情仅返回 author_id，无用户目录接口可反查姓名 */
const authorLabel = computed(() => {
  const row = article.value
  if (!row) return '—'
  if (store.profile && row.author_id === store.profile.id) return `${store.displayName}（我）`
  return `作者 #${row.author_id}`
})

const currentSpace = computed(() =>
  article.value ? wiki.spaces.find((s) => Number(s.id) === Number(article.value!.space_id)) ?? null : null,
)

const playUrl = (attachmentId: number) => (article.value ? mediaPlayUrl(article.value.id, attachmentId) : '')

function go(id: number): void {
  router.push(`/wiki/${id}`)
}

function jumpTo(id: string): void {
  activeToc.value = id
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// ---------- 目录滚动高亮 ----------

function onScroll(): void {
  const heads = toc.value
    .map((item) => document.getElementById(item.id))
    .filter((el): el is HTMLElement => !!el)
  if (!heads.length) return
  const threshold = 120
  let current = heads[0].id
  for (const el of heads) {
    if (el.getBoundingClientRect().top <= threshold) current = el.id
    else break
  }
  activeToc.value = current
}

async function loadDetail(): Promise<void> {
  const id = articleId.value
  if (!id) {
    router.replace('/')
    return
  }
  loading.value = true
  historyOpen.value = false
  article.value = null
  toc.value = []
  related.value = []
  prevArticle.value = null
  nextArticle.value = null

  try {
    const res = await portalApi.article(id)
    const row = res.data?.article
    if (!row) {
      loading.value = false
      return
    }
    article.value = row
    toc.value = parseContent(row.content || '').toc

    // 空间 / 分类名（面包屑与右侧卡片用）
    await wiki.loadSpaces().catch(() => undefined)
    await wiki.loadCategories(row.space_id).catch(() => undefined)

    void loadSiblings(row)
    void loadRelated(row)

    await nextTick()
    onScroll()
  } catch {
    article.value = null
  } finally {
    loading.value = false
  }
}

/** 上下篇：后端无专有接口，取同分类列表后按位置推导 */
async function loadSiblings(row: ArticleItem): Promise<void> {
  try {
    const res = await portalApi.articles({
      space_id: row.space_id,
      category_id: row.category_id || '',
      size: 50,
    })
    const rows = res.data?.list ?? []
    const index = rows.findIndex((item) => item.id === row.id)
    if (index < 0) return
    prevArticle.value = index > 0 ? rows[index - 1] : null
    nextArticle.value = index < rows.length - 1 ? rows[index + 1] : null
  } catch {
    prevArticle.value = null
    nextArticle.value = null
  }
}

/** 相关推荐：优先同分类，不足 3 条时用同空间补齐 */
async function loadRelated(row: ArticleItem): Promise<void> {
  const pick = (rows: ArticleItem[]): ArticleItem[] =>
    rows.filter((item) => item.id !== row.id).slice(0, 3)

  try {
    if (row.category_id) {
      const res = await portalApi.articles({ category_id: row.category_id, size: 8 })
      related.value = pick(res.data?.list ?? [])
    }
    if (related.value.length < 3) {
      const res = await portalApi.articles({ space_id: row.space_id, size: 8 })
      const extra = pick(res.data?.list ?? []).filter(
        (item) => !related.value.some((existing) => existing.id === item.id),
      )
      related.value = [...related.value, ...extra].slice(0, 3)
    }
  } catch {
    related.value = []
  }
}

onMounted(() => {
  window.addEventListener('scroll', onScroll, { passive: true })
  void loadDetail()
})

onBeforeUnmount(() => {
  window.removeEventListener('scroll', onScroll)
})

watch(
  () => route.params.id,
  (value, old) => {
    if (value && value !== old && route.name === 'WikiDetail') void loadDetail()
  },
)
</script>

<template>
  <div class="page-max" style="padding: 0 28px 40px">
    <div class="crumb">
      <span class="link" @click="router.push('/')">WIKI</span>
      <span>/</span>
      <span class="link" @click="router.push('/')">{{ spaceName || '全部知识库' }}</span>
      <template v-if="categoryName">
        <span>/</span>
        <span>{{ categoryName }}</span>
      </template>
      <span>/</span>
      <b>{{ article?.title || '加载中…' }}</b>
    </div>

    <div v-if="loading" class="kb-empty">加载中…</div>

    <div v-else-if="!article" class="kb-empty">
      <div class="kb-empty-icon">🔒</div>
      <div style="margin-top: 10px">知识不存在或当前账号不可见</div>
      <div style="margin-top: 6px; font-size: 12px">受限内容仅授权成员可见，请联系空间管理员开通权限。</div>
    </div>

    <div v-else class="detail-wrap detail-grid">
      <main>
        <article class="article rise d1" style="margin-top: 0">
          <div class="article-head">
            <div class="article-title-row">
              <h2>{{ article.title }}</h2>
              <button type="button" class="btn btn-sm" @click="historyOpen = true">
                <History :size="14" /> 历史版本
              </button>
              <button v-if="canEdit" type="button" class="btn btn-sm" @click="editArticle">
                <Pencil :size="14" /> 编辑
              </button>
            </div>
            <div class="article-meta">
              <span class="avatar-mini">{{ authorLabel.slice(-1) }}</span>
              <span>{{ authorLabel }}</span>
              <span class="meta-sep"></span>
              <span>更新于 {{ formatDateTime(article.published_at || article.updated_at) }}</span>
              <span class="meta-sep"></span>
              <span>{{ formatCount(article.view_count) }} 次浏览</span>
              <span class="pill green">已发布</span>
              <span v-if="currentSpace?.is_restricted === 1" class="pill amber">🔒 受限空间</span>
            </div>
          </div>

          <!-- 正文渲染：视频 / 附件占位节点原地渲染为播放器与附件卡片 -->
          <div class="article-content">
            <KbContentRenderer :html="article.content" :attachments="article.attachments ?? []" :article-id="article.id" />
          </div>

          <!-- 文末附件列表 -->
          <div v-if="attachmentsAll.length" class="card-solid rise d2 att-panel">
            <div class="side-card-title">本文附件（{{ attachmentsAll.length }}）</div>
            <div class="att-grid">
              <div v-for="att in attachmentsAll" :key="att.id" class="att-item">
                <div class="thumb att-thumb">
                  <div class="ph" :class="gradientOf(att.original_name)"></div>
                </div>
                <div class="att-body">
                  <div class="att-name">{{ att.original_name }}</div>
                  <div class="cell-sub">
                    {{ formatBytes(att.size) }}
                    <template v-if="att.status === 'failed'"> · 转码失败</template>
                    <template v-else-if="att.status !== 'ready'"> · {{ att.status }}</template>
                  </div>
                </div>
                <a :href="playUrl(att.id)" class="btn btn-sm">
                  <Download :size="14" />下载
                </a>
              </div>
            </div>
          </div>

          <!-- 上下篇 -->
          <div v-if="prevArticle || nextArticle" class="pager">
            <button v-if="prevArticle" type="button" class="pager-btn" @click="go(prevArticle.id)">
              <small>← 上一篇</small>
              <span>{{ prevArticle.title }}</span>
            </button>
            <button v-if="nextArticle" type="button" class="pager-btn right" @click="go(nextArticle.id)">
              <small>下一篇 →</small>
              <span>{{ nextArticle.title }}</span>
            </button>
          </div>
        </article>

        <!-- 相关推荐 -->
        <div v-if="related.length" class="card-solid rise d3 related-panel" style="margin-top: 24px; padding: 18px 20px">
          <div class="side-card-title">相关推荐</div>
          <div class="grid">
            <KbArticleCard
              v-for="item in related"
              :key="item.id"
              :article="item"
              :doc-type-name="item.doc_type_name"
              @open="(row) => go(row.id)"
            />
          </div>
        </div>
      </main>

      <aside class="toc rise d2">
        <div class="card-solid">
          <div class="panel-title">本页目录</div>
          <template v-if="toc.length">
            <div
              v-for="item in toc"
              :key="item.id"
              class="toc-item"
              :class="[`toc-l${item.level}`, { active: activeToc === item.id }]"
              @click="jumpTo(item.id)"
            >
              <span class="toc-label">{{ item.text }}</span>
            </div>
          </template>
          <div v-else class="cell-sub">本文暂无目录结构（可用正文编辑器添加 H2 / H3 标题）</div>
        </div>

        <div class="card-solid" style="margin-top: 16px">
          <div class="panel-title">所属空间</div>
          <div style="display: flex; align-items: center; gap: 8px; padding: 4px 0; flex-wrap: wrap">
            <span class="tag">{{ spaceName || '—' }}</span>
            <span v-if="currentSpace" class="pill" :class="currentSpace.is_restricted === 1 ? 'amber' : 'green'">
              {{ currentSpace.is_restricted === 1 ? '受限' : '公开' }}
            </span>
          </div>
          <div style="font-size: 12px; color: var(--text-3); margin-top: 8px; line-height: 1.7">
            {{
              currentSpace?.description ||
              '该空间对所有登录用户可见；受限空间仅授权成员可见，且不计入全站统计。'
            }}
          </div>
        </div>
      </aside>
    </div>

    <ArticleHistoryDrawer
      v-model="historyOpen"
      :article-id="article?.id || 0"
      :current-content="article?.content || ''"
      :attachments="article?.attachments ?? []"
    />
  </div>
</template>

<style scoped>
/* 【本页目录】【所属空间】置于正文左侧：首列固定 268px，正文占其余列。
   DOM 仍保持 main 在前，读屏与键盘顺序不变，仅用 grid-column 指定视觉位置 */
.detail-grid {
  grid-template-columns: 268px 1fr;
  align-items: start;
}
.detail-grid > main {
  grid-column: 2;
  grid-row: 1;
}
.detail-grid > aside {
  grid-column: 1;
  grid-row: 1;
}
/* 正文与相关推荐同宽、居中对齐：原先正文元素满列、内部再被 --read-max 限宽居中，
   相关推荐却满列，导致两者边缘错位 */
.detail-grid > main > .article,
.detail-grid > main > .related-panel {
  width: 100%;
  margin-inline: auto;
}
/* 正文加白色底框并占满中间列，消除两侧留白 */
.article {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--r-l);
  padding: 30px 34px;
}
/* 标题区 / 上下篇也占满卡片，与正文同宽对齐 */
.article-head,
.pager {
  max-width: none;
  margin-inline: 0;
}
.article-title-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
}
.article-title-row h2 {
  flex: 1;
  min-width: 0;
  max-width: none;
  margin-inline: 0;
}
.article-title-row .btn {
  flex: none;
}
/* 阅读区默认预留 12 行，内容更多时自然增高；文本居中限宽避免超框 */
.article-content {
  min-height: var(--read-min-height);
  max-width: var(--read-text);
  margin-inline: auto;
  overflow-wrap: break-word;
}
/* 正文块在 design.css 中还受 --read-max(880px) 限制，这里解除，
   让阅读区文本与编辑区 .kb-editor 一样使用 --read-text(1050px) */
.article-content :deep(.article-body) {
  max-width: 100%;
}
.article-content :deep(img),
.article-content :deep(video),
.article-content :deep(table) {
  max-width: 100%;
  height: auto;
}
.article-content :deep(table) {
  display: block;
  overflow-x: auto;
}
</style>
