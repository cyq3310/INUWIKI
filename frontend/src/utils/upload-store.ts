import type { AttachmentType } from '../types'

/**
 * 断点续传记录：以「文件名 + 大小 + lastModified（+ 类型）」为键存 localStorage。
 * 与后端约定一致（见 backend/app/service/UploadService.php 的注释）：
 * 服务端只下发 uploadId，前端本地记住任务，重新进入时调 /uploads/check 拿到已传分片索引后继续。
 */
export interface ResumeRecord {
  key: string
  name: string
  size: number
  lastModified: number
  type: AttachmentType
  uploadId: string
  chunkSize: number
  chunkTotal: number
  /** 已上传的分片索引 */
  uploaded: number[]
  updatedAt: number
}

const PREFIX = 'inuwiki:upload:'

/** 续传键：文件名 + 大小 + lastModified */
export function resumeKey(file: File, type: AttachmentType): string {
  return `${PREFIX}${type}:${file.name}:${file.size}:${file.lastModified}`
}

export function saveRecord(record: ResumeRecord): void {
  try {
    localStorage.setItem(record.key, JSON.stringify(record))
  } catch {
    // 隐私模式或超出配额时忽略，不影响上传流程本身
  }
}

export function loadRecord(key: string): ResumeRecord | null {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) return null
    const parsed = JSON.parse(raw) as ResumeRecord
    return parsed && parsed.uploadId ? parsed : null
  } catch {
    return null
  }
}

export function removeRecord(key: string): void {
  try {
    localStorage.removeItem(key)
  } catch {
    // ignore
  }
}

/** 列出所有未完成的上传任务（按更新时间倒序） */
export function listRecords(): ResumeRecord[] {
  const list: ResumeRecord[] = []
  try {
    for (let i = 0; i < localStorage.length; i += 1) {
      const key = localStorage.key(i)
      if (!key || !key.startsWith(PREFIX)) continue
      const record = loadRecord(key)
      if (record) list.push(record)
    }
  } catch {
    // ignore
  }
  return list.sort((a, b) => b.updatedAt - a.updatedAt)
}
