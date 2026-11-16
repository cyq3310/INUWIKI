<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ChevronDown, ChevronRight, Folder, Layers, Search } from 'lucide-vue-next'
import type { CategoryNode } from '../types'
import {
  categoryTrail,
  flattenCategories,
  isSystemCategory,
  resolveCategoryTrail,
  sortSystemLast,
  systemCategoryId,
} from '../utils/tree'
import type { FlatCategory } from '../utils/tree'

/**
 * 所属目录选择器：默认只显示 L1，点选目录后若有子目录则展开，可逐层下钻。
 * 面包屑显示完整归属，L1/L2 徽标让层级不只靠缩进表达；搜索时临时展开命中项的祖先链。
 */
const props = withDefaults(
  defineProps<{
    modelValue: number | ''
    categories: CategoryNode[]
    spaceName?: string
    loading?: boolean
    disabled?: boolean
    triggerId?: string
  }>(),
  { spaceName: '', loading: false, disabled: false, triggerId: 'cat-picker-trigger' },
)

const emit = defineEmits<{ (e: 'update:modelValue', value: number | ''): void }>()

interface VisibleRow {
  id: number
  name: string
  depth: number
  article_count: number
  hasChildren: boolean
  isSystem: boolean
}

const root = ref<HTMLElement | null>(null)
const triggerEl = ref<HTMLButtonElement | null>(null)
const open = ref(false)
const keyword = ref('')

const ordered = computed(() => sortSystemLast(props.categories))
const flat = computed(() => flattenCategories(ordered.value))
/** 树里已存在系统默认目录时，不再额外渲染「不指定目录」占位项 */
const hasSystem = computed(() => systemCategoryId(ordered.value) !== 0)
/** 组件内部选中状态：点击后立即更新，不依赖父级 v-model 的回传时机 */
const localId = ref<number | ''>(props.modelValue === '' ? '' : Number(props.modelValue))
const displayTrail = ref<FlatCategory[]>([])
watch(
  () => props.modelValue,
  (value) => {
    localId.value = value === '' ? '' : Number(value)
  },
)
// 目录树异步加载 / 接口降级为空时，仍用点击当时记录的链路显示完整路径
const trail = computed(() =>
  resolveCategoryTrail(ordered.value, localId.value, displayTrail.value),
)

/** 菜单内展开的目录：打开时只恢复「已选目录的祖先」，其余 L1 以下全部收起 */
const expanded = ref<number[]>([])
const searching = computed(() => keyword.value.trim().length > 0)
const matches = computed(() => {
  const q = keyword.value.trim().toLowerCase()
  if (!q) return []
  return flat.value.filter((item) => item.name.toLowerCase().includes(q))
})
const ancestorIds = computed(() => {
  const set = new Set<number>()
  for (const item of matches.value) {
    for (const seg of categoryTrail(ordered.value, item.id).slice(0, -1)) {
      set.add(Number(seg.id))
    }
  }
  return set
})
const effectiveExpanded = computed(() => {
  const set = new Set(expanded.value.map(Number))
  for (const id of ancestorIds.value) set.add(id)
  return set
})

function collect(nodes: CategoryNode[], depth: number, out: VisibleRow[]): void {
  for (const node of nodes ?? []) {
    const id = Number(node.id)
    const children = node.children ?? []
    out.push({
      id,
      name: node.name,
      depth,
      article_count: Number(node.article_count ?? 0) || 0,
      hasChildren: children.length > 0,
      isSystem: isSystemCategory(node),
    })
    if (children.length && effectiveExpanded.value.has(id)) collect(children, depth + 1, out)
  }
}

const visible = computed(() => {
  const rows: VisibleRow[] = []
  collect(ordered.value, 0, rows)
  if (!searching.value) return rows
  // 搜索时只保留命中项与其祖先，深层结果仍能看清层级
  const hit = new Set(matches.value.map((item) => Number(item.id)))
  return rows.filter((row) => hit.has(row.id) || ancestorIds.value.has(row.id))
})

const isExpanded = (id: number): boolean => effectiveExpanded.value.has(Number(id))

function toggleExpand(id: number): void {
  const set = new Set(expanded.value.map(Number))
  const target = Number(id)
  if (set.has(target)) set.delete(target)
  else set.add(target)
  expanded.value = [...set]
}

function select(value: number | ''): void {
  const id = value === '' ? '' : Number(value)
  localId.value = id
  displayTrail.value = id === '' ? [] : categoryTrail(ordered.value, id)
  emit('update:modelValue', id)
}

function finish(): void {
  open.value = false
  triggerEl.value?.focus()
}

function pickRoot(): void {
  select('')
  finish()
}

/** 点选目录：有子目录时展开并保留菜单，方便继续往下选；叶子节点选完即关闭 */
function pickRow(row: VisibleRow): void {
  select(row.id)
  if (searching.value || !row.hasChildren) {
    finish()
    return
  }
  if (!expanded.value.some((id) => Number(id) === row.id)) {
    expanded.value = [...expanded.value, row.id]
  }
}

function toggle(): void {
  if (!props.disabled) open.value = !open.value
}
function onDocClick(event: MouseEvent): void {
  if (root.value && !root.value.contains(event.target as Node)) open.value = false
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    finish()
  } else if (event.key === 'ArrowDown' && !open.value) {
    event.preventDefault()
    open.value = true
  }
}

watch(open, (value) => {
  if (!value) return
  keyword.value = ''
  expanded.value = categoryTrail(ordered.value, localId.value)
    .slice(0, -1)
    .map((seg) => Number(seg.id))
})

onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <div ref="root" class="cat-picker">
    <button
      :id="props.triggerId"
      ref="triggerEl"
      type="button"
      class="cat-trigger"
      :class="{ disabled: props.disabled }"
      :disabled="props.disabled"
      :aria-expanded="open"
      aria-haspopup="listbox"
      aria-label="选择所属目录"
      @click="toggle"
      @keydown="onKeydown"
    >
      <span class="cat-trigger-icon"><Layers :size="15" /></span>
      <span class="cat-trigger-body">
        <span v-if="trail.length" class="cat-trail">
          <span v-for="(seg, index) in trail" :key="seg.id" class="cat-seg">
            <i v-if="index" aria-hidden="true">/</i>{{ seg.name }}
          </span>
        </span>
        <span v-else class="cat-placeholder">
          {{ props.loading ? '目录加载中…' : props.disabled ? '请先选择所属空间' : '请选择所属目录（默认：未指定目录）' }}
        </span>
      </span>
      <span v-if="trail.length" class="cat-level">L{{ trail.length }}</span>
      <ChevronDown :size="16" class="cat-caret" :class="{ open }" />
    </button>

    <div v-if="open" class="cat-menu" role="listbox" aria-label="所属目录列表" @keydown="onKeydown">
      <div class="cat-menu-head">
        <Search :size="14" />
        <input v-model="keyword" type="text" placeholder="搜索目录名称…" aria-label="搜索目录" />
      </div>
      <div class="cat-menu-body">
        <button
          v-if="!searching && !hasSystem"
          type="button"
          class="cat-option"
          :class="{ selected: localId === '' }"
          role="option"
          :aria-selected="localId === ''"
          @click="pickRoot"
        >
          <span class="cat-twisty-spacer" aria-hidden="true"></span>
          <span class="cat-branch" aria-hidden="true">·</span>
          <Folder :size="14" class="cat-option-icon" />
          <span class="cat-name">未指定目录</span>
          <span class="cat-hint">系统默认</span>
        </button>

        <button
          v-for="row in visible"
          :key="row.id"
          type="button"
          class="cat-option"
          :class="{ selected: localId === row.id }"
          role="option"
          :aria-selected="localId === row.id"
          @click="pickRow(row)"
        >
          <span class="cat-indent" :style="{ width: row.depth * 16 + 'px' }" aria-hidden="true"></span>
          <span
            v-if="row.hasChildren && !searching"
            class="cat-twisty"
            :class="{ expanded: isExpanded(row.id) }"
            role="button"
            tabindex="0"
            :aria-label="isExpanded(row.id) ? '收起子目录' : '展开子目录'"
            @click.stop="toggleExpand(row.id)"
            @keydown.enter.stop.prevent="toggleExpand(row.id)"
            @keydown.space.stop.prevent="toggleExpand(row.id)"
          >
            <ChevronRight :size="13" />
          </span>
          <span v-else class="cat-twisty-spacer" aria-hidden="true"></span>
          <Folder :size="14" class="cat-option-icon" />
          <span class="cat-name">{{ row.name }}</span>
          <span v-if="row.isSystem" class="cat-system-tag">系统默认</span>
          <span class="cat-count">{{ row.article_count }} 篇</span>
          <span class="cat-level">L{{ row.depth + 1 }}</span>
        </button>

        <div v-if="!visible.length" class="tree-hint">
          {{ searching ? '没有匹配的目录' : props.categories.length ? '暂无可显示的目录' : '当前空间暂无目录，可点击「新增目录」创建' }}
        </div>
      </div>
      <div class="cat-menu-foot">
        <span>
          {{
            searching
              ? `找到 ${matches.length} 个目录 · 点击即选中`
              : `共 ${flat.length} 个目录 · 默认只显示 L1，点目录展开下级`
          }}
        </span>
        <button type="button" class="cat-done" @click="finish">完成</button>
      </div>
    </div>
  </div>
</template>
