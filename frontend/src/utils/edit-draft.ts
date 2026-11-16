/**
 * 编辑页本地自动草稿：浏览器崩溃 / 误关标签 / 电脑重启后仍能找回正文。
 *
 * 只写 localStorage，不产生任何服务端存储与请求；按用户 + 文章隔离，
 * 单篇文章只保留一份草稿，7 天后过期。写入失败（隐私模式 / 配额满）静默降级，
 * 绝不阻断编辑。风格与 utils/upload-store.ts 的断点续传草稿一致。
 */

export interface EditDraftPayload {
  form: Record<string, unknown>
  editorGrants: unknown[]
  viewerGrants: unknown[]
  attachments: unknown[]
  baseUpdatedAt: string | null
}

export interface EditDraft extends EditDraftPayload {
  version: 1
  userId: number
  articleId: number | 'new'
  savedAt: number
}

const PREFIX = 'inuwiki_edit_draft'
const TTL = 7 * 24 * 60 * 60 * 1000
const VERSION = 1

function draftKey(userId: number, articleId: number | 'new'): string {
  return `${PREFIX}:${userId}:${articleId}`
}

export function readDraft(userId: number, articleId: number | 'new'): EditDraft | null {
  try {
    const key = draftKey(userId, articleId)
    const raw = localStorage.getItem(key)
    if (!raw) return null
    const draft = JSON.parse(raw) as EditDraft
    if (draft.version !== VERSION || draft.userId !== userId || Date.now() - draft.savedAt > TTL) {
      localStorage.removeItem(key)
      return null
    }
    return draft
  } catch {
    return null
  }
}

/** 返回是否写入成功；失败时调用方只提示，不影响编辑 */
export function writeDraft(
  userId: number,
  articleId: number | 'new',
  payload: EditDraftPayload,
): boolean {
  try {
    const draft: EditDraft = {
      version: VERSION,
      userId,
      articleId,
      ...payload,
      savedAt: Date.now(),
    }
    localStorage.setItem(draftKey(userId, articleId), JSON.stringify(draft))
    return true
  } catch {
    return false
  }
}

export function clearDraft(userId: number, articleId: number | 'new'): void {
  try {
    localStorage.removeItem(draftKey(userId, articleId))
  } catch {
    // 忽略
  }
}

/** 清掉所有过期草稿，避免长期占用本地配额 */
export function pruneDrafts(): void {
  try {
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const key = localStorage.key(i)
      if (!key || !key.startsWith(`${PREFIX}:`)) continue
      const raw = localStorage.getItem(key)
      if (!raw) {
        localStorage.removeItem(key)
        continue
      }
      try {
        const draft = JSON.parse(raw) as EditDraft
        if (draft.version !== VERSION || Date.now() - draft.savedAt > TTL) {
          localStorage.removeItem(key)
        }
      } catch {
        localStorage.removeItem(key)
      }
    }
  } catch {
    // 忽略
  }
}
