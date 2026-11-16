<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { AlertTriangle, CheckCircle2, Loader2, Pause, Play, UploadCloud, X } from 'lucide-vue-next'
import { uploadApi } from '../api'
import {
  loadRecord,
  listRecords,
  removeRecord,
  resumeKey,
  saveRecord,
  type ResumeRecord,
} from '../utils/upload-store'
import { formatBytes, formatDuration } from '../utils/format'
import type { AttachmentType } from '../types'

/**
 * 分片上传组件（5MB / 片 · 并发 3 · 断点续传）
 *
 * 流程：init → check（续传时）→ 并发上传分片 → complete → 轮询附件状态
 * 续传键：文件名 + 大小 + lastModified（+ 类型），存 localStorage；
 * 重新进入时先 GET /admin/kb/uploads/check 拿已传分片索引，再从未传的片继续。
 *
 * 四态：上传中 → 合并中 → 转码中 → 就绪（失败单独一态）。
 * 注意：合并与转码由后端队列异步执行，前端只能通过轮询附件状态得知进度，
 * 后端不返回转码百分比，因此合并/转码阶段进度条按 100% 展示。
 */

/** 单片 5MB，与后端 knowledge.chunk_size 保持一致 */
const CHUNK_SIZE = 5 * 1024 * 1024
/** 并发上传的分片数 */
const CONCURRENCY = 3
/** 合并 / 转码轮询间隔（毫秒） */
const POLL_INTERVAL = 3000
/** 轮询最大次数（3s × 600 ≈ 30 分钟） */
const POLL_MAX = 600

type TaskState = 'uploading' | 'paused' | 'merging' | 'transcoding' | 'ready' | 'failed' | 'canceled'

interface UploadTask {
  id: string
  name: string
  size: number
  lastModified: number
  file: File | null
  /** localStorage 续传键 */
  key: string
  uploadId: string
  chunkSize: number
  chunkTotal: number
  /** 已上传的分片索引（递增，持久化到 localStorage） */
  uploaded: number[]
  /** 在途分片索引（防止并发重复上传同一片） */
  inflight: number[]
  /** 已上传字节数（用于速度估算） */
  bytes: number
  state: TaskState
  attachmentId: number
  duration: number
  thumb: string
  error: string
  /** 瞬时速度 B/s */
  speed: number
  /** 速度采样基准 */
  lastBytes: number
  lastTs: number
  /** 正在上传的分片数（并发计数） */
  active: number
  /** 轮询错误计数 */
  pollErrors: number
}

const props = withDefaults(
  defineProps<{
    type: AttachmentType
    /** input[accept]，默认按 type 推导 */
    accept?: string
    /** 面板副标题提示语 */
    tip?: string
  }>(),
  { accept: '', tip: '' },
)

const emit = defineEmits<{
  /** 转码完成、附件就绪 */
  (e: 'done', payload: { attachmentId: number; name: string; type: AttachmentType; duration: number }): void
  /** 点击「插入正文」（就绪态任务） */
  (e: 'insert', payload: { attachmentId: number; name: string; type: AttachmentType }): void
  /** 点击「移除」：仅从本次会话移除，后端无删除附件接口 */
  (e: 'remove', attachmentId: number): void
}>()

const tasks = ref<UploadTask[]>([])
/** 进入页面时在 localStorage 里发现的未完成任务 */
const pending = ref<ResumeRecord[]>([])
const dragover = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

/** 计时器句柄：速度采样 + 每个任务的轮询 */
let speedTimer = 0
const pollTimers = new Map<string, number>()
/** 点击「续传」时锁定的目标记录（随后弹文件选择框） */
let resumeTarget: ResumeRecord | null = null

const acceptAttr = computed(() => {
  if (props.accept) return props.accept
  if (props.type === 'video') return '.mp4,.mov,.mkv,.webm,.avi'
  if (props.type === 'image') return '.jpg,.jpeg,.png,.webp,.gif'
  return '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt,.png,.jpg,.jpeg,.webp'
})

const typeLabel = computed(() => (props.type === 'video' ? '视频' : props.type === 'image' ? '图片' : '附件'))

const defaultTip = computed(() => {
  if (props.tip) return props.tip
  if (props.type === 'video') return '支持 mp4 / mov / mkv / webm，自动分片 5 MB 并异步转码'
  if (props.type === 'image') return '支持 jpg / png / webp / gif'
  return '支持 pdf / office / zip 等常见文档，自动分片 5 MB'
})

// ---------- 状态展示 ----------

const STATE_TEXT: Record<TaskState, string> = {
  uploading: '上传中',
  paused: '已暂停',
  merging: '合并中',
  transcoding: '转码中',
  ready: '已就绪',
  failed: '失败',
  canceled: '已取消',
}

const STATE_PILL: Record<TaskState, string> = {
  uploading: 'blue',
  paused: 'gray',
  merging: 'blue',
  transcoding: 'amber',
  ready: 'green',
  failed: 'red',
  canceled: 'gray',
}

const BAR_COLOR: Record<TaskState, string> = {
  uploading: 'linear-gradient(135deg,#4F7DF3,#2B5CE6)',
  paused: '#C9D4F0',
  merging: 'linear-gradient(135deg,#4F7DF3,#2B5CE6)',
  transcoding: 'linear-gradient(135deg,#F5A524,#E5761B)',
  ready: 'linear-gradient(135deg,#36C9A6,#159A76)',
  failed: 'linear-gradient(135deg,#E5484D,#C22A2E)',
  canceled: '#C9D4F0',
}

const stateText = (task: UploadTask) => STATE_TEXT[task.state]
const statePill = (task: UploadTask) => STATE_PILL[task.state]

/** 进度百分比：仅上传阶段有真实值，合并/转码后端不下发百分比 */
function percent(task: UploadTask): number {
  if (task.state === 'canceled') return 0
  if (task.state === 'uploading' || task.state === 'paused') {
    if (!task.chunkTotal) return 0
    return Math.min(99, Math.round((task.uploaded.length / task.chunkTotal) * 100))
  }
  return 100
}

const barStyle = (task: UploadTask) => ({ width: `${percent(task)}%`, background: BAR_COLOR[task.state] })

const etaText = (task: UploadTask): string => {
  if (task.state !== 'uploading' || task.speed <= 0) return '—'
  const remain = Math.max(0, task.size - task.bytes)
  return `剩余约 ${formatDuration(remain / task.speed)}`
}

const isBusy = (task: UploadTask) => task.state === 'uploading' || task.state === 'paused'

// ---------- 续传记录 ----------

function persist(task: UploadTask): void {
  if (!task.uploadId) return
  saveRecord({
    key: task.key,
    name: task.name,
    size: task.size,
    lastModified: task.lastModified,
    type: props.type,
    uploadId: task.uploadId,
    chunkSize: task.chunkSize,
    chunkTotal: task.chunkTotal,
    uploaded: [...task.uploaded],
    updatedAt: Date.now(),
  })
}

function refreshPending(): void {
  pending.value = listRecords().filter((r) => r.type === props.type)
}

// ---------- 上传调度 ----------

function nextIndex(task: UploadTask): number | null {
  for (let i = 0; i < task.chunkTotal; i += 1) {
    if (!task.uploaded.includes(i) && !task.inflight.includes(i)) return i
  }
  return null
}

function chunkSizeAt(task: UploadTask, index: number): number {
  const start = index * task.chunkSize
  return Math.max(0, Math.min(task.chunkSize, task.size - start))
}

async function uploadChunk(task: UploadTask, index: number): Promise<void> {
  const file = task.file
  if (!file) throw new Error('文件句柄已丢失，请重新选择文件续传')
  const start = index * task.chunkSize
  const blob = file.slice(start, start + chunkSizeAt(task, index))
  task.inflight.push(index)
  try {
    await uploadApi.chunk(task.uploadId, index, blob, props.type)
    if (!task.uploaded.includes(index)) task.uploaded.push(index)
    task.bytes += blob.size
  } finally {
    task.inflight = task.inflight.filter((item) => item !== index)
  }
}

/** 启动并发上传（并发 3）。任一任务同时最多 3 个分片在飞 */
function pump(task: UploadTask): void {
  if (task.state !== 'uploading') return

  while (task.active < CONCURRENCY) {
    const index = nextIndex(task)
    if (index === null) {
      // 全部分片已上传 → 收尾
      if (task.active === 0 && task.uploaded.length >= task.chunkTotal) {
        void finalize(task)
      }
      return
    }
    task.active += 1
    uploadChunk(task, index)
      .catch((err: Error) => {
        task.state = 'failed'
        task.error = err.message || '分片上传失败'
        persist(task)
      })
      .finally(() => {
        task.active -= 1
        if (task.state === 'uploading') pump(task)
      })
  }
}

/** 全部片上传完成 → complete（合并入队）→ 轮询状态 */
async function finalize(task: UploadTask): Promise<void> {
  task.state = 'merging'
  try {
    const res = await uploadApi.complete(task.uploadId, props.type)
    task.attachmentId = Number(res.data?.attachment_id || 0)
    removeRecord(task.key)
    refreshPending()
    if (!task.attachmentId) {
      task.state = 'failed'
      task.error = '服务端未返回附件 ID'
      return
    }
    startPolling(task)
  } catch (err) {
    task.state = 'failed'
    task.error = (err as Error).message || '合并失败'
    persist(task)
  }
}

function startPolling(task: UploadTask): void {
  stopPolling(task.id)
  let times = 0
  const poll = (): void => {
    times += 1
    if (task.state === 'canceled') {
      stopPolling(task.id)
      return
    }
    uploadApi
      .attachment(task.attachmentId)
      .then((res) => {
        task.pollErrors = 0
        const status = res.data?.status
        task.duration = Number(res.data?.duration || 0)
        task.thumb = res.data?.thumb_path || ''
        if (status === 'ready') {
          stopPolling(task.id)
          task.state = 'ready'
          ElMessage.success(`「${task.name}」处理完成`)
          emit('done', {
            attachmentId: task.attachmentId,
            name: task.name,
            type: props.type,
            duration: task.duration,
          })
        } else if (status === 'failed') {
          stopPolling(task.id)
          task.state = 'failed'
          task.error = res.data?.fail_reason || '处理失败'
        } else if (status === 'transcoding') {
          task.state = 'transcoding'
        } else {
          task.state = 'merging'
        }
      })
      .catch((err: Error) => {
        task.pollErrors += 1
        if (task.pollErrors >= 5 || times >= POLL_MAX) {
          stopPolling(task.id)
          if (task.state !== 'ready') {
            task.state = 'failed'
            task.error = err.message || '状态查询失败'
          }
        }
      })
    if (times >= POLL_MAX) {
      stopPolling(task.id)
      if (task.state !== 'ready') {
        task.state = 'failed'
        task.error = '处理超时，请稍后到文章列表查看附件状态'
      }
    }
  }

  const timer = window.setInterval(poll, POLL_INTERVAL)
  pollTimers.set(task.id, timer)
  // 先登记定时器再立即查一次：首次命中 ready 时 stopPolling 才能清掉定时器
  // complete 返回即查询，sync 队列下无需白等一个轮询间隔
  poll()
}

function stopPolling(taskId: string): void {
  const timer = pollTimers.get(taskId)
  if (timer) {
    window.clearInterval(timer)
    pollTimers.delete(taskId)
  }
}

/** 恢复上传：只重传缺失的分片 */
async function start(file: File, record?: ResumeRecord | null): Promise<void> {
  const key = resumeKey(file, props.type)
  let existing: ResumeRecord | null = record && record.key === key ? record : loadRecord(key)

  // 选择了文件且本地存在同键记录 → 提示续传（"发现未完成上传任务，是否续传"）
  if (existing && !record) {
    try {
      await ElMessageBox.confirm(
        `发现未完成上传任务「${file.name}」（已传 ${existing.uploaded.length} / ${existing.chunkTotal} 片），是否续传？`,
        '断点续传',
        { confirmButtonText: '续传', cancelButtonText: '重新上传', type: 'warning' },
      )
    } catch {
      // 选择「重新上传」：丢弃旧任务
      removeRecord(key)
      existing = null
    }
  }

  const task: UploadTask = {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    name: file.name,
    size: file.size,
    lastModified: file.lastModified,
    file,
    key,
    uploadId: '',
    chunkSize: CHUNK_SIZE,
    chunkTotal: 0,
    uploaded: [],
    inflight: [],
    bytes: 0,
    state: 'uploading',
    attachmentId: 0,
    duration: 0,
    thumb: '',
    error: '',
    speed: 0,
    lastBytes: 0,
    lastTs: Date.now(),
    active: 0,
    pollErrors: 0,
  }
  tasks.value.unshift(task)

  try {
    if (existing?.uploadId) {
      // 断点续传：先向服务端核对已传分片索引
      try {
        const res = await uploadApi.check(existing.uploadId)
        task.uploadId = existing.uploadId
        task.chunkSize = Number(existing.chunkSize || CHUNK_SIZE)
        task.chunkTotal = Number(res.data?.chunk_total || existing.chunkTotal || 0)
        task.uploaded = [...(res.data?.uploaded ?? [])].sort((a, b) => a - b)
      } catch {
        // 服务端任务已过期 → 重新初始化
        task.uploadId = ''
      }
    }

    if (!task.uploadId) {
      const init = await uploadApi.init({ filename: file.name, size: file.size, type: props.type })
      task.uploadId = init.data.upload_id
      task.chunkSize = Number(init.data.chunk_size || CHUNK_SIZE)
      task.chunkTotal = Number(init.data.chunk_total || 0)
      task.uploaded = []
    }

    if (!task.chunkTotal) {
      task.chunkTotal = Math.ceil(task.size / task.chunkSize)
    }

    task.bytes = task.uploaded.reduce((sum, index) => sum + chunkSizeAt(task, index), 0)
    persist(task)
    refreshPending()
    pump(task)
  } catch (err) {
    task.state = 'failed'
    task.error = (err as Error).message || '初始化上传失败'
  }
}

// ---------- 交互 ----------

function pickFile(record: ResumeRecord | null = null): void {
  resumeTarget = record
  fileInput.value?.click()
}

function onFileChange(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  // 清空 value，允许重复选择同一文件
  input.value = ''
  if (!file) return
  if (file.size <= 0) {
    ElMessage.warning('文件为空，无法上传')
    return
  }
  const target = resumeTarget
  resumeTarget = null
  void start(file, target)
}

function onDrop(event: DragEvent): void {
  dragover.value = false
  const file = event.dataTransfer?.files?.[0]
  if (file) void start(file, null)
}

function pause(task: UploadTask): void {
  task.state = 'paused'
  persist(task)
  refreshPending()
}

function resume(task: UploadTask): void {
  if (!task.file) {
    // 刷新页面后 File 句柄丢失，需要重新选择同一文件才能续传
    ElMessage.info('请重新选择同一个文件以继续上传')
    pickFile(loadRecord(task.key))
    return
  }
  task.state = 'uploading'
  task.lastBytes = task.bytes
  task.lastTs = Date.now()
  pump(task)
}

function cancel(task: UploadTask): void {
  stopPolling(task.id)
  task.state = 'canceled'
  task.file = null
  task.inflight = []
  removeRecord(task.key)
  refreshPending()
}

function removeTask(task: UploadTask): void {
  stopPolling(task.id)
  removeRecord(task.key)
  if (task.attachmentId) emit('remove', task.attachmentId)
  tasks.value = tasks.value.filter((t) => t.id !== task.id)
  refreshPending()
}

function doInsert(task: UploadTask): void {
  emit('insert', { attachmentId: task.attachmentId, name: task.name, type: props.type })
}

function tickSpeed(): void {
  const now = Date.now()
  for (const task of tasks.value) {
    const dt = (now - task.lastTs) / 1000
    if (task.state === 'uploading' && dt >= 0.8) {
      task.speed = Math.max(0, (task.bytes - task.lastBytes) / dt)
      task.lastBytes = task.bytes
      task.lastTs = now
    } else if (task.state !== 'uploading') {
      task.speed = 0
    }
  }
}

onMounted(() => {
  refreshPending()
  speedTimer = window.setInterval(tickSpeed, 1000)
})

onBeforeUnmount(() => {
  if (speedTimer) window.clearInterval(speedTimer)
  for (const id of Array.from(pollTimers.keys())) stopPolling(id)
  // 离开页面时保留未完成任务，便于下次续传
  for (const task of tasks.value) {
    if (isBusy(task)) persist(task)
  }
})
</script>

<template>
  <div>
    <!-- 选择区（拖拽 / 点击） -->
    <div
      class="uploader"
      :class="{ dragover }"
      @click="pickFile(null)"
      @dragover.prevent="dragover = true"
      @dragleave.prevent="dragover = false"
      @drop.prevent="onDrop"
    >
      <div class="u-icon"><UploadCloud :size="26" /></div>
      <p>将{{ typeLabel }}拖拽到此处，或 <span class="link">点击选择文件</span></p>
      <small>{{ defaultTip }}</small>
    </div>

    <input
      ref="fileInput"
      type="file"
      :accept="acceptAttr"
      style="display: none"
      @change="onFileChange"
    />

    <!-- 未完成上传任务提示（断点续传入口） -->
    <div
      v-for="record in pending"
      :key="record.key"
      style="
        margin-top: 12px;
        padding: 10px 14px;
        background: #fff8e8;
        border: 1px solid #f3e2bc;
        border-radius: var(--r-s);
        font-size: 12px;
        color: #8a6a1f;
      "
    >
      检测到未完成上传任务「{{ record.name }}」（已传 {{ record.uploaded.length }} / {{ record.chunkTotal }} 片），
      <span class="link" @click="pickFile(record)">点击续传</span>
    </div>

    <!-- 任务列表 -->
    <div v-for="task in tasks" :key="task.id" class="task">
      <div class="task-head">
        <span class="task-name">{{ task.name }}</span>
        <span class="task-size">{{ formatBytes(task.size) }}</span>
        <span class="pill" :class="statePill(task)">
          <Loader2 v-if="task.state === 'merging' || task.state === 'transcoding'" :size="11" />
          <CheckCircle2 v-else-if="task.state === 'ready'" :size="11" />
          <AlertTriangle v-else-if="task.state === 'failed'" :size="11" />
          {{ stateText(task) }}
        </span>
        <span v-if="isBusy(task)" style="margin-left: auto; font-size: 12px; color: var(--text-3)">
          {{ task.uploaded.length }} / {{ task.chunkTotal }} 片
        </span>
        <span v-else-if="task.duration" style="margin-left: auto; font-size: 12px; color: var(--text-3)">
          {{ formatDuration(task.duration) }}
        </span>
      </div>

      <div class="task-bar"><i :style="barStyle(task)"></i></div>

      <div class="task-foot">
        <span v-if="isBusy(task)">{{ percent(task) }}%</span>
        <span v-else>{{ stateText(task) }}</span>
        <span v-if="task.state === 'uploading'">{{ formatBytes(task.speed) }}/s</span>
        <span v-if="task.state === 'uploading'">{{ etaText(task) }}</span>
        <span v-else-if="task.attachmentId">附件 #{{ task.attachmentId }}</span>

        <span class="sp">
          <template v-if="task.state === 'uploading'">
            <span class="link" @click="pause(task)"><Pause :size="12" class="inline-block align-[-2px]" /> 暂停</span>
            ·
            <span class="link danger" @click="cancel(task)">取消</span>
          </template>
          <template v-else-if="task.state === 'paused'">
            <span class="link" @click="resume(task)"><Play :size="12" class="inline-block align-[-2px]" /> 继续</span>
            ·
            <span class="link danger" @click="cancel(task)">取消</span>
          </template>
          <template v-else-if="task.state === 'ready'">
            <template v-if="type !== 'file'">
              <span class="link" @click="doInsert(task)">插入正文</span>
              ·
            </template>
            <span class="link danger" @click="removeTask(task)">移除</span>
          </template>
          <template v-else>
            <span class="link danger" @click="removeTask(task)"><X :size="12" class="inline-block align-[-2px]" /> 移除</span>
          </template>
        </span>
      </div>

      <div v-if="task.error" class="cell-sub" style="color: var(--red)">失败原因：{{ task.error }}</div>
    </div>
  </div>
</template>
