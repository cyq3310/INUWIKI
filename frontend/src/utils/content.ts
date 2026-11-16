/**
 * 正文解析：把 wangEditor 产出的 HTML 切成「HTML 段 / 视频 / 附件」三类块，
 * 供 KbContentRenderer 原地渲染。占位节点约定（与后端 PurifierService 白名单一致）：
 *   <div data-kb-video="12"></div>   正文段落内插入的视频
 *   <a data-kb-file="34">xx.pdf</a>  正文段落内插入的附件
 *   <div data-kb-file="34"></div>    同上（div 形态）
 */

import { resolveUploadUrl } from '../config/runtime'

export type ContentBlock =
  | { kind: 'html'; html: string }
  | { kind: 'video'; attachmentId: number; html: string }
  | { kind: 'file'; attachmentId: number; html: string; text: string }

export interface TocItem {
  id: string
  text: string
  /** 1-3，对应 h1-h3 */
  level: number
}

export interface ParsedContent {
  blocks: ContentBlock[]
  toc: TocItem[]
}

/** 是否占位节点 */
function isPlaceholder(el: Element): boolean {
  return el.hasAttribute('data-kb-video') || el.hasAttribute('data-kb-file')
}

function placeholderId(el: Element): number {
  const raw = el.getAttribute('data-kb-video') || el.getAttribute('data-kb-file') || '0'
  return Number(raw) || 0
}

function openTag(el: Element): string {
  return (el.cloneNode(false) as Element).outerHTML.replace(/>\s*$/, '>')
}

/**
 * 历史正文保存的是静态直出 URL（/uploads/knowledge/<path>）。
 * 静态路径已禁止外部直出，统一换成走鉴权的资源 URL，避免旧文章图片失效或绕过鉴权。
 */
export function normalizeAssetUrls(html: string): string {
  if (!html || !html.includes('/uploads/knowledge/')) return html
  const doc = new DOMParser().parseFromString(`<div id="kb-root">${html}</div>`, 'text/html')
  const root = doc.getElementById('kb-root')
  if (!root) return html

  const marker = '/uploads/knowledge/'
  root.querySelectorAll('img[src], video[poster], source[src]').forEach((el) => {
    for (const attr of ['src', 'poster']) {
      const value = el.getAttribute(attr)
      if (!value || !value.includes(marker)) continue
      const rel = value.slice(value.indexOf(marker) + marker.length).split(/[?#]/)[0]
      if (rel) el.setAttribute(attr, resolveUploadUrl(rel))
    }
  })
  return root.innerHTML
}

/**
 * 解析正文：
 * 1. 给 h1-h3 加上锚点 id（供左侧目录跳转与滚动高亮）
 * 2. 按占位节点切块，占位节点之外的部分原样输出
 */
export function parseContent(html: string): ParsedContent {
  const doc = new DOMParser().parseFromString(`<div id="kb-root">${normalizeAssetUrls(html) || ''}</div>`, 'text/html')
  const root = doc.getElementById('kb-root')
  if (!root) return { blocks: [], toc: [] }

  // 1. 标题锚点
  const toc: TocItem[] = []
  root.querySelectorAll('h1,h2,h3').forEach((el, index) => {
    const id = `kb-h-${index}`
    el.setAttribute('id', id)
    toc.push({ id, text: el.textContent?.trim() || `章节 ${index + 1}`, level: Number(el.tagName.slice(1)) || 3 })
  })

  // 2. 切块
  const blocks: ContentBlock[] = []
  let buffer = ''

  const flush = () => {
    const value = buffer.trim()
    if (value) blocks.push({ kind: 'html', html: value })
    buffer = ''
  }

  const walk = (node: Element) => {
    for (const child of Array.from(node.childNodes)) {
      if (child.nodeType === Node.TEXT_NODE) {
        buffer += child.textContent ?? ''
        continue
      }
      if (child.nodeType !== Node.ELEMENT_NODE) continue
      const el = child as Element

      if (isPlaceholder(el)) {
        flush()
        const id = placeholderId(el)
        if (el.hasAttribute('data-kb-video')) {
          blocks.push({ kind: 'video', attachmentId: id, html: el.outerHTML })
        } else {
          blocks.push({ kind: 'file', attachmentId: id, html: el.outerHTML, text: el.textContent?.trim() || '' })
        }
        continue
      }

      if (el.querySelector('[data-kb-video],[data-kb-file]')) {
        // 占位节点嵌在段落内：保留外层标签，递归处理内部
        buffer += openTag(el)
        walk(el)
        buffer += `</${el.tagName.toLowerCase()}>`
        continue
      }

      buffer += el.outerHTML
    }
  }

  walk(root)
  flush()

  return { blocks, toc }
}

/** wangEditor(slate) 要求顶层必须是块级节点 */
const EDITOR_BLOCK_TAGS = new Set([
  'P',
  'H1',
  'H2',
  'H3',
  'H4',
  'H5',
  'H6',
  'UL',
  'OL',
  'DIV',
  'TABLE',
  'BLOCKQUOTE',
  'PRE',
  'HR',
])

/**
 * 把正文规范化为 wangEditor 可安全挂载的结构。
 *
 * wangEditor 的 slate 模型要求顶层只能是块级节点，遇到游离的 `<a>` 或裸文本会抛
 * "Cannot resolve a DOM node from Slate node"；该错误发生在组件更新期且无法捕获，
 * 会打断整个页面的渲染循环（表现为正文页签空白、按钮全部失去响应）。
 * 因此历史数据 / 异常数据在进编辑器前统一处理：
 * - 顶层非块级节点（如裸 `<a>`）包进 `<p>`
 * - 顶层裸文本包进 `<p>`，纯空白文本节点丢弃
 */
export function normalizeForEditor(html: string): string {
  const source = normalizeAssetUrls(html || '').trim()
  if (!source) return ''

  const doc = new DOMParser().parseFromString(`<div id="kb-root">${source}</div>`, 'text/html')
  const root = doc.getElementById('kb-root')
  if (!root) return source

  for (const node of Array.from(root.childNodes)) {
    if (node.nodeType === Node.TEXT_NODE) {
      if (!(node.textContent ?? '').trim()) {
        node.remove()
        continue
      }
      const p = doc.createElement('p')
      p.textContent = node.textContent ?? ''
      node.replaceWith(p)
      continue
    }
    if (node.nodeType !== Node.ELEMENT_NODE) continue

    const el = node as Element
    if (EDITOR_BLOCK_TAGS.has(el.tagName)) continue

    const p = doc.createElement('p')
    el.replaceWith(p)
    p.append(el)
  }

  return root.innerHTML
}

/** 取出正文引用的全部视频 / 附件 ID（列表页判断"是否视频条目"用） */
export function extractMediaRefs(html: string): { videoIds: number[]; fileIds: number[] } {
  const doc = new DOMParser().parseFromString(`<div id="kb-root">${html || ''}</div>`, 'text/html')
  const root = doc.getElementById('kb-root')
  const videoIds: number[] = []
  const fileIds: number[] = []
  if (!root) return { videoIds, fileIds }
  root.querySelectorAll('[data-kb-video]').forEach((el) => {
    const id = Number(el.getAttribute('data-kb-video') || 0)
    if (id > 0) videoIds.push(id)
  })
  root.querySelectorAll('[data-kb-file]').forEach((el) => {
    const id = Number(el.getAttribute('data-kb-file') || 0)
    if (id > 0) fileIds.push(id)
  })
  return { videoIds, fileIds }
}
