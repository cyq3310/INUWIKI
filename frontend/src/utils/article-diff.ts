import { diffWordsWithSpace } from 'diff'

export type ArticleDiffKind = 'same' | 'added' | 'removed'

export interface ArticleDiffPart {
  kind: ArticleDiffKind
  text: string
}

export interface ArticleDiffResult {
  oldText: string
  currentText: string
  oldParts: ArticleDiffPart[]
  currentParts: ArticleDiffPart[]
  hasDiff: boolean
}

/** 把富文本正文转成适合词级 diff 的纯文本，并把媒体占位符显式化 */
export function htmlToDiffText(html: string): string {
  const raw = html || ''
  if (typeof DOMParser === 'undefined') {
    return raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
  }

  const doc = new DOMParser().parseFromString(raw, 'text/html')
  doc.querySelectorAll('script, style').forEach((el) => el.remove())

  doc.querySelectorAll('[data-kb-video]').forEach((el) => {
    el.textContent = `[视频 #${el.getAttribute('data-kb-video') || ''}]`
  })
  doc.querySelectorAll('[data-kb-file]').forEach((el) => {
    el.textContent = `[附件 #${el.getAttribute('data-kb-file') || ''}]`
  })
  doc.querySelectorAll('img').forEach((img) => {
    const alt = img.getAttribute('alt')
    img.replaceWith(doc.createTextNode(`[图片${alt ? ` ${alt}` : ''}]`))
  })

  doc
    .querySelectorAll('p, div, h1, h2, h3, h4, h5, h6, li, blockquote, tr, table, ul, ol, section, article, br')
    .forEach((el) => {
      if (el.tagName === 'BR') el.replaceWith(doc.createTextNode('\n'))
      else el.insertAdjacentText('afterend', '\n')
    })

  return (doc.body.textContent || '')
    .replace(/\u00a0/g, ' ')
    .replace(/[ \t\r]+/g, ' ')
    .replace(/ *\n */g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}

/** 选中历史版本 vs 当前版本：生成左右两侧的词级差异片段 */
export function buildArticleDiff(oldHtml: string, currentHtml: string): ArticleDiffResult {
  const oldText = htmlToDiffText(oldHtml)
  const currentText = htmlToDiffText(currentHtml)
  const changes = diffWordsWithSpace(oldText, currentText)

  const oldParts: ArticleDiffPart[] = []
  const currentParts: ArticleDiffPart[] = []

  for (const change of changes) {
    if (change.added) {
      currentParts.push({ kind: 'added', text: change.value })
    } else if (change.removed) {
      oldParts.push({ kind: 'removed', text: change.value })
    } else {
      oldParts.push({ kind: 'same', text: change.value })
      currentParts.push({ kind: 'same', text: change.value })
    }
  }

  return {
    oldText,
    currentText,
    oldParts,
    currentParts,
    hasDiff: changes.some((change) => change.added || change.removed),
  }
}
