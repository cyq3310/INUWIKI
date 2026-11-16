<script setup lang="ts">
import { computed, ref } from 'vue'
import { Download, Paperclip } from 'lucide-vue-next'
import KbVideoPlayer from './KbVideoPlayer.vue'
import { parseContent } from '../utils/content'
import { mediaPlayUrl } from '../utils/media'
import { resolveUploadUrl } from '../config/runtime'
import { fileExt, formatBytes, formatDuration } from '../utils/format'
import type { Attachment } from '../types'

/**
 * 正文渲染器：
 * - <div data-kb-video="12"> → 原地渲染播放器
 * - [data-kb-file="34"]      → 原地渲染附件卡片
 * - 正文图片点击 → 灯箱放大
 */
const props = withDefaults(
  defineProps<{
    html: string
    attachments: Attachment[]
    articleId: number
  }>(),
  { attachments: () => [], articleId: 0 },
)

const parsed = computed(() => parseContent(props.html))

const attachmentMap = computed(() => {
  const map = new Map<number, Attachment>()
  for (const item of props.attachments ?? []) map.set(item.id, item)
  return map
})

/** 附件扩展名 → 占位渐变（与原型 g1-g6 一致） */
const EXT_GRADIENT: Record<string, string> = {
  pdf: 'g3',
  doc: 'g1',
  docx: 'g1',
  xls: 'g2',
  xlsx: 'g2',
  ppt: 'g6',
  pptx: 'g6',
  zip: 'g4',
  png: 'g5',
  jpg: 'g5',
  jpeg: 'g5',
  webp: 'g5',
  gif: 'g5',
}

const gradientOf = (name: string) => EXT_GRADIENT[fileExt(name)] || 'g1'

const playUrl = (attachmentId: number) => mediaPlayUrl(props.articleId, attachmentId)

// ---------- 图片灯箱 ----------
const lightbox = ref<string | null>(null)

const onContentClick = (event: MouseEvent) => {
  const target = event.target as HTMLElement | null
  if (target && target.tagName === 'IMG') {
    lightbox.value = (target as HTMLImageElement).src
  }
}

const closeLightbox = () => {
  lightbox.value = null
}
</script>

<template>
  <div>
    <template v-for="(block, index) in parsed.blocks" :key="index">
      <!-- 纯 HTML 段 -->
      <div v-if="block.kind === 'html'" class="article-body" v-html="block.html" @click="onContentClick"></div>

      <!-- 正文内嵌视频 -->
      <div
        v-else-if="block.kind === 'video'"
        style="margin: 18px 0; border-radius: var(--r-m); overflow: hidden; box-shadow: var(--sh-2)"
      >
        <KbVideoPlayer
          v-if="attachmentMap.get(block.attachmentId)"
          :src="playUrl(block.attachmentId)"
          :poster="resolveUploadUrl(attachmentMap.get(block.attachmentId)?.thumb_path || '')"
          :title="attachmentMap.get(block.attachmentId)?.original_name || ''"
          :unavailable-hint="
            attachmentMap.get(block.attachmentId)?.status === 'ready'
              ? '视频尚未就绪或播放鉴权未通过'
              : `视频当前状态：${attachmentMap.get(block.attachmentId)?.status || '未知'}`
          "
        />
        <div v-else class="kb-player" style="aspect-ratio: 16 / 4">
          <div class="kb-player-error">
            该视频附件（ID {{ block.attachmentId }}）未随本文返回，可能已被移除或尚未绑定到文章
          </div>
        </div>
      </div>

      <!-- 正文内嵌附件 -->
      <div v-else class="kb-file-card">
        <div class="kb-file-icon" :class="gradientOf(attachmentMap.get(block.attachmentId)?.original_name || block.text)">
          {{ (fileExt(attachmentMap.get(block.attachmentId)?.original_name || block.text) || 'file').slice(0, 4) }}
        </div>
        <div style="flex: 1; min-width: 0">
          <div style="font-size: 14px; font-weight: 500">
            {{ attachmentMap.get(block.attachmentId)?.original_name || block.text || `附件 #${block.attachmentId}` }}
          </div>
          <div class="cell-sub">
            {{ formatBytes(attachmentMap.get(block.attachmentId)?.size || 0) }}
            <template v-if="attachmentMap.get(block.attachmentId)?.status === 'failed'"> · 转码失败</template>
          </div>
        </div>
        <a :href="playUrl(block.attachmentId)" class="btn btn-sm">
          <Download :size="14" />下载
        </a>
      </div>
    </template>

    <!-- 占位节点之外无内容（如正文只有视频）时给出提示 -->
    <div
      v-if="!parsed.blocks.length && !props.html"
      class="kb-empty"
      style="display: flex; align-items: center; justify-content: center; gap: 8px"
    >
      <Paperclip :size="16" />该知识暂无正文内容
    </div>

    <!-- 图片灯箱 -->
    <Teleport to="body">
      <div v-if="lightbox" class="kb-lightbox" @click="closeLightbox">
        <img :src="lightbox" alt="预览大图" @click.stop />
        <button type="button" class="kb-lightbox-close" @click="closeLightbox">✕</button>
      </div>
    </Teleport>
  </div>
</template>
