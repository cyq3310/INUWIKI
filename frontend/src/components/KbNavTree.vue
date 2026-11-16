<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { BookOpen } from 'lucide-vue-next'
import KbCategoryTree from './KbCategoryTree.vue'
import { categoryTrail, sortSystemLast, systemCategoryId } from '../utils/tree'
import {
  applyExpandToggle,
  normalizeExpanded,
  readExpandedCategories,
  writeExpandedCategories,
} from '../utils/category-expand'
import type { ArticleItem, CategoryNode } from '../types'

/**
 * 单空间导航树：只渲染「当前选中空间」的目录与文章。
 * 空间切换交给上方的 KbSpaceSelect；这里保留「全部知识」作为清空分类筛选的入口。
 * 展开层级按空间存在 localStorage：无缓存只显示 L1，返回主页时恢复上次展开的路径。
 */
const props = withDefaults(
  defineProps<{
    spaceId: number | ''
    categoryId: number | ''
    categories: CategoryNode[]
    articles: ArticleItem[]
    loading?: boolean
  }>(),
  { loading: false },
)

const emit = defineEmits<{
  (e: 'update:categoryId', value: number | ''): void
  (e: 'open-article', value: ArticleItem): void
}>()

const pickCategory = (value: number | ''): void => emit('update:categoryId', value)
const forwardArticle = (article: ArticleItem): void => emit('open-article', article)

const expanded = ref<number[]>([])
const orderedCategories = computed(() => sortSystemLast(props.categories))

/** category_id=0 的存量文章统一挂到「未指定目录」节点下，避免散落在树外 */
const normalizedArticles = computed(() => {
  const systemId = systemCategoryId(orderedCategories.value)
  if (!systemId) return props.articles
  return props.articles.map((article) =>
    Number(article.category_id) === 0 ? { ...article, category_id: systemId } : article,
  )
})

/** 目录树异步到达、空间切换时，从本地记忆恢复并剔除已删除的目录 */
function syncExpanded(): void {
  expanded.value = normalizeExpanded(orderedCategories.value, readExpandedCategories(props.spaceId))
  ensureSelectedTrail()
}

/** 当前选中的目录若藏在深层，把它的祖先链路一并展开并记忆，避免筛选后路径不可见 */
function ensureSelectedTrail(): void {
  if (props.categoryId === '' || !orderedCategories.value.length) return
  const ancestors = categoryTrail(orderedCategories.value, props.categoryId)
    .slice(0, -1)
    .map((seg) => Number(seg.id))
  if (!ancestors.length) return
  const merged = normalizeExpanded(orderedCategories.value, [...expanded.value, ...ancestors])
  const changed =
    merged.length !== expanded.value.length ||
    merged.some((id, index) => id !== expanded.value[index])
  if (!changed) return
  expanded.value = merged
  writeExpandedCategories(props.spaceId, merged)
}

function toggleExpand(id: number): void {
  const next = applyExpandToggle(orderedCategories.value, expanded.value, id)
  expanded.value = next
  writeExpandedCategories(props.spaceId, next)
}

watch(() => props.spaceId, syncExpanded, { immediate: true })
watch(() => props.categories, syncExpanded)
watch(() => props.categoryId, ensureSelectedTrail)
</script>

<template>
  <div class="nav-tree">
    <div v-if="props.spaceId === ''" class="nav-empty">
      选择具体空间后，这里显示该空间的目录与文章结构。
    </div>

    <template v-else>
      <div
        class="nav-row all"
        :class="{ active: props.categoryId === '' }"
        role="button"
        tabindex="0"
        @click="pickCategory('')"
        @keydown.enter="pickCategory('')"
        @keydown.space.prevent="pickCategory('')"
      >
        <span class="twisty-spacer"></span>
        <BookOpen :size="15" class="icon" />
        <span class="label">全部知识</span>
        <span class="count">{{ props.articles.length }}</span>
      </div>

      <KbCategoryTree
        :tree="orderedCategories"
        :model-value="props.categoryId"
        :expanded="expanded"
        :articles="normalizedArticles"
        :depth="0"
        @update:model-value="pickCategory"
        @open-article="forwardArticle"
        @toggle-expand="toggleExpand"
      />

      <div v-if="props.loading" class="nav-empty">目录加载中…</div>
      <div v-else-if="!props.categories.length && !props.articles.length" class="nav-empty">
        该空间暂无目录与文章
      </div>
    </template>
  </div>
</template>
