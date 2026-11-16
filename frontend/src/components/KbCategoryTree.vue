<script setup lang="ts">
import { ChevronRight, FileText, Folder, FolderOpen } from 'lucide-vue-next'
import type { ArticleItem, CategoryNode } from '../types'

/**
 * 目录 / 文章树：分类为可折叠目录，文章为叶子节点。
 * 缩进按 depth 累加（原先 .tree-child 固定 24px，多层结构会挤成同一列）。
 * 展开状态由 KbNavTree 统一持有并持久化，递归层共用同一份，默认全部收起（只显示 L1）。
 */
const props = withDefaults(
  defineProps<{
    tree: CategoryNode[]
    modelValue: number | ''
    /** 展开的目录 ID 集合，由父组件透传，所有递归层共用 */
    expanded?: number[]
    /** 当前空间的全部文章，按 category_id 挂到所属目录 */
    articles?: ArticleItem[]
    depth?: number
  }>(),
  { expanded: () => [], articles: () => [], depth: 0 },
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | ''): void
  (e: 'open-article', value: ArticleItem): void
  (e: 'toggle-expand', value: number): void
}>()

const isOpen = (id: number): boolean =>
  props.expanded.some((value) => Number(value) === Number(id))

function toggle(id: number): void {
  emit('toggle-expand', Number(id))
}
const forwardToggle = (id: number): void => emit('toggle-expand', id)

const articlesOf = (id: number): ArticleItem[] =>
  props.articles.filter((a) => Number(a.category_id) === Number(id))
const hasChildren = (node: CategoryNode): boolean => !!node.children?.length
const hasContent = (node: CategoryNode): boolean => hasChildren(node) || articlesOf(node.id).length > 0

/** 目录上的数字含子目录文章，避免空目录看着像没内容 */
function countOf(node: CategoryNode): number {
  let total = articlesOf(node.id).length
  for (const child of node.children ?? []) total += countOf(child)
  return total
}

const indent = (extra = 0): string => `padding-left: ${8 + (props.depth + extra) * 14}px`
const pick = (value: number | ''): void => emit('update:modelValue', value)
const forwardArticle = (article: ArticleItem): void => emit('open-article', article)
const STATUS_LABEL: Record<ArticleItem['status'], string> = {
  draft: '草稿',
  offline: '已下架',
  published: '',
  deleted: '已删除',
}
const statusLabel = (status: ArticleItem['status']): string => STATUS_LABEL[status] ?? ''
</script>

<template>
  <div class="nav-tree-children">
    <template v-for="node in props.tree" :key="node.id">
      <div
        class="nav-row folder"
        :class="{ active: Number(props.modelValue) === Number(node.id) }"
        :style="indent()"
        :title="node.name"
        role="button"
        tabindex="0"
        :aria-expanded="hasContent(node) ? isOpen(node.id) : undefined"
        @click="pick(node.id)"
        @keydown.enter="pick(node.id)"
        @keydown.space.prevent="pick(node.id)"
      >
        <span
          v-if="hasContent(node)"
          class="twisty"
          :class="{ open: isOpen(node.id) }"
          role="button"
          tabindex="0"
          :aria-label="isOpen(node.id) ? '收起子目录' : '展开子目录'"
          @click.stop="toggle(node.id)"
          @keydown.enter.stop.prevent="toggle(node.id)"
          @keydown.space.stop.prevent="toggle(node.id)"
        >
          <ChevronRight :size="14" />
        </span>
        <span v-else class="twisty-spacer"></span>
        <FolderOpen v-if="isOpen(node.id) && hasContent(node)" :size="15" class="icon folder-icon" />
        <Folder v-else :size="15" class="icon folder-icon" />
        <span class="label truncate">{{ node.name }}</span>
        <span v-if="Number(node.is_system ?? 0) === 1" class="cat-system-tag">系统</span>
        <span v-if="countOf(node)" class="count">{{ countOf(node) }}</span>
      </div>

      <template v-if="isOpen(node.id)">
        <KbCategoryTree
          v-if="hasChildren(node)"
          :tree="node.children ?? []"
          :model-value="props.modelValue"
          :expanded="props.expanded"
          :articles="props.articles"
          :depth="props.depth + 1"
          @update:model-value="pick"
          @open-article="forwardArticle"
          @toggle-expand="forwardToggle"
        />
        <div
          v-for="article in articlesOf(node.id)"
          :key="`article-${article.id}`"
          class="nav-row article"
          :style="indent(1)"
          :title="article.title"
          role="button"
          tabindex="0"
          :aria-label="article.title + (statusLabel(article.status) ? '（' + statusLabel(article.status) + '）' : '')"
          @click="forwardArticle(article)"
          @keydown.enter="forwardArticle(article)"
          @keydown.space.prevent="forwardArticle(article)"
        >
          <span class="twisty-spacer"></span>
          <FileText :size="14" class="icon" />
          <span class="label truncate">{{ article.title }}</span>
          <span
            v-if="article.status !== 'published'"
            class="status-dot"
            :class="article.status"
            :title="statusLabel(article.status)"
          ></span>
        </div>
      </template>
    </template>
  </div>
</template>
