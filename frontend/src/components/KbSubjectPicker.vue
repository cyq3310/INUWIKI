<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ChevronDown, Search, User, Users } from 'lucide-vue-next'
import { directoryApi } from '../api'
import type { SubjectOption, SubjectType } from '../types'

/**
 * 角色 / 人员搜索选择器：输入名称或编码，从 WIKI 本地目录快照搜索，选中即添加授权。
 * 下拉 Teleport 到 body 并 fixed 定位，避免空间管理抽屉的 overflow / transform 裁剪。
 */
const props = withDefaults(
  defineProps<{
    type: SubjectType
    placeholder?: string
    disabled?: boolean
    /** 已授权的 ID，用于把候选项标为「已添加」并禁止重复选择 */
    selectedIds?: number[]
    /** 权限配置的人员选择场景：禁用超级管理员，避免误改其权限 */
    disableSuperAdmin?: boolean
  }>(),
  { placeholder: '', disabled: false, selectedIds: () => [], disableSuperAdmin: false },
)

const emit = defineEmits<{ (e: 'select', value: SubjectOption): void }>()

const root = ref<HTMLElement | null>(null)
const inputEl = ref<HTMLInputElement | null>(null)
const menuEl = ref<HTMLElement | null>(null)
const keyword = ref('')
const open = ref(false)
const loading = ref(false)
const error = ref('')
const options = ref<SubjectOption[]>([])
const activeIndex = ref(-1)
const menuStyle = ref<Record<string, string>>({})
const requestSeq = ref(0)
let timer: ReturnType<typeof setTimeout> | undefined
/** 选中动作会清空关键词并重新聚焦，期间抑制自动展开，避免下拉被立刻重新打开 */
let picking = false

async function defaultFetch(type: SubjectType, value: string): Promise<SubjectOption[]> {
  const res = await directoryApi.search(type, value)
  return res.data?.subjects ?? []
}

async function load(): Promise<void> {
  const seq = ++requestSeq.value
  loading.value = true
  error.value = ''
  try {
    const rows = await defaultFetch(props.type, keyword.value.trim())
    if (seq !== requestSeq.value) return
    options.value = rows
    activeIndex.value = rows.findIndex((row) => !isLocked(row))
  } catch {
    if (seq !== requestSeq.value) return
    options.value = []
    activeIndex.value = -1
    error.value = '目录暂不可用，请稍后重试'
  } finally {
    if (seq === requestSeq.value) loading.value = false
  }
}

function updatePosition(): void {
  const rect = inputEl.value?.getBoundingClientRect()
  if (!rect) return
  menuStyle.value = {
    left: `${Math.round(rect.left)}px`,
    top: `${Math.round(rect.bottom + 6)}px`,
    width: `${Math.max(Math.round(rect.width), 280)}px`,
  }
}

function openMenu(): void {
  if (props.disabled || picking) return
  open.value = true
  updatePosition()
  void load()
}
function closeMenu(): void {
  open.value = false
}
function toggle(): void {
  if (open.value) closeMenu()
  else {
    updatePosition()
    open.value = true
    void load()
  }
}

const isAdded = (option: SubjectOption): boolean =>
  props.selectedIds.some((id) => Number(id) === Number(option.subject_id))

const isSuperAdmin = (option: SubjectOption): boolean =>
  props.disableSuperAdmin && option.subject_type === 'user' && option.is_superadmin === true

const isLocked = (option: SubjectOption): boolean => isAdded(option) || isSuperAdmin(option)

function pick(option: SubjectOption): void {
  if (isLocked(option)) return
  picking = true
  clearTimeout(timer)
  requestSeq.value += 1
  loading.value = false
  error.value = ''
  emit('select', option)
  keyword.value = ''
  options.value = []
  activeIndex.value = -1
  closeMenu()
  inputEl.value?.focus()
  void nextTick(() => {
    picking = false
  })
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    closeMenu()
    return
  }
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    if (!open.value) {
      openMenu()
      return
    }
    if (options.value.length) {
      activeIndex.value = (activeIndex.value + 1) % options.value.length
    }
    return
  }
  if (event.key === 'ArrowUp') {
    event.preventDefault()
    if (options.value.length) {
      activeIndex.value = (activeIndex.value - 1 + options.value.length) % options.value.length
    }
    return
  }
  if (event.key === 'Enter') {
    event.preventDefault()
    if (!open.value) {
      openMenu()
      return
    }
    const option = options.value[activeIndex.value]
    if (option) pick(option)
  }
}

function onDocClick(event: MouseEvent): void {
  const target = event.target as Node
  if (root.value?.contains(target) || menuEl.value?.contains(target)) return
  closeMenu()
}
function onViewportChange(): void {
  if (open.value) updatePosition()
}

watch(keyword, () => {
  if (picking) return
  if (!open.value) open.value = true
  updatePosition()
  clearTimeout(timer)
  timer = setTimeout(() => void load(), 250)
})
watch(
  () => props.type,
  () => {
    keyword.value = ''
    options.value = []
    activeIndex.value = -1
    clearTimeout(timer)
    if (open.value) void load()
  },
)

onMounted(() => {
  document.addEventListener('click', onDocClick)
  window.addEventListener('resize', onViewportChange)
  window.addEventListener('scroll', onViewportChange, true)
})
onBeforeUnmount(() => {
  clearTimeout(timer)
  document.removeEventListener('click', onDocClick)
  window.removeEventListener('resize', onViewportChange)
  window.removeEventListener('scroll', onViewportChange, true)
})
</script>

<template>
  <div ref="root" class="sub-picker">
    <div class="sub-search" :class="{ disabled: props.disabled }">
      <Search :size="15" class="sub-search-icon" />
      <input
        ref="inputEl"
        v-model="keyword"
        type="text"
        class="input sub-input"
        :placeholder="props.placeholder || (props.type === 'role' ? '搜索角色名称或编码' : '搜索人员姓名或账号')"
        :disabled="props.disabled"
        role="combobox"
        aria-autocomplete="list"
        aria-label="搜索角色或人员"
        :aria-expanded="open"
        @focus="openMenu"
        @keydown="onKeydown"
      />
      <span v-if="loading" class="sub-loading">搜索中…</span>
      <button
        v-else
        type="button"
        class="sub-caret"
        :class="{ open }"
        aria-label="展开候选列表"
        @click.stop="toggle"
      >
        <ChevronDown :size="15" />
      </button>
    </div>
  </div>

  <Teleport to="body">
    <div v-if="open" ref="menuEl" class="sub-menu" :style="menuStyle" role="listbox" aria-label="角色或人员候选">
      <div class="sub-menu-body">
        <button
          v-for="(option, index) in options"
          :key="`${option.subject_type}-${option.subject_id}`"
          type="button"
          class="sub-option"
          :class="{ active: index === activeIndex, added: isAdded(option), locked: isSuperAdmin(option) }"
          :disabled="isLocked(option)"
          role="option"
          :aria-selected="index === activeIndex"
          @mouseenter="activeIndex = index"
          @click="pick(option)"
        >
          <Users v-if="props.type === 'role'" :size="15" class="sub-icon" />
          <User v-else :size="15" class="sub-icon" />
          <span class="sub-name">{{ option.name }}</span>
          <span v-if="option.code && option.code !== option.name" class="sub-code">{{ option.code }}</span>
          <span v-if="isAdded(option)" class="sub-tag">已添加</span>
          <span v-else-if="isSuperAdmin(option)" class="sub-tag warn">超级管理员</span>
          <span v-else-if="option.status === 0" class="sub-tag warn">已停用</span>
        </button>
        <div v-if="loading && !options.length" class="sub-empty">搜索中…</div>
        <div v-else-if="error" class="sub-empty">{{ error }}</div>
        <div v-else-if="!options.length" class="sub-empty">没有匹配的角色 / 人员</div>
      </div>
      <div class="sub-foot">输入名称或编码搜索，选中即添加 · {{ options.length }} 条候选</div>
    </div>
  </Teleport>
</template>
