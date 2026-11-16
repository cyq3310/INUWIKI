import { ref } from 'vue'

/** 侧栏宽度：默认 300px，可拖范围 248~400px（下限 248、上限 400） */
export const SIDEBAR_MIN_WIDTH = 248
export const SIDEBAR_DEFAULT_WIDTH = 300
export const SIDEBAR_MAX_WIDTH = 400
const STORAGE_KEY = 'inuwiki.sidebarWidth'

/** 模块级单例：门户与后台列表页共享同一宽度；无本地记忆时落在默认值 */
const width = ref<number>(SIDEBAR_DEFAULT_WIDTH)

try {
  const saved = Number(window.localStorage.getItem(STORAGE_KEY))
  if (Number.isFinite(saved) && saved >= SIDEBAR_MIN_WIDTH && saved <= SIDEBAR_MAX_WIDTH) {
    width.value = saved
  }
} catch {
  // 隐私模式 / 禁用存储时保持默认宽度
}

function clamp(value: number): number {
  return Math.min(SIDEBAR_MAX_WIDTH, Math.max(SIDEBAR_MIN_WIDTH, Math.round(value)))
}

function applyWidth(next: number): void {
  width.value = clamp(next)
  try {
    window.localStorage.setItem(STORAGE_KEY, String(width.value))
  } catch {
    // 存储不可用不影响本次拖拽
  }
}

/**
 * 侧栏拖拽：pointer 拖动、方向键微调、Home/End 到边界、双击恢复。
 * 宽度写入 localStorage，刷新后保持。
 */
export function useSidebarWidth() {
  function startResize(event: PointerEvent): void {
    event.preventDefault()
    const startX = event.clientX
    const startWidth = width.value
    const onMove = (moveEvent: PointerEvent): void => {
      applyWidth(startWidth + (moveEvent.clientX - startX))
    }
    const onUp = (): void => {
      document.removeEventListener('pointermove', onMove)
      document.removeEventListener('pointerup', onUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }
    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'
    document.addEventListener('pointermove', onMove)
    document.addEventListener('pointerup', onUp)
  }

  function onResizeKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowRight') {
      event.preventDefault()
      applyWidth(width.value + 2)
    } else if (event.key === 'ArrowLeft') {
      event.preventDefault()
      applyWidth(width.value - 2)
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault()
      applyWidth(event.key === 'Home' ? SIDEBAR_MIN_WIDTH : SIDEBAR_MAX_WIDTH)
    }
  }

  function resetWidth(): void {
    applyWidth(SIDEBAR_DEFAULT_WIDTH)
  }

  return {
    sidebarWidth: width,
    minWidth: SIDEBAR_MIN_WIDTH,
    defaultWidth: SIDEBAR_DEFAULT_WIDTH,
    maxWidth: SIDEBAR_MAX_WIDTH,
    startResize,
    onResizeKeydown,
    resetWidth,
  }
}
