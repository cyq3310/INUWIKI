<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { portalApi } from '../api'
import KbContentRenderer from './KbContentRenderer.vue'
import { buildArticleDiff } from '../utils/article-diff'
import type { ArticleVersion, Attachment } from '../types'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    articleId: number
    currentContent: string
    attachments?: Attachment[]
  }>(),
  { attachments: () => [] },
)

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

type HistoryTab = 'diff' | 'history' | 'current'

const versions = ref<ArticleVersion[]>([])
const activeId = ref(0)
const version = ref<ArticleVersion | null>(null)
const loading = ref(false)
const detailLoading = ref(false)
const loadError = ref('')
const tab = ref<HistoryTab>('diff')

const currentVersion = computed(() => versions.value.find((row) => row.id === activeId.value) || null)
const diff = computed(() =>
  version.value ? buildArticleDiff(version.value.content || '', props.currentContent || '') : null,
)

function close(): void {
  emit('update:modelValue', false)
}

async function selectVersion(row: ArticleVersion): Promise<void> {
  activeId.value = row.id
  version.value = null
  tab.value = 'diff'
  detailLoading.value = true
  try {
    const res = await portalApi.version(props.articleId, row.id)
    version.value = res.data?.version ?? null
  } catch {
    version.value = null
  } finally {
    detailLoading.value = false
  }
}

async function loadVersions(): Promise<void> {
  versions.value = []
  version.value = null
  activeId.value = 0
  loadError.value = ''
  tab.value = 'diff'

  if (!props.articleId) return

  loading.value = true
  try {
    const res = await portalApi.versions(props.articleId)
    versions.value = res.data?.list ?? []
    if (versions.value.length) {
      await selectVersion(versions.value[0])
    }
  } catch {
    loadError.value = '历史版本加载失败，请稍后重试。'
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void loadVersions()
  },
)

watch(
  () => props.articleId,
  () => {
    if (props.modelValue) void loadVersions()
  },
)
</script>

<template>
  <el-drawer :model-value="modelValue" title="历史版本（只读）" size="880px" @update:model-value="close">
    <div class="history-layout">
      <aside class="history-list">
        <div class="history-list-title">已发布版本</div>
        <div v-if="loading" class="cell-sub" style="padding: 12px 4px">加载中…</div>
        <div v-else-if="loadError" class="history-error">{{ loadError }}</div>
        <div v-else-if="!versions.length" class="cell-sub" style="padding: 12px 4px">
          暂无已发布历史版本
        </div>
        <button
          v-for="row in versions"
          :key="row.id"
          type="button"
          class="history-item"
          :class="{ active: row.id === activeId }"
          @click="selectVersion(row)"
        >
          <span class="cell-title">第 {{ row.version_no }} 版</span>
          <span class="cell-sub">{{ row.created_at }}</span>
          <span class="cell-sub history-item-title">{{ row.title || '无标题' }}</span>
        </button>
      </aside>

      <section class="history-main">
        <template v-if="detailLoading">
          <div class="kb-empty">版本正文加载中…</div>
        </template>
        <template v-else-if="!version">
          <div class="kb-empty">点击左侧版本查看差异</div>
        </template>
        <template v-else>
          <div class="history-toolbar">
            <div class="history-compare">
              <b>第 {{ version.version_no }} 版</b>
              <span>VS 当前版本</span>
              <span class="history-readonly">只读</span>
            </div>
            <div class="history-tabs">
              <button type="button" class="btn btn-sm" :class="{ 'btn-primary': tab === 'diff' }" @click="tab = 'diff'">
                差异
              </button>
              <button
                type="button"
                class="btn btn-sm"
                :class="{ 'btn-primary': tab === 'history' }"
                @click="tab = 'history'"
              >
                历史原文
              </button>
              <button
                type="button"
                class="btn btn-sm"
                :class="{ 'btn-primary': tab === 'current' }"
                @click="tab = 'current'"
              >
                当前正文
              </button>
            </div>
          </div>

          <div v-if="tab === 'diff'" class="history-diff">
            <div v-if="diff && !diff.hasDiff" class="kb-empty">该版本与当前正文一致，没有差异。</div>
            <div v-else class="diff-columns">
              <section class="diff-pane">
                <header class="diff-pane-title">历史版本 · 第 {{ version.version_no }} 版</header>
                <div class="diff-content">
                  <template v-for="(part, index) in diff?.oldParts ?? []" :key="`old-${index}`">
                    <del v-if="part.kind === 'removed'">{{ part.text }}</del>
                    <span v-else>{{ part.text }}</span>
                  </template>
                </div>
              </section>
              <section class="diff-pane current">
                <header class="diff-pane-title">当前版本</header>
                <div class="diff-content">
                  <template v-for="(part, index) in diff?.currentParts ?? []" :key="`current-${index}`">
                    <ins v-if="part.kind === 'added'">{{ part.text }}</ins>
                    <span v-else>{{ part.text }}</span>
                  </template>
                </div>
              </section>
            </div>
          </div>

          <div v-else-if="tab === 'history'" class="history-preview">
            <KbContentRenderer
              :html="version.content || ''"
              :attachments="attachments"
              :article-id="articleId"
            />
          </div>

          <div v-else class="history-preview">
            <KbContentRenderer
              :html="currentContent"
              :attachments="attachments"
              :article-id="articleId"
            />
          </div>
        </template>
      </section>
    </div>
  </el-drawer>
</template>

<style scoped>
.history-layout {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  gap: 16px;
  min-height: 100%;
}

.history-list {
  border-right: 1px solid var(--line);
  padding-right: 12px;
}

.history-list-title {
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 10px;
  color: var(--text);
}

.history-error {
  padding: 10px;
  color: var(--red, #e5484d);
  font-size: 12px;
}

.history-item {
  display: block;
  width: 100%;
  text-align: left;
  padding: 10px 11px;
  margin-bottom: 8px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: var(--surface);
  cursor: pointer;
}

.history-item:hover {
  border-color: var(--primary-line, #b9c9ff);
  background: #f8faff;
}

.history-item.active {
  border-color: var(--primary);
  background: var(--primary-weak, #eef3ff);
}

.history-item-title {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-top: 4px;
}

.history-main {
  min-width: 0;
}

.history-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.history-compare {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

.history-compare > span:not(.history-readonly) {
  color: var(--text-3, #8a93a6);
}

.history-readonly {
  padding: 2px 7px;
  border-radius: 999px;
  background: var(--primary-weak, #eef3ff);
  color: var(--primary);
  font-size: 11px;
}

.history-tabs {
  display: flex;
  gap: 6px;
}

.diff-columns {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.diff-pane {
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow: hidden;
  background: var(--surface);
}

.diff-pane-title {
  padding: 9px 12px;
  background: #f7f8fb;
  border-bottom: 1px solid var(--line);
  font-size: 13px;
  font-weight: 600;
}

.diff-pane.current .diff-pane-title {
  background: #f2f8f3;
}

.diff-content {
  padding: 14px;
  max-height: calc(100vh - 220px);
  overflow: auto;
  font-size: 13px;
  line-height: 1.9;
  white-space: pre-wrap;
  word-break: break-word;
  color: var(--text);
}

.diff-content del {
  background: #ffe8e8;
  color: #b42318;
  text-decoration: line-through;
  border-radius: 3px;
  padding: 1px 2px;
}

.diff-content ins {
  background: #dcf6e4;
  color: #16794a;
  text-decoration: none;
  border-radius: 3px;
  padding: 1px 2px;
}

.history-preview {
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 16px;
  max-height: calc(100vh - 220px);
  overflow: auto;
  background: var(--surface);
}

@media (max-width: 900px) {
  .history-layout {
    grid-template-columns: 1fr;
  }
  .history-list {
    border-right: 0;
    border-bottom: 1px solid var(--line);
    padding-right: 0;
    padding-bottom: 12px;
    display: flex;
    gap: 8px;
    overflow-x: auto;
  }
  .history-list-title {
    display: none;
  }
  .history-item {
    min-width: 160px;
    margin-bottom: 0;
  }
  .diff-columns {
    grid-template-columns: 1fr;
  }
}
</style>
