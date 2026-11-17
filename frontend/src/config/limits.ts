import type { AttachmentType } from '../types'

/**
 * 上传限制：必须与 backend/config/myconfig.php 的 knowledge 组保持一致
 * （image_max / video_max / file_max 与 image_ext / video_ext / file_ext）。
 * 前端只做即时提示，最终以服务端校验为准。
 */
export const UPLOAD_LIMITS: Record<AttachmentType, { max: number; ext: string[]; label: string }> = {
  image: {
    max: 10 * 1024 * 1024,
    ext: ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    label: 'JPG / PNG / WebP / GIF，≤10 MB',
  },
  video: {
    max: 1024 * 1024 * 1024,
    ext: ['mp4', 'mov', 'mkv', 'avi', 'webm'],
    label: 'mp4 / mov / mkv / avi / webm，单个最大 1 GB',
  },
  file: {
    max: 50 * 1024 * 1024,
    ext: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt', 'fbx', 'glb'],
    label: 'PDF / Office / ZIP / TXT / FBX / GLB，单个最大 50 MB',
  },
}

/** 单片大小（5MB），与 myconfig.knowledge.chunk_size 一致 */
export const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024
