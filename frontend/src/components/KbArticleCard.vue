<script setup lang="ts">
import { computed } from 'vue'
import { extractMediaRefs } from '../utils/content'
import { formatCount, formatRelativeTime } from '../utils/format'
import { cardColorOf } from '../utils/card'
import type { ArticleItem } from '../types'

/** 知识卡片：标题满宽色带（8 色色块）、单行标题、两行摘要、文档类型 / 浏览 / 时间 */
const props = defineProps<{
  article: ArticleItem
  /** 文档类型名称（列表接口的 doc_type_name）；未指定类型时不渲染标签 */
  docTypeName?: string
}>()

const emit = defineEmits<{ (e: 'open', article: ArticleItem): void }>()

const bandColor = computed(() => cardColorOf(props.article.band_color, props.article.id))

/**
 * 列表接口不返回附件，是否视频条目由正文占位节点判断；
 * 时长需附件记录，列表阶段拿不到，故只标「视频」。
 */
const hasVideo = computed(() => extractMediaRefs(props.article.content || '').videoIds.length > 0)
</script>

<template>
  <article class="kb-card" @click="emit('open', props.article)">
    <div class="kb-band" :class="bandColor">
      <div class="kb-title" :title="props.article.title">{{ props.article.title }}</div>
      <span v-if="hasVideo" class="kb-band-video">视频</span>
    </div>

    <div class="kb-body">
      <div class="kb-summary">{{ props.article.summary || '（暂无摘要）' }}</div>
      <div class="kb-meta">
        <span v-if="props.docTypeName" class="tag">{{ props.docTypeName }}</span>
        <span>{{ formatCount(props.article.view_count) }} 浏览</span>
        <span>{{ formatRelativeTime(props.article.published_at || props.article.updated_at) }}</span>
      </div>
    </div>
  </article>
</template>
