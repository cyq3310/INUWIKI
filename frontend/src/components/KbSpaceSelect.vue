<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ChevronDown, Layers, Lock } from 'lucide-vue-next'
import type { SpaceItem } from '../types'

/**
 * 空间切换下拉：侧栏目录树之上的独立选择器。
 * 用按钮 + 自绘面板而非原生 select，才能在 248px 侧栏里同时显示空间名与编码，
 * 并沿用项目的圆角 / 描边 / 阴影 / 焦点环令牌。
 */
const props = withDefaults(
  defineProps<{
    spaces: SpaceItem[]
    modelValue: number | ''
    /** 是否提供「全部空间」入口（门户全局浏览用） */
    allowAll?: boolean
    /** 顶栏紧凑版：36px 高、隐藏标签与编码 */
    compact?: boolean
    loading?: boolean
    /** 空间列表暂不可用时的名称兜底（来自 store 的当前空间快照） */
    fallbackName?: string
  }>(),
  { allowAll: false, compact: false, loading: false, fallbackName: '' },
)

const emit = defineEmits<{ (e: 'update:modelValue', value: number | ''): void }>()

const root = ref<HTMLElement | null>(null)
const triggerEl = ref<HTMLButtonElement | null>(null)
const open = ref(false)

const current = computed(() =>
  props.modelValue === ''
    ? null
    : props.spaces.find((s) => Number(s.id) === Number(props.modelValue)) ?? null,
)
const name = computed(() => {
  if (props.modelValue === '') return props.allowAll ? '全部空间' : '请选择空间'
  return current.value?.name || props.fallbackName || (props.allowAll ? '全部空间' : '请选择空间')
})
const code = computed(() => {
  if (props.modelValue === '') return props.allowAll ? '所有可见空间' : '—'
  return current.value?.code || '—'
})

function toggle(): void {
  open.value = !open.value
}

function close(): void {
  open.value = false
}

function pick(value: number | ''): void {
  emit('update:modelValue', value === '' ? '' : Number(value))
  close()
  triggerEl.value?.focus()
}

function onDocClick(event: MouseEvent): void {
  if (root.value && !root.value.contains(event.target as Node)) close()
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close()
    triggerEl.value?.focus()
  } else if (event.key === 'ArrowDown' && !open.value) {
    event.preventDefault()
    open.value = true
  }
}

onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <div ref="root" class="space-picker" :class="{ compact: props.compact }">
    <div v-if="!props.compact" class="space-picker-label">当前空间</div>
    <button
      ref="triggerEl"
      type="button"
      class="space-trigger"
      :aria-expanded="open"
      aria-haspopup="listbox"
      @click="toggle"
      @keydown="onKeydown"
    >
      <span class="space-trigger-icon"><Layers :size="16" /></span>
      <span class="space-trigger-text">
        <span class="truncate">{{ name }}</span>
        <small class="truncate">{{ code }}</small>
      </span>
      <ChevronDown :size="16" class="space-trigger-caret" :class="{ open }" />
    </button>

    <div v-if="open" class="space-menu" role="listbox" aria-label="选择知识空间" @keydown="onKeydown">
      <button
        v-if="props.allowAll"
        type="button"
        class="space-option"
        :class="{ selected: props.modelValue === '' }"
        role="option"
        :aria-selected="props.modelValue === ''"
        @click="pick('')"
      >
        <span class="space-option-name truncate">全部空间</span>
        <small>跨空间浏览</small>
      </button>
      <button
        v-for="space in props.spaces"
        :key="space.id"
        type="button"
        class="space-option"
        :class="{ selected: Number(props.modelValue) === Number(space.id) }"
        role="option"
        :aria-selected="Number(props.modelValue) === Number(space.id)"
        @click="pick(space.id)"
      >
        <span class="space-option-name truncate">{{ space.name }}</span>
        <Lock v-if="space.is_restricted === 1" :size="12" class="space-option-lock" />
        <small class="truncate">{{ space.code }}</small>
      </button>
      <div v-if="props.loading" class="tree-hint">空间加载中…</div>
      <div v-else-if="!props.spaces.length" class="tree-hint">暂无可见空间</div>
    </div>
  </div>
</template>
