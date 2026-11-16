<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown, ArrowUp, ChevronRight, Eye, Folder, Home, Lock, Move, Pencil, RefreshCw, Tag, Trash2 } from 'lucide-vue-next'
import KbSubjectPicker from '../../components/KbSubjectPicker.vue'
import PermissionConfig from '../../components/PermissionConfig.vue'
import { categoryApi, directoryApi, docTypeApi } from '../../api'
import { useUserStore } from '../../stores/user'
import { useWikiStore } from '../../stores/wiki'
import type { DocType, DocTypeSubject, SubjectType } from '../../types'
import {
  CATEGORY_MAX_LEVEL,
  CATEGORY_NAME_MAX_LENGTH,
  SYSTEM_CATEGORY_NAME,
  canAddChildAt,
  flattenCategories,
  isSystemCategory,
  sortSystemLast,
} from '../../utils/tree'
import type { FlatCategory } from '../../utils/tree'

/**
 * 后台 · 配置管理：按「当前空间」维护目录结构与文档类型。
 * - 目录管理：调整目录结构（旧能力，与文档类型相互独立）。
 * - 文档类型：文章创建时的「分类」来源 + 默认可见范围（角色 / 人员），可独立维护。
 * - 权限配置：按角色 / 人员分别授予系统权限码（全局生效，独立于 PermissionConfig 组件）。
 */
const wiki = useWikiStore()
const store = useUserStore()

type Tab = 'dir' | 'doctype' | 'perm'
/** 配置管理三个页签各自对应一个独立权限码 */
const TAB_PERMS: Record<Tab, string> = {
  dir: 'kb:category:manage',
  doctype: 'kb:doc_type:manage',
  perm: 'kb:permission:manage',
}
const TAB_KEYS: Tab[] = ['dir', 'doctype', 'perm']
const canTab = computed<Record<Tab, boolean>>(() => ({
  dir: store.hasPermission(TAB_PERMS.dir),
  doctype: store.hasPermission(TAB_PERMS.doctype),
  perm: store.hasPermission(TAB_PERMS.perm),
}))
const visibleTabs = computed<Tab[]>(() => TAB_KEYS.filter((tab) => canTab.value[tab]))
const activeTab = ref<Tab>(visibleTabs.value[0] ?? 'dir')
watch(visibleTabs, (tabs) => {
  if (!tabs.includes(activeTab.value)) activeTab.value = tabs[0] ?? 'dir'
})

const spaceId = computed(() => wiki.adminSpaceId)
const currentSpaceName = computed(
  () => wiki.spaceName(Number(spaceId.value)) || wiki.currentSpaceName || '未选择',
)

// ---------- 目录管理 ----------
const loading = ref(false)
const busy = ref(0)
const rows = ref<FlatCategory[]>([])
const editingId = ref(0)
const editingName = ref('')
const movingId = ref(0)
const moveParentId = ref<number | ''>('')

// 目录默认只显示 L1，由用户手动展开；刷新/改结构后按 id 保留展开状态
const expandedDirIds = ref<number[]>([])
// 「调整位置」浮层使用独立的展开状态，每次打开都回到 L1
const moveExpandedIds = ref<number[]>([])
const moveTargetRows = ref<FlatCategory[]>([])

const rowById = computed(() => new Map(rows.value.map((item) => [item.id, item])))

function isDirExpanded(id: number): boolean {
  return expandedDirIds.value.includes(id)
}
function toggleDir(id: number): void {
  expandedDirIds.value = isDirExpanded(id)
    ? expandedDirIds.value.filter((x) => x !== id)
    : [...expandedDirIds.value, id]
}
function hasDirChildren(row: FlatCategory): boolean {
  return rows.value.some((item) => item.parent_id === row.id)
}
function isMoveExpanded(id: number): boolean {
  return moveExpandedIds.value.includes(id)
}
function toggleMove(id: number): void {
  moveExpandedIds.value = isMoveExpanded(id)
    ? moveExpandedIds.value.filter((x) => x !== id)
    : [...moveExpandedIds.value, id]
}
function hasMoveChildren(row: FlatCategory): boolean {
  return moveTargetRows.value.some((item) => item.parent_id === row.id)
}

/** 深层目录可见 ⇔ 其所有祖先都已展开（用 parent_id 反查，seen 防环） */
function ancestorsExpanded(row: FlatCategory, expanded: number[]): boolean {
  let pid = row.parent_id
  const seen = new Set<number>()
  while (pid && !seen.has(pid)) {
    seen.add(pid)
    if (!expanded.includes(pid)) return false
    pid = rowById.value.get(pid)?.parent_id ?? 0
  }
  return true
}

const visibleRows = computed(() =>
  rows.value.filter((row) => row.depth === 0 || ancestorsExpanded(row, expandedDirIds.value)),
)
const moveOptions = computed(() =>
  moveTargetRows.value.filter((row) => row.depth === 0 || ancestorsExpanded(row, moveExpandedIds.value)),
)

async function load(force = false): Promise<void> {
  if (!canTab.value.dir) {
    rows.value = []
    return
  }
  const id = Number(spaceId.value)
  if (!id) {
    rows.value = []
    return
  }
  loading.value = true
  try {
    await wiki.loadAdminCategories(id, force)
    rows.value = flattenCategories(sortSystemLast(wiki.adminCategories[id] ?? []))
    // 目录被删除后清掉其展开状态，避免状态无限累积
    const alive = new Set(rows.value.map((item) => item.id))
    expandedDirIds.value = expandedDirIds.value.filter((x) => alive.has(x))
  } catch (error) {
    rows.value = []
    ElMessage.error(error instanceof Error ? error.message : '目录加载失败')
  } finally {
    loading.value = false
  }
}

async function reload(): Promise<void> {
  const id = Number(spaceId.value)
  if (id) wiki.invalidateCategories(id)
  await load(true)
}

async function run(id: number, task: () => Promise<void>): Promise<void> {
  busy.value = id
  try {
    await task()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '操作失败，请稍后重试')
  } finally {
    busy.value = 0
  }
}

function startEdit(row: FlatCategory): void {
  editingId.value = row.id
  editingName.value = row.name
  movingId.value = 0
}
function cancelEdit(): void {
  editingId.value = 0
  editingName.value = ''
}
async function saveName(row: FlatCategory): Promise<void> {
  const value = editingName.value.trim()
  if (!value || value.length > CATEGORY_NAME_MAX_LENGTH) {
    ElMessage.warning(`目录名称需为 1 ~ ${CATEGORY_NAME_MAX_LENGTH} 个字`)
    return
  }
  if (value === SYSTEM_CATEGORY_NAME) {
    ElMessage.warning('「未指定目录」是系统保留名称')
    return
  }
  const duplicated = rows.value.some(
    (item) =>
      item.id !== row.id &&
      item.parent_id === row.parent_id &&
      item.name.trim().toLowerCase() === value.toLowerCase(),
  )
  if (duplicated) {
    ElMessage.warning('该层级下已存在同名目录')
    return
  }
  await run(row.id, async () => {
    await categoryApi.update(row.id, { name: value })
    cancelEdit()
    await reload()
    ElMessage.success('目录已重命名')
  })
}

function subtreeHeight(id: number): number {
  let max = 1
  const walk = (pid: number, depth: number): void => {
    for (const child of rows.value.filter((item) => item.parent_id === pid)) {
      max = Math.max(max, depth + 1)
      walk(child.id, depth + 1)
    }
  }
  walk(id, 1)
  return max
}

function parentOptions(row: FlatCategory): FlatCategory[] {
  const excluded = new Set<number>([row.id])
  let changed = true
  while (changed) {
    changed = false
    for (const item of rows.value) {
      if (excluded.has(item.parent_id) && !excluded.has(item.id)) {
        excluded.add(item.id)
        changed = true
      }
    }
  }
  const height = subtreeHeight(row.id)
  return rows.value.filter(
    (item) =>
      !excluded.has(item.id) &&
      canAddChildAt(item.depth) &&
      item.depth + height <= CATEGORY_MAX_LEVEL - 1,
  )
}

function startMove(row: FlatCategory): void {
  movingId.value = row.id
  moveParentId.value = row.parent_id === 0 ? '' : row.parent_id
  moveTargetRows.value = parentOptions(row)
  moveExpandedIds.value = []
  editingId.value = 0
}
function cancelMove(): void {
  movingId.value = 0
  moveTargetRows.value = []
  moveExpandedIds.value = []
}
async function saveMove(row: FlatCategory): Promise<void> {
  const parentId = Number(moveParentId.value || 0)
  if (parentId === row.parent_id) {
    cancelMove()
    return
  }
  await run(row.id, async () => {
    await categoryApi.update(row.id, { parent_id: parentId })
    cancelMove()
    await reload()
    ElMessage.success('位置已调整，子目录与文章随目录整体迁移')
  })
}

function siblingsOf(row: FlatCategory): FlatCategory[] {
  return rows.value.filter((item) => item.parent_id === row.parent_id && !isSystemCategory(item))
}
function canMoveUp(row: FlatCategory): boolean {
  return siblingsOf(row).findIndex((item) => item.id === row.id) > 0
}
function canMoveDown(row: FlatCategory): boolean {
  const list = siblingsOf(row)
  const index = list.findIndex((item) => item.id === row.id)
  return index >= 0 && index < list.length - 1
}
async function reorder(row: FlatCategory, direction: -1 | 1): Promise<void> {
  const siblings = siblingsOf(row)
  const index = siblings.findIndex((item) => item.id === row.id)
  const neighbor = siblings[index + direction]
  if (!neighbor) return
  const nextSort =
    direction === -1
      ? Math.max(row.sort ?? 0, neighbor.sort ?? 0) + 1
      : Math.min(row.sort ?? 0, neighbor.sort ?? 0) - 1
  await run(row.id, async () => {
    await categoryApi.update(row.id, { sort: nextSort })
    await reload()
  })
}

async function remove(row: FlatCategory): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `删除「${row.name}」后，其下的子目录与文章会直接移至「未指定目录」，不会被删除。确定继续吗？`,
      '删除目录',
      { type: 'warning', confirmButtonText: '删除并迁移', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  await run(row.id, async () => {
    await categoryApi.remove(row.id)
    await reload()
    ElMessage.success('已删除，子目录与文章已移至「未指定目录」')
  })
}

// ---------- 文档类型管理 ----------
/** 授权对象显示名缓存：key = `${subject_type}:${subject_id}`，列表与弹窗共用 */
const subjectNameMap = ref<Record<string, string>>({})

function subjectKey(s: { subject_type: string; subject_id: number | string }): string {
  return `${s.subject_type}:${Number(s.subject_id)}`
}

/** 展示名：目录命中用真名，否则回落到 #ID（目录接口不可用时） */
function subjectLabel(s: { subject_type: string; subject_id: number | string; name?: string }): string {
  return subjectNameMap.value[subjectKey(s)] || s.name || `#${s.subject_id}`
}

/** 批量按 ID 解析授权对象名称，沿用项目现有 directoryApi.resolve 模式 */
async function resolveSubjectNames(list: DocType[]): Promise<void> {
  const wanted: Record<SubjectType, number[]> = { role: [], user: [] }
  for (const dt of list) {
    for (const s of dt.subjects ?? []) {
      if (!subjectNameMap.value[subjectKey(s)]) {
        wanted[s.subject_type].push(Number(s.subject_id))
      }
    }
  }
  for (const type of ['role', 'user'] as SubjectType[]) {
    const ids = [...new Set(wanted[type])]
    if (!ids.length) continue
    try {
      const res = await directoryApi.resolve(type, ids)
      for (const sub of res.data?.subjects ?? []) {
        subjectNameMap.value[subjectKey(sub)] = sub.name
      }
    } catch {
      // 目录接口不可用时保留 #ID 兜底
    }
  }
}

const docLoading = ref(false)
const docBusy = ref(0)
const docTypes = ref<DocType[]>([])
const docDialog = ref(false)
const dtEditingId = ref(0)
const dtName = ref('')
const dtVisibility = ref<DocType['visibility']>('space')
const dtSubjects = ref<Array<DocTypeSubject & { name?: string }>>([])
const dtPickType = ref<SubjectType>('role')
const dtSaving = ref(false)

const VISIBILITY_LABEL: Record<string, string> = {
  public: '全员可见',
  space: '空间内可见',
  restricted: '指定范围',
}

async function loadDocTypes(force = false): Promise<void> {
  if (!canTab.value.doctype) {
    docTypes.value = []
    return
  }
  const id = Number(spaceId.value)
  if (!id) {
    docTypes.value = []
    return
  }
  docLoading.value = true
  try {
    const res = await docTypeApi.list(id)
    docTypes.value = res.data?.doc_types ?? []
    await resolveSubjectNames(docTypes.value)
  } catch {
    docTypes.value = []
  } finally {
    docLoading.value = false
  }
}

const permRefreshSeq = ref(0)

async function refreshAll(): Promise<void> {
  permRefreshSeq.value += 1
  await Promise.all([load(true), loadDocTypes(true)])
}

function openDtCreate(): void {
  dtEditingId.value = 0
  dtName.value = ''
  dtVisibility.value = 'space'
  dtSubjects.value = []
  docDialog.value = true
}
async function openDtEdit(row: DocType): Promise<void> {
  dtEditingId.value = row.id
  dtName.value = row.name
  dtVisibility.value = row.visibility
  dtSubjects.value = (row.subjects ?? []).map((s) => ({
    subject_type: s.subject_type,
    subject_id: Number(s.subject_id),
  }))
  docDialog.value = true
  await resolveDtSubjects()
}
function addDtSubject(option: { subject_type: SubjectType; subject_id: number; name: string }): void {
  const id = Number(option.subject_id)
  if (!id || id <= 0) return
  if (
    dtSubjects.value.some(
      (s) => s.subject_type === option.subject_type && Number(s.subject_id) === id,
    )
  ) {
    ElMessage.info('该对象已在名单中')
    return
  }
  subjectNameMap.value[subjectKey({ subject_type: option.subject_type, subject_id: id })] = option.name
  dtSubjects.value.push({ subject_type: option.subject_type, subject_id: id, name: option.name })
}
async function resolveDtSubjects(): Promise<void> {
  for (const type of ['role', 'user'] as SubjectType[]) {
    const ids = [...new Set(dtSubjects.value.filter((s) => s.subject_type === type).map((s) => Number(s.subject_id)))]
    if (!ids.length) continue
    try {
      const res = await directoryApi.resolve(type, ids)
      for (const sub of res.data?.subjects ?? []) {
        const hit = dtSubjects.value.find(
          (s) => s.subject_type === sub.subject_type && Number(s.subject_id) === Number(sub.subject_id),
        )
        if (hit) hit.name = sub.name
        subjectNameMap.value[subjectKey(sub)] = sub.name
      }
    } catch {
      // 目录不可用时保留 #ID 展示
    }
  }
}
async function saveDt(): Promise<void> {
  const value = dtName.value.trim()
  if (!value || value.length > CATEGORY_NAME_MAX_LENGTH) {
    ElMessage.warning(`文档类型名称需为 1 ~ ${CATEGORY_NAME_MAX_LENGTH} 个字`)
    return
  }
  const id = Number(spaceId.value)
  if (!id) return
  const subjects =
    dtVisibility.value === 'restricted'
      ? dtSubjects.value.map((s) => ({ subject_type: s.subject_type, subject_id: Number(s.subject_id) }))
      : []
  dtSaving.value = true
  const payload = { space_id: id, name: value, visibility: dtVisibility.value, subjects }
  try {
    if (dtEditingId.value) {
      await docTypeApi.update(dtEditingId.value, payload)
      ElMessage.success('已保存')
    } else {
      await docTypeApi.create(payload)
      ElMessage.success('已创建')
    }
    docDialog.value = false
    await loadDocTypes(true)
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '保存失败')
  } finally {
    dtSaving.value = false
  }
}
async function removeDt(row: DocType): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `删除文档类型「${row.name}」后，已使用该类型的文章仍保留原文，但其分类标记会变为空。确定继续吗？`,
      '删除文档类型',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  docBusy.value = row.id
  try {
    await docTypeApi.remove(row.id)
    ElMessage.success('已删除')
    await loadDocTypes(true)
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '删除失败')
  } finally {
    docBusy.value = 0
  }
}

watch(spaceId, () => {
  cancelEdit()
  cancelMove()
  expandedDirIds.value = []
  subjectNameMap.value = {}
  void load(true)
  void loadDocTypes(true)
})

onMounted(async () => {
  try {
    await Promise.all([
      wiki.loadAdminSpaces().catch(() => undefined),
      wiki.loadSpaces().catch(() => undefined),
    ])
  } finally {
    // 忽略加载异常，由下方内容区兜底
  }
  if (spaceId.value) {
    await load()
    await loadDocTypes()
  }
})
</script>

<template>
  <div class="page-max cfg-page">
    <div class="toolbar rise d1 cfg-toolbar">
      <div>
        <h1 class="cfg-title">配置管理</h1>
        <p class="cfg-sub">
          管理当前空间的知识库配置。目录（结构）、文档类型（文章分类 + 默认可见范围）、权限相互独立。
        </p>
      </div>
      <div class="cfg-toolbar-right">
        <div class="cfg-space-chip">当前空间 · <b>{{ currentSpaceName }}</b></div>
        <button type="button" class="btn" :disabled="loading || docLoading || !visibleTabs.length" @click="refreshAll">
          <RefreshCw :size="14" />刷新
        </button>
      </div>
    </div>

    <div v-if="!visibleTabs.length" class="cfg-empty rise d2">当前账号没有配置管理权限。</div>

    <div v-else-if="!spaceId" class="cfg-empty rise d2">请从顶栏选择一个空间后再进行配置。</div>

    <div v-else class="cfg-layout">
      <aside class="cfg-nav">
        <div class="cfg-nav-cap">配置项</div>
        <div v-if="canTab.dir" class="cfg-nav-item" :class="{ active: activeTab === 'dir' }" @click="activeTab = 'dir'">
          <Folder :size="16" /> 目录管理
        </div>
        <div v-if="canTab.doctype" class="cfg-nav-item" :class="{ active: activeTab === 'doctype' }" @click="activeTab = 'doctype'">
          <Tag :size="16" /> 文档类型
        </div>
        <div v-if="canTab.perm" class="cfg-nav-item" :class="{ active: activeTab === 'perm' }" @click="activeTab = 'perm'">
          <Lock :size="16" /> 权限管理
        </div>
      </aside>

      <div class="cfg-panel">
        <!-- 目录管理 -->
        <template v-if="activeTab === 'dir' && canTab.dir">
          <div class="cfg-notice rise d2">
            <b>结构规则</b>
            <span>
              ① 调整位置后，子目录与其下文章随目录整体迁移到新结构；
              ② 删除目录时，子目录与文章直接移至「未指定目录」；
              ③ 目录最多 L{{ CATEGORY_MAX_LEVEL }}；
              ④ 「未指定目录」不可改名、移动、排序或删除；
              ⑤ 目录默认只显示一级，点击左侧箭头逐层展开；「调整位置」界面同样默认只显示一级。
            </span>
          </div>

          <div class="cfg-card rise d2">
            <div class="cfg-head">
              <span>目录列表 · {{ rows.length }} 个</span>
              <span v-if="loading" class="cfg-loading">加载中…</span>
            </div>

            <template v-for="row in visibleRows" :key="row.id">
              <div class="cfg-row" :class="{ system: isSystemCategory(row) }">
                <span class="cfg-indent" :style="{ width: row.depth * 18 + 'px' }" aria-hidden="true"></span>
                <button
                  v-if="hasDirChildren(row)"
                  type="button"
                  class="dir-caret"
                  :class="{ open: isDirExpanded(row.id) }"
                  :aria-label="isDirExpanded(row.id) ? `收起 ${row.name}` : `展开 ${row.name}`"
                  :aria-expanded="isDirExpanded(row.id)"
                  @click="toggleDir(row.id)"
                >
                  <ChevronRight :size="13" />
                </button>
                <span v-else class="dir-caret-placeholder" aria-hidden="true"></span>
                <Folder :size="15" class="cfg-folder" />
                <div class="cfg-name">
                  <template v-if="editingId === row.id">
                    <input
                      v-model="editingName"
                      class="input cfg-input"
                      type="text"
                      :maxlength="CATEGORY_NAME_MAX_LENGTH"
                      :aria-label="`重命名 ${row.name}`"
                      @keydown.enter="saveName(row)"
                      @keydown.esc="cancelEdit"
                    />
                    <button type="button" class="btn cfg-btn" :disabled="busy !== 0" @click="saveName(row)">保存</button>
                    <button type="button" class="btn cfg-btn" @click="cancelEdit">取消</button>
                  </template>
                  <template v-else>
                    <span class="cfg-name-text">{{ row.name }}</span>
                    <span v-if="isSystemCategory(row)" class="cat-system-tag">系统默认</span>
                  </template>
                </div>
                <span class="cfg-level">L{{ row.depth + 1 }}</span>
                <span class="cfg-count">{{ row.article_count ?? 0 }} 篇</span>
                <div class="cfg-ops">
                  <span v-if="isSystemCategory(row)" class="cfg-locked"><Lock :size="12" /> 不可调整</span>
                  <template v-else>
                    <button type="button" class="btn cfg-btn" :disabled="busy !== 0" @click="startEdit(row)">
                      <Pencil :size="12" />改名
                    </button>
                    <button type="button" class="btn cfg-btn" :disabled="busy !== 0" @click="startMove(row)">
                      <Move :size="12" />调整位置
                    </button>
                    <button
                      type="button"
                      class="btn cfg-btn cfg-icon-btn"
                      :disabled="busy !== 0 || !canMoveUp(row)"
                      title="上移"
                      aria-label="上移"
                      @click="reorder(row, -1)"
                    >
                      <ArrowUp :size="12" />
                    </button>
                    <button
                      type="button"
                      class="btn cfg-btn cfg-icon-btn"
                      :disabled="busy !== 0 || !canMoveDown(row)"
                      title="下移"
                      aria-label="下移"
                      @click="reorder(row, 1)"
                    >
                      <ArrowDown :size="12" />
                    </button>
                    <button type="button" class="btn cfg-btn cfg-danger" :disabled="busy !== 0" @click="remove(row)">
                      <Trash2 :size="12" />删除
                    </button>
                  </template>
                </div>
              </div>

              <div v-if="movingId === row.id" class="cfg-move">
                <div class="cfg-move-title">把「{{ row.name }}」调整到：（默认显示一级目录，点击箭头展开子目录）</div>
                <div class="cfg-move-list">
                  <label class="cfg-move-opt">
                    <input v-model="moveParentId" type="radio" :value="''" />
                    <span>作为一级目录（空间根目录）</span>
                  </label>
                  <label v-for="opt in moveOptions" :key="opt.id" class="cfg-move-opt">
                    <input v-model="moveParentId" type="radio" :value="opt.id" />
                    <span class="cfg-move-indent" :style="{ width: opt.depth * 16 + 'px' }"></span>
                    <button
                      v-if="hasMoveChildren(opt)"
                      type="button"
                      class="dir-caret small"
                      :class="{ open: isMoveExpanded(opt.id) }"
                      :aria-label="isMoveExpanded(opt.id) ? `收起 ${opt.name}` : `展开 ${opt.name}`"
                      :aria-expanded="isMoveExpanded(opt.id)"
                      @click.stop.prevent="toggleMove(opt.id)"
                    >
                      <ChevronRight :size="12" />
                    </button>
                    <span v-else class="dir-caret-placeholder small" aria-hidden="true"></span>
                    <span>{{ opt.name }}</span>
                    <span class="cfg-level">L{{ opt.depth + 1 }}</span>
                    <span v-if="isSystemCategory(opt)" class="cat-system-tag">系统默认</span>
                  </label>
                  <div v-if="!moveTargetRows.length" class="cfg-move-hint">
                    没有可用的目标层级（移动后不能超过 L{{ CATEGORY_MAX_LEVEL }}）
                  </div>
                </div>
                <div class="cfg-move-foot">
                  <span>子目录与文章将随该目录整体迁移，无需单独调整。</span>
                  <button type="button" class="btn cfg-btn" @click="cancelMove">取消</button>
                  <button type="button" class="btn cfg-btn btn-primary" :disabled="busy !== 0" @click="saveMove(row)">
                    保存位置
                  </button>
                </div>
              </div>
            </template>

            <div v-if="!loading && !rows.length" class="cfg-empty">该空间暂无目录。</div>
          </div>
        </template>

        <!-- 文档类型管理 -->
        <template v-else-if="activeTab === 'doctype' && canTab.doctype">
          <div class="cfg-card rise d2">
            <div class="cfg-head">
              <span>文档类型 · {{ docTypes.length }} 个</span>
              <button type="button" class="btn btn-primary" @click="openDtCreate">
                <Tag :size="14" /> 新增文档类型
              </button>
            </div>
            <div class="cfg-desc">
              文章创建时「分类」下拉读取此处配置的文档类型（与目录相互独立）。可为每个类型设置<b>默认可见范围</b>；
              新建文章选择该类型时，会自动带入可见名单并切换可见范围。
            </div>
            <table class="table doc-type-table">
              <thead>
                <tr>
                  <th>文档类型</th>
                  <th>默认可见范围</th>
                  <th>更新时间</th>
                  <th style="width: 168px">操作</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="dt in docTypes" :key="dt.id">
                  <td class="dt-name">
                    {{ dt.name }}
                    <span v-if="dt.is_default" class="dt-default">默认</span>
                  </td>
                  <td>
                    <span v-if="dt.visibility === 'public'" class="dt-vis"><Eye :size="13" />全员可见</span>
                    <span v-else-if="dt.visibility === 'space'" class="dt-vis"><Home :size="13" />空间内可见</span>
                    <span v-else class="dt-vis">
                      <Lock :size="13" />指定范围
                      <span v-if="dt.subjects && dt.subjects.length" class="dt-subjects">
                        <span
                          v-for="(s, i) in dt.subjects"
                          :key="i"
                          :class="s.subject_type === 'role' ? 'tag-role' : 'tag-user'"
                        >
                          {{ s.subject_type === 'role' ? '角色' : '人员' }} · {{ subjectLabel(s) }}
                        </span>
                      </span>
                    </span>
                  </td>
                  <td class="dt-time">{{ dt.updated_at }}</td>
                  <td>
                    <div class="dt-ops">
                      <button type="button" class="btn cfg-btn" :disabled="docBusy !== 0" @click="openDtEdit(dt)">
                        <Pencil :size="12" />编辑
                      </button>
                      <button type="button" class="btn cfg-btn cfg-danger" :disabled="docBusy !== 0" @click="removeDt(dt)">
                        <Trash2 :size="12" />删除
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!docLoading && !docTypes.length">
                  <td colspan="4" class="cfg-empty">该空间暂无文档类型，点击右上角新增。</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <!-- 权限配置：按人员，作用于当前空间（整包覆盖全局角色配置） -->
        <template v-else-if="activeTab === 'perm' && canTab.perm">
          <PermissionConfig mode="user-space" :space-id="Number(spaceId) || 0" :refresh-seq="permRefreshSeq" />
        </template>
        <div v-else class="cfg-empty rise d2">当前账号没有该配置项的操作权限。</div>
      </div>
    </div>

    <el-dialog
      v-model="docDialog"
      :title="dtEditingId ? '编辑文档类型' : '新增文档类型'"
      width="540px"
    >
      <div class="field">
        <label>名称</label>
        <input v-model="dtName" class="input" maxlength="15" placeholder="如 职能文档 / 项目文档 / 会议纪要" />
      </div>
      <div class="field">
        <label>默认可见范围</label>
        <select v-model="dtVisibility" class="select" style="height: 38px">
          <option value="public">全员可见</option>
          <option value="space">空间内可见</option>
          <option value="restricted">指定范围</option>
        </select>
      </div>
      <div v-if="dtVisibility === 'restricted'" class="field">
        <label>授权对象（角色 + 人员）</label>
        <div class="grants">
          <span
            v-for="(s, i) in dtSubjects"
            :key="i"
            class="tag"
            :class="s.subject_type === 'role' ? 'role' : 'user'"
          >
            {{ s.subject_type === 'role' ? '角色' : '人员' }} · {{ subjectLabel(s) }}
            <button type="button" class="tag-x" @click="dtSubjects.splice(i, 1)">✕</button>
          </span>
        </div>
        <div class="grant-add">
          <select v-model="dtPickType" class="select" style="width: 120px; height: 38px">
            <option value="role">按角色</option>
            <option value="user">按人员</option>
          </select>
          <KbSubjectPicker
            :type="dtPickType"
            :selected-ids="dtSubjects.map((s) => s.subject_id)"
            @select="addDtSubject"
          />
        </div>
      </div>
      <template #footer>
        <button type="button" class="btn" @click="docDialog = false">取消</button>
        <button type="button" class="btn btn-primary" :disabled="dtSaving" @click="saveDt">保存</button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.dir-caret {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  padding: 0;
  border: 0;
  border-radius: 4px;
  background: transparent;
  color: var(--text-3);
  cursor: pointer;
}
.dir-caret:hover {
  background: #eef3ff;
  color: var(--primary);
}
.dir-caret svg {
  transition: transform 0.18s ease;
}
.dir-caret.open svg {
  transform: rotate(90deg);
}
.dir-caret.small,
.dir-caret-placeholder.small {
  width: 14px;
  height: 14px;
}
.dir-caret-placeholder {
  flex: none;
  width: 18px;
}
/* 目录行按钮较多，禁止被压缩导致「删除」等文案换行 */
.cfg-ops .btn {
  flex: none;
  white-space: nowrap;
}
.cfg-layout {
  display: grid;
  grid-template-columns: 208px 1fr;
  gap: 18px;
  align-items: start;
}
.cfg-nav {
  position: sticky;
  top: 14px;
  background: var(--card, #fff);
  border: 1px solid var(--line, #e7eaf2);
  border-radius: 14px;
  padding: 10px;
  box-shadow: 0 2px 12px rgba(22, 33, 64, 0.05);
}
.cfg-nav-cap {
  font-size: 11px;
  color: var(--text-3, #9aa3b5);
  padding: 6px 8px 8px;
  letter-spacing: 1px;
}
.cfg-nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 14px;
  color: var(--text-2, #5b6577);
  cursor: pointer;
  margin-bottom: 2px;
}
.cfg-nav-item:hover {
  background: #f1f4fb;
}
.cfg-nav-item.active {
  background: var(--primary-weak, #eef3ff);
  color: var(--primary, #3b6cff);
  font-weight: 600;
}
.cfg-space-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border: 1px solid var(--line, #e7eaf2);
  border-radius: 999px;
  background: #fff;
  font-size: 12px;
  color: var(--text-2, #5b6577);
}
.cfg-space-chip b {
  color: var(--primary, #3b6cff);
  font-weight: 600;
}
.cfg-desc {
  background: #f8faff;
  border: 1px solid var(--primary-line, #d6e2ff);
  border-radius: 10px;
  padding: 12px 14px;
  font-size: 12.5px;
  line-height: 1.7;
  color: var(--text-2, #5b6577);
  margin-bottom: 14px;
}
.cfg-desc b {
  color: var(--primary);
  font-weight: 600;
}
/* 文档类型列表：字体体系统一对齐同页「目录管理」的 .cfg-* 规范（12~13px） */
.doc-type-table th {
  padding: 10px 14px;
  text-align: left;
  white-space: nowrap;
  font-size: 12px;
  font-weight: 500;
  color: var(--text-3);
  background: #fafbfe;
  border-bottom: 1px solid var(--line);
}
.doc-type-table td {
  padding: 10px 14px;
  font-size: 13px;
  color: var(--text);
  border-bottom: 1px solid var(--line);
  vertical-align: middle;
}
.doc-type-table tr:last-child td {
  border-bottom: 0;
}
.doc-type-table tr:hover td {
  background: rgba(43, 92, 230, 0.025);
}
.dt-name {
  font-size: 13px;
  font-weight: 500;
  color: var(--text);
}
.dt-default {
  margin-left: 6px;
  font-size: 11px;
  color: var(--text-3);
  background: #f0f2f7;
  padding: 1px 7px;
  border-radius: 999px;
}
.dt-vis {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  flex-wrap: wrap;
  font-size: 13px;
  color: var(--text-2);
}
.dt-time {
  font-size: 12px;
  color: var(--text-3);
  white-space: nowrap;
}
.dt-ops {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: nowrap;
}
.dt-ops .btn {
  flex: none;
  white-space: nowrap;
}
.dt-subjects {
  display: inline-flex;
  gap: 6px;
  margin-left: 4px;
  flex-wrap: wrap;
}
.cfg-placeholder {
  text-align: center;
  padding: 60px 20px;
  color: var(--text-3, #9aa3b5);
}
.cfg-placeholder .ph-icon {
  color: var(--primary, #3b6cff);
  opacity: 0.5;
  margin-bottom: 12px;
}
.cfg-placeholder h3 {
  color: var(--text, #1f2733);
  margin-bottom: 8px;
}
.cfg-placeholder p {
  font-size: 13px;
  max-width: 420px;
  margin: 0 auto;
  line-height: 1.7;
}
.grants {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  min-height: 42px;
  border: 1px solid var(--line, #e7eaf2);
  border-radius: 10px;
  padding: 10px;
}
.grants .tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 12px;
  background: var(--tag-u, #eafaf1);
  color: var(--tag-u-c, #1f9d57);
}
.grants .tag.role {
  background: var(--tag-r, #eef1ff);
  color: var(--tag-r-c, #5566ff);
}
.tag-x {
  border: 0;
  background: transparent;
  cursor: pointer;
  color: inherit;
  opacity: 0.6;
  font-size: 12px;
}
.tag-x:hover {
  opacity: 1;
}
.grant-add {
  display: flex;
  gap: 10px;
  margin-top: 10px;
  align-items: center;
}
</style>
