<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Editor, Toolbar } from '@wangeditor/editor-for-vue'
import { DomEditor } from '@wangeditor/editor'
import type { IDomEditor, IEditorConfig, IToolbarConfig } from '@wangeditor/editor'
import '@wangeditor/editor/dist/css/style.css'
import { Check, Film, FolderPlus, Paperclip, Settings2 } from 'lucide-vue-next'
import KbCategoryCreateDialog from '../../components/KbCategoryCreateDialog.vue'
import KbCategoryPicker from '../../components/KbCategoryPicker.vue'
import KbChunkUploader from '../../components/KbChunkUploader.vue'
import KbSubjectPicker from '../../components/KbSubjectPicker.vue'
import { articleApi, directoryApi, docTypeApi, portalApi, uploadApi } from '../../api'
import { useUserStore } from '../../stores/user'
import { useWikiStore } from '../../stores/wiki'
import { resolveUploadUrl } from '../../config/runtime'
import { DEFAULT_CHUNK_SIZE, UPLOAD_LIMITS } from '../../config/limits'
import { mergeSpaceOptions, resolveCreateSpaceId } from '../../utils/create-space'
import { sortSystemLast, systemCategoryId } from '../../utils/tree'
import { normalizeForEditor, parseContent, type TocItem } from '../../utils/content'
import { CARD_COLORS, isCardColor } from '../../utils/card'
import { formatBytes, formatDuration } from '../../utils/format'
import { clearDraft, pruneDrafts, readDraft, writeDraft, type EditDraft, type EditDraftPayload } from '../../utils/edit-draft'
import type { ArticleStatus, ArticleVersion, AttachmentStatus, AttachmentType, DocType, EditMode, SubjectOption, SubjectType, ViewScope } from '../../types'

/**
 * 后台 · 文章编辑（正文为主区域，文章设置以右侧抽屉展开，新建时自动展开）
 *
 * 设置：标题（≤16 字）/ 空间+分类 / 文档类型 / 摘要（≤36 字，卡片固定两行）
 *      / 标题色带（8 色色块）/ 发布设置 / 可见范围 / 可编辑范围 / 授权名单
 * 正文：wangEditor 占满整行，工具栏含「插入视频」「插入附件」；
 *      编辑器下方依次为 KbChunkUploader（视频）与附件区域。
 *
 * 编辑页保存时会提交当前附件清单；后端按清单与正文占位节点全量绑定 / 解绑视频与文件附件。
 * 「移除」会同步清理正文占位，保存后阅读页不再返回或渲染该附件。
 */

interface GrantRow {
  subject_type: SubjectType
  subject_id: number
  name: string
}

interface AttRow {
  id: number
  name: string
  type: AttachmentType
  size: number
  duration: number
  status?: AttachmentStatus
}

const ATTACHMENT_STATUS_TEXT: Record<AttachmentStatus, string> = {
  pending: '待处理',
  merging: '合并中',
  transcoding: '转码中',
  ready: '就绪',
  failed: '处理失败',
}

function attachmentStatusText(status?: AttachmentStatus): string {
  return status ? ATTACHMENT_STATUS_TEXT[status] : '就绪'
}

const route = useRoute()
const router = useRouter()
const store = useUserStore()
const wiki = useWikiStore()

const routeId = computed(() => Number(route.params.id) || 0)
/** 文章设置抽屉：新建时自动展开，编辑时由「设置」按钮打开 */
const settingsOpen = ref(false)
const loading = ref(false)
const saving = ref(false)

/** 卡片标题最多 16 字；摘要 36 字可保证最窄卡片（280px）预留的两行完整显示 */
const MAX_TITLE_LENGTH = 16
const MAX_SUMMARY_LENGTH = 36

const form = reactive({
  title: '',
  space_id: '' as number | '',
  category_id: '' as number | '',
  doc_type_id: '' as number | '',
  summary: '',
  band_color: 'g1',
  status: 'draft' as ArticleStatus,
  view_scope: 'space' as ViewScope,
  edit_mode: 'open' as EditMode,
  content: '',
})

const VIEW_SCOPES: { value: ViewScope; title: string; desc: string }[] = [
  { value: 'public', title: '公开', desc: '所有登录用户可见，并计入全站统计与推荐' },
  { value: 'space', title: '空间内可见', desc: '仅所属空间的成员可见' },
  { value: 'restricted', title: '指定范围', desc: '仅下方授权的角色 / 人员可见，且不出现在全站统计中' },
]

const EDIT_MODES: { value: EditMode; title: string; desc: string }[] = [
  { value: 'open', title: '所有人可改', desc: '公开空间默认：具备编辑权限的成员均可修改本文' },
  { value: 'restricted', title: '指定人 · 角色可改', desc: '仅下方名单内的角色或人员可修改，其余人只读' },
]

// ---------- 授权名单 ----------

const grantTab = ref<'editor' | 'viewer'>('editor')
const editorGrants = ref<GrantRow[]>([])
const viewerGrants = ref<GrantRow[]>([])
const newGrantType = ref<SubjectType>('role')

const grantRows = computed(() => (grantTab.value === 'editor' ? editorGrants.value : viewerGrants.value))
/** 已加入当前名单的同类型 ID，供选择器标记「已添加」 */
const currentSelectedIds = computed(() =>
  grantRows.value
    .filter((row) => row.subject_type === newGrantType.value)
    .map((row) => Number(row.subject_id)),
)

/** 按 ID 精确解析名称：不受搜索条数限制，历史停用 / 软删对象也能显示 */
async function resolveGrantNames(rows: GrantRow[]): Promise<void> {
  for (const type of ['role', 'user'] as SubjectType[]) {
    const ids = [
      ...new Set(
        rows
          .filter((row) => row.subject_type === type && !row.name)
          .map((row) => Number(row.subject_id)),
      ),
    ]
    if (!ids.length) continue
    try {
      const res = await directoryApi.resolve(type, ids)
      for (const subject of res.data?.subjects ?? []) {
        const target = rows.find(
          (row) =>
            row.subject_type === subject.subject_type &&
            Number(row.subject_id) === Number(subject.subject_id),
        )
        if (target) target.name = subject.name
      }
    } catch {
      // 目录接口不可用时保持「角色 / 人员 #ID」展示
    }
  }
}

function toGrantRows(raw: unknown): GrantRow[] {
  const list = Array.isArray(raw) ? raw : []
  return list
    .map((item) => {
      const row = item as { subject_type?: string; subject_id?: number }
      const type = row.subject_type === 'user' ? 'user' : 'role'
      const id = Number(row.subject_id || 0)
      if (!id) return null
      return { subject_type: type, subject_id: id, name: '' } as GrantRow
    })
    .filter((row): row is GrantRow => !!row)
}

async function loadGrants(id: number): Promise<void> {
  const [editor, viewer] = await Promise.all([
    articleApi.grants(id, 'editor').catch(() => null),
    articleApi.grants(id, 'viewer').catch(() => null),
  ])
  editorGrants.value = toGrantRows(editor?.data?.grants)
  viewerGrants.value = toGrantRows(viewer?.data?.grants)
  await resolveGrantNames([...editorGrants.value, ...viewerGrants.value])
}

const subjectName = (row: GrantRow) =>
  row.name || `${row.subject_type === 'role' ? '角色' : '人员'} #${row.subject_id}`

/** 搜索选择器选中即加入当前名单，名称由目录结果带入，无需手填 */
function addGrant(option: SubjectOption): void {
  const id = Number(option.subject_id)
  if (!id || id <= 0) return
  const target = grantTab.value === 'editor' ? editorGrants : viewerGrants
  if (
    target.value.some(
      (row) => row.subject_type === option.subject_type && Number(row.subject_id) === id,
    )
  ) {
    ElMessage.info('该对象已在名单中')
    return
  }
  target.value.push({
    subject_type: option.subject_type,
    subject_id: id,
    name: option.name,
  })
}

function removeGrant(row: GrantRow): void {
  if (grantTab.value === 'editor') {
    editorGrants.value = editorGrants.value.filter((item) => item !== row)
  } else {
    viewerGrants.value = viewerGrants.value.filter((item) => item !== row)
  }
}

async function saveGrants(id: number): Promise<void> {
  await articleApi.setGrants(
    id,
    'editor',
    editorGrants.value.map((row) => ({ subject_type: row.subject_type, subject_id: row.subject_id })),
  )
  await articleApi.setGrants(
    id,
    'viewer',
    viewerGrants.value.map((row) => ({ subject_type: row.subject_type, subject_id: row.subject_id })),
  )
}

// ---------- 标题 / 摘要长度 ----------

/** 摘要换行折叠为空格并限制字数，保证卡片预留的 2 行能完整显示 */
function onSummaryInput(): void {
  const normalized = form.summary.replace(/[\r\n]+/g, ' ').slice(0, MAX_SUMMARY_LENGTH)
  if (normalized !== form.summary) form.summary = normalized
}

// ---------- 正文编辑器 ----------

const editorRef = shallowRef<IDomEditor | null>(null)
const toolbarConfig: Partial<IToolbarConfig> = {}

/** 左侧目录：由当前正文 HTML 直接推导，输入或插入 H1-H3 标题后即时更新 */
const editorToc = computed<TocItem[]>(() => parseContent(form.content).toc)
const activeEditorToc = ref(-1)
watch(editorToc, () => {
  activeEditorToc.value = -1
})

/** 点击目录项滚动到编辑器内对应标题（标题节点顺序与目录项一致） */
function jumpToEditorHeading(index: number): void {
  activeEditorToc.value = index
  const heading = document.querySelectorAll<HTMLElement>(
    '[data-w-e-textarea] h1, [data-w-e-textarea] h2, [data-w-e-textarea] h3',
  )[index]
  if (!heading) return

  const scroller = heading.closest('.kb-editor')?.querySelector<HTMLElement>('.w-e-scroll')
    ?? document.querySelector<HTMLElement>('.kb-editor .w-e-scroll')
  if (!scroller) {
    heading.scrollIntoView({ behavior: 'smooth', block: 'start' })
    return
  }

  const top = heading.getBoundingClientRect().top
    - scroller.getBoundingClientRect().top
    + scroller.scrollTop
    - 12
  scroller.scrollTo({ top: Math.max(0, top), behavior: 'smooth' })
}

/** 粘贴 / 工具栏插入图片共用：上传后返回可写入正文的根相对 URL */
async function uploadImageFile(file: File): Promise<string> {
  const res = await uploadApi.image(file)
  const path = res.data?.path
  if (!path) throw new Error('服务端未返回图片路径')
  return resolveUploadUrl(path)
}

const editorConfig: Partial<IEditorConfig> = {
  placeholder: '在此撰写正文…支持标题、列表、图片、表格等；可直接粘贴截图，视频与附件请用下方按钮插入',
  // 工具栏「插入图片」走 wangEditor 内置上传；base64LimitSize: 0 避免
  // 内联 data: URI（保存时会被后端 HTMLPurifier 剥离）。
  MENU_CONF: {
    uploadImage: {
      base64LimitSize: 0,
      customUpload: async (file: File, insertFn: (url: string, alt?: string, href?: string) => void) => {
        try {
          insertFn(await uploadImageFile(file), file.name || '图片', '')
        } catch {
          ElMessage.error('图片上传失败，请检查上传权限后重试')
        }
      },
    },
  },
}

/**
 * 粘贴图片：editor-for-vue 要求 customPaste 走 @customPaste 事件
 * （放进 defaultConfig 会直接抛错），且结果必须用第三个回调返回：
 * false 阻止默认粘贴，避免 Slate 再处理一次造成重复插入。
 */
function onCustomPaste(editor: IDomEditor, event: ClipboardEvent, done: (res?: boolean) => void): void {
  const images = Array.from(event.clipboardData?.files ?? []).filter((f) => f.type.startsWith('image/'))
  if (images.length === 0) {
    done(true)
    return
  }

  const text = event.clipboardData?.getData('text/plain') ?? ''
  if (text.trim()) editor.insertText(text)

  void (async () => {
    for (const file of images) {
      try {
        const alt = (file.name || '粘贴图片').replace(/["<>]/g, '')
        editor.dangerouslyInsertHtml(`<img src="${await uploadImageFile(file)}" alt="${alt}" />`)
      } catch {
        ElMessage.error('图片上传失败，请检查上传权限后重试')
      }
    }
  })()
  done(false)
}

function onCreated(editor: IDomEditor): void {
  editorRef.value = editor

  // 列表项内禁止 Tab 行缩进：wangEditor 的 list 插件会在光标位于 list-item
  // 行首时把 level +1 生成多级列表。多级列表在 HTMLPurifier 折叠 / 回显时
  // 容易丢字或被合并，这里统一禁用，列表始终保持单级。
  const baseHandleTab = editor.handleTab.bind(editor)
  editor.handleTab = () => {
    if (DomEditor.getSelectedNodeByType(editor, 'list-item')) return
    baseHandleTab()
  }
}

onBeforeUnmount(() => {
  // editor-for-vue 会在组件卸载时自行 destroy，这里仅释放引用
  editorRef.value = null
})

/** 附件清单：已有附件 + 本次会话上传成功的附件（全量，保存时用于后端绑定） */
const attachments = ref<AttRow[]>([])

/** 视频与文件附件分开展示；正文图片仍由 bindContentAssets 按 URL 绑定 */
const videoAttachments = computed(() => attachments.value.filter((row) => row.type === 'video'))
const fileAttachments = computed(() => attachments.value.filter((row) => row.type === 'file'))

function upsertAttachment(row: AttRow): void {
  const hit = attachments.value.find((item) => item.id === row.id)
  if (hit) Object.assign(hit, row)
  else attachments.value.push(row)
}

/** 分片上传完成即加入清单；即使后端队列还没处理完，也能在编辑页看到并移除 */
function onUploadCommitted(payload: { attachmentId: number; name: string; type: AttachmentType; status: AttachmentStatus }): void {
  upsertAttachment({
    id: payload.attachmentId,
    name: payload.name,
    type: payload.type,
    size: 0,
    duration: 0,
    status: payload.status,
  })
}

function onUploaded(payload: { attachmentId: number; name: string; type: AttachmentType; duration: number }): void {
  upsertAttachment({
    id: payload.attachmentId,
    name: payload.name,
    type: payload.type,
    size: 0,
    duration: payload.duration,
    status: 'ready',
  })
}

// ---------- 插入正文 ----------

const insertDialog = ref(false)
const insertKind = ref<AttachmentType>('video')

const candidates = computed(() => attachments.value.filter((row) => row.type === insertKind.value))

function openInsert(kind: AttachmentType): void {
  insertKind.value = kind
  insertDialog.value = true
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

/**
 * 正文占位节点约定（与 utils/content.ts、后端 PurifierService 白名单一致）：
 *   <div data-kb-video="12"></div>
 *   <div data-kb-file="34">名称</div>
 */
function insertIntoEditor(row: { id: number; name: string; type: AttachmentType }): void {
  const editor = editorRef.value
  if (!editor) {
    ElMessage.warning('正文编辑器尚未就绪')
    return
  }
  editor.restoreSelection()
  const html =
    row.type === 'video'
      ? `<div data-kb-video="${row.id}"></div><p><br></p>`
      : `<div data-kb-file="${row.id}">${escapeHtml(row.name)}</div><p><br></p>`
  editor.dangerouslyInsertHtml(html)
  insertDialog.value = false
  ElMessage.success('已插入正文（落在光标所在段落）')
}

/** KbChunkUploader 的 insert 事件载荷（attachmentId） → 正文插入 */
function onInsertRequest(payload: { attachmentId: number; name: string; type: AttachmentType }): void {
  insertIntoEditor({ id: payload.attachmentId, name: payload.name, type: payload.type })
}

/** 从当前清单与正文占位节点同时移除；保存后服务端会解绑该附件 */
function dropAttachment(id: number): void {
  attachments.value = attachments.value.filter((row) => row.id !== id)

  const doc = new DOMParser().parseFromString(`<div id="kb-root">${form.content || ''}</div>`, 'text/html')
  const root = doc.getElementById('kb-root')
  if (root) {
    root.querySelectorAll(`[data-kb-video="${id}"], [data-kb-file="${id}"]`).forEach((node) => node.remove())
    form.content = normalizeForEditor(root.innerHTML)
  }
  scheduleDraft()
  ElMessage.success('已移除附件，保存后不再显示')
}

/** 上传上限取自与后端 myconfig 同步的 limits 配置，避免界面文案与实际限制脱节 */
const videoMaxText = computed(() => formatBytes(UPLOAD_LIMITS.video.max))
const fileMaxText = computed(() => formatBytes(UPLOAD_LIMITS.file.max))
const chunkText = `${DEFAULT_CHUNK_SIZE / 1048576} MB`
const videoTip = computed(() => `${UPLOAD_LIMITS.video.label} · 自动分片 ${chunkText} 并异步转码`)
const fileTip = computed(() => `${UPLOAD_LIMITS.file.label} · 自动分片 ${chunkText}`)

// ---------- 保存 ----------

const canSubmit = computed(() => !!form.title.trim() && !!form.space_id)

async function loadArticle(): Promise<void> {
  const id = routeId.value
  initializing.value = true
  suppressDraft = true
  // 后台空间列表需要 manage 权限；同时补一份门户可见空间，确保所属空间名称可得
  await Promise.all([
    wiki.loadAdminSpaces().catch(() => undefined),
    wiki.loadSpaces().catch(() => undefined),
  ])

  if (!id) {
    // 新建：URL 上的 space_id 是唯一权威来源，绝不静默退回 adminSpaces[0]。
    // 同时同步后台顶栏空间，避免从门户进入时顶栏仍停在别的空间。
    const querySpaceId = Number(route.query.space_id || 0)
    const querySpaceName = typeof route.query.space_name === 'string' ? route.query.space_name : ''
    const resolved = resolveCreateSpaceId(querySpaceId, wiki.adminSpaces, wiki.spaces, wiki.adminSpaceId)
    form.space_id = resolved
    if (resolved) {
      // 来源空间名随 URL 传入：即使空间列表接口取不到，也显示对应空间的真实名称
      wiki.setAdminSpaceId(resolved, querySpaceName || wiki.spaceName(resolved))
      await refreshCategories(Number(resolved))
      await loadDocTypes(Number(resolved))
      // 新建文章默认归入该空间的「未指定目录」；URL 指定目录时以 URL 为准
      const queryCategoryId = Number(route.query.category_id || 0)
      form.category_id = queryCategoryId || systemCategoryForForm() || ''
    }
    initializing.value = false
    suppressDraft = false
    return
  }

  loading.value = true
  try {
    let row = wiki.editRow && Number(wiki.editRow.id) === id ? wiki.editRow : null
    if (!row) {
      // 刷新编辑页时由后台详情兜底（草稿 / 下架也能拿到）；再退到门户详情
      try {
        const res = await articleApi.detail(id)
        row = res.data?.article ?? null
      } catch {
        try {
          const res = await portalApi.article(id)
          row = res.data?.article ?? null
        } catch {
          row = null
        }
      }
    }

    if (!row) {
      ElMessage.warning('未找到该文章数据，或文章已被删除')
      router.replace('/admin/wiki')
      return
    }

    baseUpdatedAt.value = row.updated_at ?? null
    form.title = row.title
    form.space_id = Number(row.space_id) || ''
    form.category_id = Number(row.category_id) || ''
    form.doc_type_id = Number(row.doc_type_id) || ''
    form.summary = row.summary ?? ''
    form.band_color = isCardColor(row.band_color) ? row.band_color : 'g1'
    form.status = row.status
    form.view_scope = row.view_scope
    form.edit_mode = row.edit_mode
    // 历史数据可能含游离的顶层 <a> / 裸文本，直接喂给 wangEditor 会让整页渲染崩溃
    form.content = normalizeForEditor(row.content || '')
    for (const att of row.attachments ?? []) {
      upsertAttachment({
        id: att.id,
        name: att.original_name,
        type: att.type,
        size: att.size,
        duration: att.duration,
        status: att.status,
      })
    }

    if (form.space_id) await refreshCategories(Number(form.space_id))
    await loadDocTypes(Number(form.space_id))
    await loadGrants(id)
  } finally {
    loading.value = false
    initializing.value = false
    suppressDraft = false
  }
}

async function saveArticle(status: ArticleStatus): Promise<void> {
  if (!form.title.trim()) {
    ElMessage.warning('请填写文章标题')
    settingsOpen.value = true
    return
  }
  if (!form.space_id) {
    ElMessage.warning('请选择所属空间')
    settingsOpen.value = true
    return
  }
  if (form.title.trim().length > MAX_TITLE_LENGTH) {
    ElMessage.warning(`文章标题最多 ${MAX_TITLE_LENGTH} 个字`)
    settingsOpen.value = true
    return
  }
  if (form.summary.length > MAX_SUMMARY_LENGTH) {
    ElMessage.warning(`摘要最多 ${MAX_SUMMARY_LENGTH} 个字`)
    settingsOpen.value = true
    return
  }

  saving.value = true
  try {
    const payload = {
      title: form.title.trim().slice(0, MAX_TITLE_LENGTH),
      space_id: Number(form.space_id),
      category_id: Number(form.category_id || 0),
      doc_type_id: Number(form.doc_type_id || 0),
      summary: form.summary.trim().slice(0, MAX_SUMMARY_LENGTH) || null,
      band_color: isCardColor(form.band_color) ? form.band_color : null,
      content: form.content,
      status,
      view_scope: form.view_scope,
      edit_mode: form.edit_mode,
      // 当前附件清单交给文章保存接口全量同步，避免二次 attach 与页面跳转产生竞态
      attachment_ids: attachments.value.map((row) => row.id),
    }

    let id = routeId.value
    if (id) {
      await articleApi.update(id, payload)
    } else {
      const res = await articleApi.create(payload)
      id = Number(res.data?.id || 0)
      if (!id) {
        ElMessage.error('创建失败：服务端未返回文章 ID')
        return
      }
    }

    // 授权名单（restricted 场景）
    await saveGrants(id).catch(() => undefined)

    // 保存成功：清掉本地草稿并解除离开拦截，避免下次进编辑页误报「恢复草稿」
    clearDraft(draftUserId.value, draftArticleId.value)
    dirty.value = false
    draftActive.value = false
    ElMessage.success(status === 'published' ? '已发布' : '已保存')
    wiki.setEditRow(null)
    await router.replace('/admin/wiki')
  } finally {
    saving.value = false
  }
}

function goBack(): void {
  router.push('/admin/wiki')
}

/** 当前空间的目录树：选择器与新增目录弹层共用同一份数据 */
const categoryTree = computed(() =>
  sortSystemLast(form.space_id ? wiki.adminCategories[Number(form.space_id)] ?? [] : []),
)
/** 当前空间的「未指定目录」ID：新建文章或清空目录时用它兜底 */
const systemCategoryForForm = (): number => systemCategoryId(categoryTree.value)
const canManageCategory = computed(() => store.hasPermission('kb:category:manage'))
const categoryDialogOpen = ref(false)
const categoryLoading = ref(false)
/** 初始化回填表单期间，space_id 的 watch 不应清空 category_id */
const initializing = ref(false)

// ---------- 文档类型（文章分类，与目录相互独立） ----------
const docTypes = ref<DocType[]>([])
const docLoading = ref(false)

async function loadDocTypes(spaceId: number): Promise<void> {
  if (!spaceId) {
    docTypes.value = []
    return
  }
  docLoading.value = true
  try {
    const res = await docTypeApi.bySpace(Number(spaceId))
    docTypes.value = res.data?.doc_types ?? []
  } catch {
    docTypes.value = []
  } finally {
    docLoading.value = false
  }
}

/** 文章创建选择文档类型时：把其默认可见范围带入「可见范围」，restricted 时自动填入可见名单 */
function onDocTypeChange(): void {
  const id = Number(form.doc_type_id || 0)
  if (!id) return
  const dt = docTypes.value.find((d) => d.id === id)
  if (!dt) return
  form.view_scope = dt.visibility
  if (dt.visibility === 'restricted') {
    viewerGrants.value = (dt.subjects ?? []).map((s) => ({
      subject_type: s.subject_type,
      subject_id: Number(s.subject_id),
      name: '',
    }))
    grantTab.value = 'viewer'
    void resolveGrantNames(viewerGrants.value)
  } else {
    viewerGrants.value = []
  }
}

async function refreshCategories(spaceId: number, force = false): Promise<void> {
  categoryLoading.value = true
  try {
    await wiki.loadAdminCategories(spaceId, force)
  } catch {
    // 接口失败时保持空态，由选择器给出「暂无目录」提示
  } finally {
    categoryLoading.value = false
  }
}

/** 新建目录后强制刷新缓存并自动选中，避免下拉看不到刚创建的目录 */
async function onCategoryCreated(id: number): Promise<void> {
  if (!form.space_id || !id) return
  // 门户侧栏用另一份分类缓存，一并失效，避免新建目录后侧栏还是旧结构
  wiki.invalidateCategories(Number(form.space_id))
  await refreshCategories(Number(form.space_id), true)
  form.category_id = id
  ElMessage.success('目录已创建并自动选中')
}

/** 后台 + 门户两套空间缓存合并；URL 指定的空间即使暂未命中也保留占位，
 *  避免 select 找不到 option 时显示空白。 */
const spaceOptions = computed(() => {
  const rows = mergeSpaceOptions(wiki.adminSpaces, wiki.spaces)
  if (form.space_id && !rows.some((space) => Number(space.id) === Number(form.space_id))) {
    // 极端情况下列表接口不可用，用 store 的名称快照兜底，不暴露内部 ID
    return [{ id: Number(form.space_id), name: wiki.currentSpaceName || '当前空间' }, ...rows]
  }
  return rows
})
const currentSpaceName = computed(
  () =>
    spaceOptions.value.find((space) => Number(space.id) === Number(form.space_id))?.name ?? '当前空间',
)

watch(
  () => form.space_id,
  async (value) => {
    // 初始化回填 space_id 时不能清空 category_id，否则编辑已有文章会丢掉所属目录
    if (initializing.value) return
    form.category_id = ''
    // 新建页手动改空间时让后台顶栏跟随，保持「当前空间」全局一致
    if (!routeId.value && value) wiki.setAdminSpaceId(Number(value))
    if (value) {
      await refreshCategories(Number(value))
      await loadDocTypes(Number(value))
      // 新建页切换空间后重新落到新空间的「未指定目录」
      if (!routeId.value) form.category_id = systemCategoryForForm() || ''
    }
  },
)

// ---------- 本地自动草稿：浏览器 / 电脑意外关闭后仍可恢复 ----------

const draftUserId = computed(() => Number(store.profile?.id || 0))
const draftArticleId = computed<number | 'new'>(() => routeId.value || 'new')
const baseUpdatedAt = ref<string | null>(null)
const draftActive = ref(false)
const dirty = ref(false)
/** 初始化回填表单与恢复草稿期间不写草稿，也不标记为「已修改」 */
let suppressDraft = true
let draftTimer: number | undefined
let draftFlushTimer: number | undefined
let draftBound = false

function draftPayload(): EditDraftPayload {
  return {
    form: JSON.parse(JSON.stringify(form)) as Record<string, unknown>,
    editorGrants: JSON.parse(JSON.stringify(editorGrants.value)) as unknown[],
    viewerGrants: JSON.parse(JSON.stringify(viewerGrants.value)) as unknown[],
    attachments: JSON.parse(JSON.stringify(attachments.value)) as unknown[],
    baseUpdatedAt: baseUpdatedAt.value,
  }
}

function flushDraft(): void {
  if (!draftUserId.value || !draftActive.value) return
  writeDraft(draftUserId.value, draftArticleId.value, draftPayload())
}

function scheduleDraft(): void {
  if (suppressDraft || !draftActive.value) return
  dirty.value = true
  window.clearTimeout(draftTimer)
  draftTimer = window.setTimeout(flushDraft, 1000)
}

function applyDraft(draft: EditDraft): void {
  suppressDraft = true
  Object.assign(form, draft.form as Partial<typeof form>)
  editorGrants.value = draft.editorGrants as GrantRow[]
  viewerGrants.value = draft.viewerGrants as GrantRow[]
  attachments.value = draft.attachments as AttRow[]
  baseUpdatedAt.value = draft.baseUpdatedAt
  form.content = normalizeForEditor(String(form.content || ''))
  suppressDraft = false
}

async function checkDraft(): Promise<void> {
  if (!draftUserId.value) return
  const draft = readDraft(draftUserId.value, draftArticleId.value)
  if (!draft) {
    draftActive.value = true
    return
  }

  const when = new Date(draft.savedAt).toLocaleString('zh-CN')
  const conflict =
    draft.baseUpdatedAt && baseUpdatedAt.value && draft.baseUpdatedAt !== baseUpdatedAt.value
      ? '\n服务端文章在此期间已有更新，恢复可能覆盖他人改动。'
      : ''
  try {
    await ElMessageBox.confirm(`检测到 ${when} 的未保存草稿，是否恢复？${conflict}`, '恢复草稿', {
      type: 'warning',
      confirmButtonText: '恢复草稿',
      cancelButtonText: '放弃草稿',
      distinguishCancelAndClose: true,
    })
    applyDraft(draft)
    dirty.value = true
    ElMessage.success('草稿已恢复')
  } catch (action) {
    if (action === 'cancel') clearDraft(draftUserId.value, draftArticleId.value)
  }
  draftActive.value = true
}

function onVisibilityChange(): void {
  if (document.visibilityState === 'hidden') flushDraft()
}

function onBeforeUnload(event: BeforeUnloadEvent): void {
  flushDraft()
  if (!dirty.value) return
  event.preventDefault()
  event.returnValue = ''
}

function bindDraftEvents(): void {
  if (draftBound) return
  draftBound = true
  window.addEventListener('pagehide', flushDraft)
  document.addEventListener('visibilitychange', onVisibilityChange)
  window.addEventListener('beforeunload', onBeforeUnload)
  // 定时兜底：防抖之外，长文编辑期间也保证最多 30 秒落盘一次
  draftFlushTimer = window.setInterval(flushDraft, 30_000)
}

watch(form, scheduleDraft, { deep: true })

onBeforeRouteLeave((_to, _from, next) => {
  if (!dirty.value) {
    next()
    return
  }
  ElMessageBox.confirm('当前有未保存的修改，已自动存为本地草稿。确定离开吗？', '未保存的修改', {
    type: 'warning',
    confirmButtonText: '离开',
    cancelButtonText: '继续编辑',
  })
    .then(() => {
      flushDraft()
      dirty.value = false
      next()
    })
    .catch(() => next(false))
})

// ---------- 历史版本 ----------

const versionOpen = ref(false)
const versions = ref<ArticleVersion[]>([])
const versionLoading = ref(false)
const versionPreview = ref<ArticleVersion | null>(null)

async function openVersions(): Promise<void> {
  if (!routeId.value) return
  versionOpen.value = true
  versionLoading.value = true
  versionPreview.value = null
  try {
    const res = await articleApi.versions(routeId.value)
    versions.value = res.data?.list ?? []
  } catch {
    versions.value = []
  } finally {
    versionLoading.value = false
  }
}

async function previewVersion(row: ArticleVersion): Promise<void> {
  try {
    const res = await articleApi.versionDetail(routeId.value, row.id)
    versionPreview.value = res.data?.version ?? null
  } catch {
    versionPreview.value = null
  }
}

async function restoreVersion(row: ArticleVersion): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `确定把文章恢复到第 ${row.version_no} 版吗？当前内容会先自动留一条历史版本。`,
      '恢复历史版本',
      { type: 'warning', confirmButtonText: '恢复', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  await articleApi.restoreVersion(routeId.value, row.id)
  ElMessage.success('已恢复到该版本')
  versionOpen.value = false
  versionPreview.value = null
  clearDraft(draftUserId.value, draftArticleId.value)
  dirty.value = false
  await loadArticle()
}

function restorePreview(): void {
  if (versionPreview.value) void restoreVersion(versionPreview.value)
}

onMounted(async () => {
  // 创建 / 新增文章时自动展开设置抽屉；编辑已有文章由「设置」按钮打开
  settingsOpen.value = !routeId.value
  if (!store.profile) await store.fetchProfile().catch(() => undefined)
  pruneDrafts()
  await loadArticle()
  await checkDraft()
  bindDraftEvents()
  suppressDraft = false
})

onBeforeUnmount(() => {
  window.clearTimeout(draftTimer)
  window.clearInterval(draftFlushTimer)
  window.removeEventListener('pagehide', flushDraft)
  document.removeEventListener('visibilitychange', onVisibilityChange)
  window.removeEventListener('beforeunload', onBeforeUnload)
})
</script>

<template>
  <div class="page-max" style="padding: 6px 28px 40px">
    <div class="toolbar rise d1" style="margin-bottom: 6px">
      <button type="button" class="btn btn-ghost" @click="goBack">← 返回列表</button>
      <div class="sp"></div>
      <button v-if="routeId" type="button" class="btn" @click="openVersions">历史版本</button>
      <button type="button" class="btn" @click="settingsOpen = true">
        <Settings2 :size="14" /> 设置
      </button>
      <button type="button" class="btn" :disabled="saving || !canSubmit" @click="saveArticle(form.status)">
        保存（{{ form.status === 'draft' ? '草稿' : form.status === 'published' ? '已发布' : '已下架' }}）
      </button>
      <button type="button" class="btn btn-primary" :disabled="saving || !canSubmit" @click="saveArticle('published')">
        发布
      </button>
    </div>

    <div v-if="loading" class="kb-empty">加载中…</div>

    <!-- ========== 文章设置：右侧抽屉（与空间管理「新建空间」一致） ========== -->
    <el-drawer v-model="settingsOpen" title="文章设置" size="720px">
      <div class="card-solid">
          <div class="form-section">
            <div class="form-section-title">基础信息</div>
          <div v-if="!routeId && form.space_id" class="cell-sub" style="margin: -4px 0 14px">
            已自动带入当前空间：<b style="color: var(--primary)">{{ currentSpaceName }}</b>（可在此更改）
          </div>
          <div class="field">
            <label>文章标题 <span class="char-count">{{ form.title.length }} / {{ MAX_TITLE_LENGTH }}</span></label>
            <input
              v-model="form.title"
              class="input"
              :maxlength="MAX_TITLE_LENGTH"
              placeholder="最多 16 个字，卡片一行放不下时自动省略"
            />
          </div>
          <div class="field">
            <label>所属空间</label>
            <select v-model="form.space_id" class="select" style="height: 38px">
              <option value="">请选择空间</option>
              <option v-for="space in spaceOptions" :key="space.id" :value="space.id">
                {{ space.name }}
              </option>
            </select>
          </div>
          <div class="field cat-field">
            <div class="cat-field-head">
              <label for="cat-picker-trigger">所属目录</label>
              <button
                v-if="canManageCategory && form.space_id"
                type="button"
                class="btn btn-sm cat-add-btn"
                @click="categoryDialogOpen = true"
              >
                <FolderPlus :size="14" /> 新增目录
              </button>
            </div>
            <KbCategoryPicker
              v-model="form.category_id"
              :categories="categoryTree"
              :space-name="currentSpaceName"
              :loading="categoryLoading"
              :disabled="!form.space_id"
            />
            <div class="cell-sub">目录决定文章在知识目录树中的位置；未选择时默认归入「未指定目录」。</div>
          </div>
          <div class="field cat-field">
            <div class="cat-field-head">
              <label>文档类型（分类）</label>
            </div>
            <select v-model="form.doc_type_id" class="select" style="height: 38px" :disabled="!form.space_id" @change="onDocTypeChange">
              <option value="">请选择文档类型</option>
              <option v-for="dt in docTypes" :key="dt.id" :value="dt.id">{{ dt.name }}</option>
            </select>
            <div class="cell-sub">
              文章的分类来源「文档类型」（与目录相互独立）；选择带默认可见范围的类型会自动带入下方授权名单。
            </div>
          </div>
          <div class="field">
            <label>摘要 <span class="char-count">{{ form.summary.length }} / {{ MAX_SUMMARY_LENGTH }}</span></label>
            <textarea
              v-model="form.summary"
              class="input"
              rows="2"
              :maxlength="MAX_SUMMARY_LENGTH"
              placeholder="展示在列表卡片，最多 36 字"
              @input="onSummaryInput"
            ></textarea>
            <div class="cell-sub">卡片固定预留 2 行；最多 36 字可保证完整显示，换行会自动合并为空格。</div>
          </div>
          <div class="field" style="margin-bottom: 0">
            <label>标题色带</label>
            <div class="band-colors">
              <button
                v-for="color in CARD_COLORS"
                :key="color"
                type="button"
                class="band-color"
                :class="[color, { active: form.band_color === color }]"
                :aria-label="`色块 ${color}`"
                :aria-pressed="form.band_color === color"
                @click="form.band_color = color"
              >
                <Check v-if="form.band_color === color" :size="15" />
              </button>
            </div>
            <div class="cell-sub">卡片顶部满宽色带，共 8 种颜色（不含黑色）；历史文章未设置时按 ID 自动分配。</div>
          </div>
        </div>

        <div class="form-sep"></div>
        <div class="form-section">
          <div class="form-section-title">发布设置</div>
          <div class="form-row">
            <div class="field" style="margin-bottom: 0">
              <label>状态</label>
              <select v-model="form.status" class="select" style="height: 38px">
                <option value="draft">草稿</option>
                <option value="published">已发布</option>
                <option value="offline">已下架</option>
              </select>
            </div>
            <div class="field" style="margin-bottom: 0">
              <label>作者</label>
              <div class="input fake-input">
                {{ store.displayName || '当前账号' }}（自动带入）
              </div>
            </div>
          </div>
        </div>

        <div class="form-sep"></div>
        <div class="form-section">
          <div class="form-section-title">可见范围</div>
          <div class="scope-opts">
            <div
              v-for="option in VIEW_SCOPES"
              :key="option.value"
              class="scope-opt"
              :class="{ active: form.view_scope === option.value }"
              @click="form.view_scope = option.value"
            >
              <span class="radio"></span>
              <div><b>{{ option.title }}</b><p>{{ option.desc }}</p></div>
            </div>
          </div>
        </div>

        <div class="form-sep"></div>
        <div class="form-section">
          <div class="form-section-title">可编辑范围</div>
          <div class="scope-opts">
            <div
              v-for="option in EDIT_MODES"
              :key="option.value"
              class="scope-opt"
              :class="{ active: form.edit_mode === option.value }"
              @click="form.edit_mode = option.value"
            >
              <span class="radio"></span>
              <div><b>{{ option.title }}</b><p>{{ option.desc }}</p></div>
            </div>
          </div>

          <div style="margin-top: 14px">
            <div style="font-size: 13px; font-weight: 500; color: var(--text-2); margin-bottom: 8px">
              授权名单（角色 + 人员）
            </div>
            <div class="grant-panel">
              <div class="grant-head">
                <button
                  type="button"
                  class="grant-tab"
                  :class="{ active: grantTab === 'editor' }"
                  @click="grantTab = 'editor'"
                >
                  可编辑名单（{{ editorGrants.length }}）
                </button>
                <button
                  type="button"
                  class="grant-tab"
                  :class="{ active: grantTab === 'viewer' }"
                  @click="grantTab = 'viewer'"
                >
                  可见名单（{{ viewerGrants.length }}）
                </button>
              </div>

              <div v-for="row in grantRows" :key="`${row.subject_type}-${row.subject_id}`" class="grant-row">
                <div class="who">
                  <span :class="row.subject_type === 'role' ? 'tag-role' : 'tag-user'">
                    {{ row.subject_type === 'role' ? '角色' : '人员' }}
                  </span>
                  {{ subjectName(row) }}
                </div>
                <div class="grant-checks">
                  <span class="chk on"><i></i>{{ grantTab === 'editor' ? '可编辑' : '可查看' }}</span>
                </div>
                <button type="button" class="link danger" @click="removeGrant(row)">移除</button>
              </div>

              <div v-if="!grantRows.length" class="cell-sub" style="padding: 12px 14px">
                暂无{{ grantTab === 'editor' ? '可编辑' : '可见' }}授权对象
              </div>
            </div>

            <div class="grant-add">
              <select v-model="newGrantType" class="select grant-add-type">
                <option value="role">按角色</option>
                <option value="user">按人员</option>
              </select>
              <KbSubjectPicker
                :type="newGrantType"
                :selected-ids="currentSelectedIds"
                @select="addGrant"
              />
            </div>
            <div class="cell-sub" style="margin-top: 8px">
              说明：文章级授权只有「可查看 / 可编辑」两档；「可管理」属于空间级授权（空间管理页配置）。
              输入角色名称 / 编码或人员姓名 / 账号搜索，选中即加入当前名单。
            </div>
          </div>
        </div>
        </div>
      <div class="settings-drawer-foot">
        <button type="button" class="btn btn-primary" @click="settingsOpen = false">完成</button>
      </div>
    </el-drawer>

    <!-- ========== 历史版本（只存文本快照，不复制附件） ========== -->
    <el-drawer v-model="versionOpen" title="历史版本" size="760px">
      <div v-if="versionLoading" class="kb-empty">加载中…</div>
      <div v-else-if="!versions.length" class="kb-empty">暂无历史版本</div>
      <div v-else class="version-layout">
        <div class="version-list">
          <div
            v-for="row in versions"
            :key="row.id"
            class="version-item"
            :class="{ active: versionPreview?.id === row.id }"
            @click="previewVersion(row)"
          >
            <div class="cell-title">第 {{ row.version_no }} 版 · {{ row.title }}</div>
            <div class="cell-sub">{{ row.created_at }} · 操作人 #{{ row.created_by }}</div>
          </div>
        </div>
        <div class="version-preview">
          <template v-if="versionPreview">
            <div class="article-body" v-html="versionPreview.content || ''"></div>
            <button type="button" class="btn btn-primary" style="margin-top: 12px" @click="restorePreview">
              恢复此版本
            </button>
          </template>
          <div v-else class="cell-sub">点击左侧版本查看正文预览</div>
        </div>
      </div>
    </el-drawer>

    <!-- ========== 正文 ========== -->
    <div v-if="!loading" class="edit-grid">
      <aside class="edit-toc">
        <div class="card-solid">
          <div class="panel-title">本页目录</div>
          <template v-if="editorToc.length">
            <div
              v-for="(item, index) in editorToc"
              :key="item.id"
              class="toc-item"
              :class="[`toc-l${item.level}`, { active: activeEditorToc === index }]"
              @click="jumpToEditorHeading(index)"
            >
              <span class="toc-label">{{ item.text }}</span>
            </div>
          </template>
          <div v-else class="cell-sub">暂无目录：在正文中添加 H1 / H2 / H3 标题后会实时出现在这里</div>
        </div>
      </aside>

      <div class="edit-main">
      <div class="card-solid" style="padding: 0; overflow: hidden">
        <div class="side-card-title" style="padding: 14px 16px 0">正文编辑</div>

        <div class="kb-toolbar-row">
          <Toolbar class="kb-toolbar" :editor="editorRef" :default-config="toolbarConfig" mode="default" />
          <div class="kb-toolbar-actions">
            <button type="button" class="btn btn-sm kb-btn-accent" @click="openInsert('video')">
              <Film :size="14" /> 插入视频
            </button>
            <button type="button" class="btn btn-sm kb-btn-accent" @click="openInsert('file')">
              <Paperclip :size="14" /> 插入附件
            </button>
          </div>
        </div>

        <Editor
          v-model="form.content"
          class="kb-editor"
          :default-config="editorConfig"
          mode="default"
          @onCreated="onCreated"
          @customPaste="onCustomPaste"
        />
      </div>

      <div class="card-solid" style="margin-top: 20px">
        <div class="side-card-title">视频（支持分片 · 断点续传 · 单个最大 {{ videoMaxText }}）</div>
        <div v-for="row in videoAttachments" :key="row.id" style="display: flex; align-items: center; gap: 12px; padding: 8px 0">
          <div class="thumb" style="width: 38px; height: 38px; border-radius: 8px">
            <div class="ph g2"></div>
          </div>
          <div style="flex: 1; min-width: 0">
            <div style="font-size: 13px" class="truncate">{{ row.name }}</div>
            <div class="cell-sub">
              视频
              <template v-if="row.size"> · {{ formatBytes(row.size) }}</template>
              <template v-if="row.duration"> · {{ formatDuration(row.duration) }}</template>
              · {{ attachmentStatusText(row.status) }}
            </div>
          </div>
          <button type="button" class="link danger" title="移除视频并清理正文占位" @click="dropAttachment(row.id)">
            移除
          </button>
        </div>
        <div v-if="!videoAttachments.length" class="cell-sub" style="padding: 4px 0 8px">暂无视频，上传后会自动出现在这里</div>
        <div style="margin-top: 12px">
          <KbChunkUploader
            type="video"
            :tip="videoTip"
            @uploaded="onUploadCommitted"
            @done="onUploaded"
            @insert="onInsertRequest"
            @remove="dropAttachment"
          />
        </div>
      </div>

      <div class="card-solid" style="margin-top: 20px">
        <div class="side-card-title">附件（单个最大 {{ fileMaxText }}）</div>
        <div v-for="row in fileAttachments" :key="row.id" style="display: flex; align-items: center; gap: 12px; padding: 8px 0">
          <div class="thumb" style="width: 38px; height: 38px; border-radius: 8px">
            <div class="ph" :class="row.type === 'video' ? 'g2' : 'g1'"></div>
          </div>
          <div style="flex: 1; min-width: 0">
            <div style="font-size: 13px" class="truncate">{{ row.name }}</div>
            <div class="cell-sub">
              {{ row.type === 'video' ? '视频' : '附件' }}
              <template v-if="row.size"> · {{ formatBytes(row.size) }}</template>
              <template v-if="row.duration"> · {{ formatDuration(row.duration) }}</template>
              · {{ attachmentStatusText(row.status) }}
            </div>
          </div>
          <button
            type="button"
            class="link danger"
            title="移除附件并清理正文占位"
            @click="dropAttachment(row.id)"
          >
            移除
          </button>
        </div>
        <div v-if="!fileAttachments.length" class="cell-sub" style="padding: 4px 0 8px">暂无附件，上传后会自动出现在这里</div>

        <div style="margin-top: 12px">
          <KbChunkUploader
            type="file"
            :tip="fileTip"
            @uploaded="onUploadCommitted"
            @done="onUploaded"
            @insert="onInsertRequest"
            @remove="dropAttachment"
          />
        </div>
      </div>
      </div>
    </div>

    <!-- 插入视频 / 附件选择 -->
    <el-dialog v-model="insertDialog" :title="insertKind === 'video' ? '插入视频' : '插入附件'" width="520px">
      <div v-if="candidates.length">
        <div
          v-for="row in candidates"
          :key="row.id"
          style="display: flex; align-items: center; gap: 12px; padding: 10px 4px; border-bottom: 1px solid #f0f3f9"
        >
          <div style="flex: 1; min-width: 0">
            <div style="font-size: 14px" class="truncate">{{ row.name }}</div>
            <div class="cell-sub">附件 #{{ row.id }}</div>
          </div>
          <button type="button" class="btn btn-sm" @click="insertIntoEditor(row)">插入</button>
        </div>
      </div>
      <div v-else class="kb-empty" style="padding: 28px 12px">
        暂无可插入的{{ insertKind === 'video' ? '视频' : '附件' }}，请先在下方上传面板完成上传（附件入库后自动加入列表）。
      </div>
      <template #footer>
        <button type="button" class="btn" @click="insertDialog = false">关闭</button>
      </template>
    </el-dialog>

    <KbCategoryCreateDialog
      v-model:open="categoryDialogOpen"
      :space-id="form.space_id"
      :space-name="currentSpaceName"
      :categories="categoryTree"
      :default-parent-id="form.category_id"
      @created="onCategoryCreated"
    />
  </div>
</template>

<style scoped>
/* wangEditor 工具栏行：左侧官方格式工具栏 + 右侧「插入视频 / 附件」动作按钮 */
.kb-toolbar-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: none;
  padding: 6px 12px;
  background: #fafbfe;
  border-bottom: 1px solid var(--line);
  position: relative;
  z-index: 1;
}
.kb-toolbar {
  flex: 1;
  min-width: 0;
}
.kb-toolbar :deep(.w-e-toolbar) {
  background: transparent !important;
  border: 0 !important;
  padding: 0 !important;
}
.kb-toolbar-actions {
  display: flex;
  gap: 8px;
  flex: none;
}
.kb-btn-accent {
  background: #e9effd;
  border-color: transparent;
  color: var(--primary);
}
/* 正文在视口内独立滚动：工具栏保持静止，长文无需回滚到顶部选功能 */
.kb-editor {
  --kb-editor-offset: 228px;
  /* wangEditor 会给根元素写 height:100%，这里必须覆盖才能形成固定视口滚动区 */
  height: clamp(var(--read-min-height), calc(100vh - var(--kb-editor-offset)), 920px) !important;
  min-height: var(--read-min-height);
  overflow: hidden;
  max-width: var(--read-text);
  margin-inline: auto;
}
.kb-editor :deep(.w-e-text-container) {
  background: #fff;
  height: 100%;
  min-height: 0;
  border: 0;
}
.kb-editor :deep(.w-e-scroll) {
  height: 100%;
  min-height: 0;
  overflow-y: auto;
  scrollbar-gutter: stable;
}
.kb-editor :deep([data-w-e-textarea]) {
  min-height: 100%;
  font-size: 15px;
  line-height: 1.9;
}
/* 工具栏与正文对齐，所见即所得 */
.kb-toolbar {
  max-width: var(--read-text);
  margin-inline: auto;
}
/* 编辑时图片 / 视频 / 表格不撑破容器 */
.kb-editor :deep([data-w-e-textarea]) img,
.kb-editor :deep([data-w-e-textarea]) video,
.kb-editor :deep([data-w-e-textarea]) table {
  max-width: 100%;
  height: auto;
}
/* 编辑视图：左侧实时目录与阅读页一致，右侧保持原有编辑区 */
.edit-grid {
  display: grid;
  grid-template-columns: 268px minmax(0, 1fr);
  gap: 24px;
  align-items: start;
}
.edit-toc {
  position: sticky;
  top: 84px;
}
@media (max-width: 1100px) {
  .edit-grid {
    grid-template-columns: 1fr;
  }
  .edit-toc {
    position: static;
  }
  .kb-editor {
    --kb-editor-offset: 248px;
  }
}
/* 历史版本抽屉：左侧版本列表 + 右侧正文预览 */
.version-layout {
  display: grid;
  grid-template-columns: 240px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}
.version-item {
  padding: 10px 12px;
  margin-bottom: 8px;
  border: 1px solid var(--line);
  border-radius: var(--r-xs);
  cursor: pointer;
}
.version-item.active {
  border-color: var(--primary);
  background: #f2f6fe;
}
.version-preview {
  min-height: 200px;
  overflow-wrap: anywhere;
}
</style>
