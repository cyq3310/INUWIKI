<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { ChevronRight, Folder, FolderPlus, X } from 'lucide-vue-next'
import { categoryApi } from '../api'
import {
  CATEGORY_MAX_LEVEL,
  CATEGORY_NAME_MAX_LENGTH,
  SYSTEM_CATEGORY_NAME,
  canAddChildAt,
  categoryTrail,
  flattenCategories,
  isSystemCategory,
  sortSystemLast,
} from '../utils/tree'
import type { CategoryNode } from '../types'

/**
 * 新增目录弹层：父目录用单选树选择层级（含「作为一级目录」）。
 * 默认只显示 L1：点目录行选中并展开下级，点箭头单独展开 / 收起，深层都由用户手动点开；
 * 若从所属目录带入了默认父级，打开时只预展开它的祖先链路（与所属目录下拉一致），其余分支仍收起。
 * 目录最多 L5（L5 不能再作为父级），名称最多 15 个字。
 * 用 Teleport 挂到 body，避免 .rise 的 transform 生成层叠上下文把弹层困在卡片里。
 */
const props = withDefaults(
  defineProps<{
    open: boolean
    spaceId: number | ''
    spaceName?: string
    categories: CategoryNode[]
    defaultParentId?: number | ''
  }>(),
  { spaceName: '当前空间', defaultParentId: '' },
)

const emit = defineEmits<{
  (e: 'update:open', value: boolean): void
  (e: 'created', id: number): void
}>()

interface ParentRow {
  id: number
  name: string
  parent_id: number
  depth: number
  /** 该行能否作为父目录（L5 是叶子层，不能再挂子目录） */
  selectable: boolean
  hasChildren: boolean
  isSystem: boolean
}

const name = ref('')
const parentId = ref<number | ''>('')
const saving = ref(false)
const submitError = ref('')
const nameInput = ref<HTMLInputElement | null>(null)
const parentList = ref<HTMLElement | null>(null)

/** 父目录树中已展开的节点；无默认父级时为空，即只显示 L1 */
const expandedParents = ref<number[]>([])
const ordered = computed(() => sortSystemLast(props.categories))
const flat = computed(() => flattenCategories(ordered.value))
const parentTrail = computed(() => categoryTrail(ordered.value, parentId.value))
const level = computed(() => parentTrail.value.length + 1)
const levelExceeded = computed(() => level.value > CATEGORY_MAX_LEVEL)
const previewSegments = computed(() => [
  props.spaceName || '当前空间',
  ...parentTrail.value.map((node) => node.name),
  name.value.trim() || '新目录名称',
])

function collectParents(nodes: CategoryNode[], depth: number, out: ParentRow[]): void {
  for (const node of nodes ?? []) {
    const children = node.children ?? []
    const id = Number(node.id)
    out.push({
      id,
      name: node.name,
      parent_id: Number(node.parent_id ?? 0),
      depth,
      selectable: canAddChildAt(depth),
      hasChildren: children.length > 0,
      isSystem: isSystemCategory(node),
    })
    if (children.length && expandedParents.value.includes(id)) {
      collectParents(children, depth + 1, out)
    }
  }
}

/** 当前可见的父目录行：只递归到已展开的节点 */
const parentOptions = computed<ParentRow[]>(() => {
  const rows: ParentRow[] = []
  collectParents(ordered.value, 0, rows)
  return rows
})

/** 后端不校验同名，前端按「同级」兜底，避免目录树出现重复分支 */
const duplicate = computed(() => {
  const value = name.value.trim().toLowerCase()
  if (!value) return false
  const pid = Number(parentId.value || 0)
  return flat.value.some(
    (item) => item.parent_id === pid && item.name.trim().toLowerCase() === value,
  )
})
/** 「未指定目录」是系统保留名，不允许再建同名目录 */
const reservedName = computed(() => name.value.trim() === SYSTEM_CATEGORY_NAME)
const canSubmit = computed(
  () =>
    !!props.spaceId &&
    !!name.value.trim() &&
    name.value.trim().length <= CATEGORY_NAME_MAX_LENGTH &&
    !reservedName.value &&
    !duplicate.value &&
    !levelExceeded.value &&
    !saving.value,
)

/** 默认父级可能已经是 L5（从目录选择器带进来），此时退回上一层，避免建出 L6 */
function safeDefaultParent(): number | '' {
  const raw = props.defaultParentId
  if (raw === '' || raw === null || raw === undefined) return ''
  const id = Number(raw)
  if (!Number.isFinite(id) || id <= 0) return ''
  const hit = flat.value.find((item) => Number(item.id) === id)
  if (!hit) return ''
  if (!canAddChildAt(hit.depth)) return hit.parent_id ? Number(hit.parent_id) : ''
  return id
}

/** 有默认父级时展开它的祖先链路，保证高亮项可见；其余分支保持收起 */
function defaultExpanded(id: number | ''): number[] {
  if (id === '') return []
  return categoryTrail(ordered.value, id)
    .slice(0, -1)
    .map((seg) => Number(seg.id))
}

const isExpanded = (id: number): boolean => expandedParents.value.includes(Number(id))
function toggleExpand(id: number): void {
  const target = Number(id)
  expandedParents.value = isExpanded(target)
    ? expandedParents.value.filter((item) => item !== target)
    : [...expandedParents.value, target]
}

/** 点目录行选中为父级；有子目录时顺带展开，方便继续往下选层级 */
function pickParent(row: ParentRow): void {
  parentId.value = row.id
  if (row.hasChildren && !isExpanded(row.id)) toggleExpand(row.id)
}

watch(
  () => props.open,
  async (value) => {
    if (!value) return
    name.value = ''
    parentId.value = safeDefaultParent()
    expandedParents.value = defaultExpanded(parentId.value)
    submitError.value = ''
    saving.value = false
    await nextTick()
    nameInput.value?.focus()
    // 默认父级可能位于长列表底部，滚动到可见处，否则「预览第 N 级」却看不到高亮项
    parentList.value
      ?.querySelector<HTMLElement>('.cat-parent-option.selected')
      ?.scrollIntoView({ block: 'nearest' })
  },
)

function close(): void {
  if (!saving.value) emit('update:open', false)
}

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  saving.value = true
  submitError.value = ''
  try {
    const res = await categoryApi.create({
      space_id: Number(props.spaceId),
      parent_id: Number(parentId.value || 0),
      name: name.value.trim(),
    })
    const id = Number(res.data?.id || 0)
    if (!id) {
      submitError.value = '服务端未返回目录 ID，请稍后重试'
      return
    }
    emit('created', id)
    emit('update:open', false)
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '创建失败，请稍后重试'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="props.open" class="cat-modal" @click.self="close" @keydown.esc="close">
      <div class="cat-dialog" role="dialog" aria-modal="true" aria-labelledby="cat-dialog-title">
        <header class="cat-dialog-head">
          <div class="cat-dialog-icon"><FolderPlus :size="18" /></div>
          <div class="cat-dialog-heading">
            <h3 id="cat-dialog-title">新增目录</h3>
            <p>先选择它挂在哪一层，创建后会自动选中该目录</p>
          </div>
          <button type="button" class="cat-dialog-close" aria-label="关闭" @click="close">
            <X :size="16" />
          </button>
        </header>

        <div class="cat-dialog-body">
          <div class="cat-step">
            <span class="cat-step-no">1</span>
            <b>选择所属层级</b>
            <span class="cat-step-tip">最多 {{ CATEGORY_MAX_LEVEL }} 级 · 逐级展开</span>
          </div>

          <div ref="parentList" class="cat-parent-list" role="radiogroup" aria-label="父目录层级">
            <button
              type="button"
              class="cat-parent-option"
              :class="{ selected: parentId === '' }"
              role="radio"
              :aria-checked="parentId === ''"
              @click="parentId = ''"
            >
              <span class="cat-radio" aria-hidden="true"></span>
              <span class="cat-twisty-spacer" aria-hidden="true"></span>
              <Folder :size="14" class="cat-option-icon" />
              <span class="cat-parent-name">作为一级目录</span>
              <span class="cat-level">L1</span>
            </button>
            <button
              v-for="item in parentOptions"
              :key="item.id"
              type="button"
              class="cat-parent-option"
              :class="{
                selected: parentId !== '' && Number(parentId) === Number(item.id),
                disabled: !item.selectable,
              }"
              role="radio"
              :aria-checked="parentId !== '' && Number(parentId) === Number(item.id)"
              :disabled="!item.selectable"
              :title="item.selectable ? `作为第 ${item.depth + 2} 级目录的父级` : 'L5 目录下不能再创建子目录'"
              @click="pickParent(item)"
            >
              <span class="cat-radio" aria-hidden="true"></span>
              <span class="cat-indent" :style="{ width: item.depth * 14 + 'px' }" aria-hidden="true"></span>
              <span
                v-if="item.hasChildren && item.selectable"
                class="cat-twisty"
                :class="{ expanded: isExpanded(item.id) }"
                role="button"
                tabindex="0"
                :aria-label="isExpanded(item.id) ? '收起子目录' : '展开子目录'"
                @click.stop="toggleExpand(item.id)"
                @keydown.enter.stop.prevent="toggleExpand(item.id)"
                @keydown.space.stop.prevent="toggleExpand(item.id)"
              >
                <ChevronRight :size="13" />
              </span>
              <span v-else class="cat-twisty-spacer" aria-hidden="true"></span>
              <Folder :size="14" class="cat-option-icon" />
              <span class="cat-parent-name">{{ item.name }}</span>
              <span v-if="item.isSystem" class="cat-system-tag">系统默认</span>
              <span v-if="!item.selectable" class="cat-limit">已达上限</span>
              <span class="cat-level">L{{ item.depth + 1 }}</span>
            </button>
            <div v-if="!flat.length" class="tree-hint">当前空间还没有目录，只能先创建一级目录</div>
          </div>

          <div class="cat-preview" aria-live="polite">
            <span class="cat-preview-label">将创建于</span>
            <span class="cat-preview-path">
              <template v-for="(seg, index) in previewSegments" :key="index">
                <i v-if="index" aria-hidden="true">/</i>{{ seg }}
              </template>
            </span>
            <span class="cat-level" :class="{ limit: levelExceeded }">
              第 {{ level }} 级 / 最多 L{{ CATEGORY_MAX_LEVEL }} 级
            </span>
          </div>

          <div class="cat-step">
            <span class="cat-step-no">2</span>
            <b>目录名称</b>
          </div>
          <input
            ref="nameInput"
            v-model="name"
            class="input cat-name-input"
            type="text"
            :maxlength="CATEGORY_NAME_MAX_LENGTH"
            placeholder="如：会议纪要"
            aria-label="目录名称"
            @input="submitError = ''"
          />
          <div class="cat-field-meta">
            <span v-if="levelExceeded" class="cat-error">
              目录最多 {{ CATEGORY_MAX_LEVEL }} 级，请改选更上层的父目录
            </span>
            <span v-else-if="reservedName" class="cat-error">「未指定目录」是系统保留名称，请换一个名称</span>
            <span v-else-if="duplicate" class="cat-error">该层级下已存在同名目录，请换一个名称</span>
            <span v-else-if="submitError" class="cat-error">{{ submitError }}</span>
            <span v-else class="cat-tip">
              同名会自动提示；最多 {{ CATEGORY_NAME_MAX_LENGTH }} 字 · {{ CATEGORY_MAX_LEVEL }} 级
            </span>
            <span>{{ name.length }}/{{ CATEGORY_NAME_MAX_LENGTH }}</span>
          </div>
        </div>

        <footer class="cat-dialog-foot">
          <span class="cat-foot-hint">需要「目录管理」权限</span>
          <button type="button" class="btn" :disabled="saving" @click="close">取消</button>
          <button type="button" class="btn btn-primary" :disabled="!canSubmit" @click="submit">
            {{ saving ? '创建中…' : '创建并选中' }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
