<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'

/**
 * 原生 <video> 播放器：不引入第三方播放器。
 * - 16:9 容器，preload=metadata，controls 走浏览器原生控件
 * - Range 拖拽由浏览器 + Nginx sendfile（206）原生支持，前端无需额外处理
 */
const props = withDefaults(
  defineProps<{
    src: string
    poster?: string
    title?: string
    /** 未就绪时的提示文案 */
    unavailableHint?: string
  }>(),
  { poster: '', title: '', unavailableHint: '' },
)

const videoRef = ref<HTMLVideoElement | null>(null)
const failed = ref(false)

const onError = () => {
  failed.value = true
}

watch(
  () => props.src,
  () => {
    failed.value = false
  },
)

onBeforeUnmount(() => {
  const el = videoRef.value
  if (el) {
    el.pause()
    el.removeAttribute('src')
    el.load()
  }
})
</script>

<template>
  <div class="kb-player">
    <video
      v-if="!failed"
      ref="videoRef"
      :src="props.src"
      :poster="props.poster || undefined"
      :title="props.title || undefined"
      controls
      preload="metadata"
      playsinline
      @error="onError"
    ></video>
    <div v-else class="kb-player-error">
      <div style="font-size: 22px">⛔</div>
      <div>视频无法加载</div>
      <div style="opacity: 0.8">
        {{ props.unavailableHint || '可能尚未转码完成，或当前环境未提供媒体鉴权方式' }}
      </div>
    </div>
  </div>
</template>
