// ---------- 认证 ----------

/** 登录用户档案（后端 AuthService::buildProfile 结构） */
export interface UserProfile {
  id: number
  username: string
  nickname: string
  avatar: string | null
  /** 角色 code 集合 */
  roles: string[]
  /** 角色 ID 集合（空间按角色授权时用 inue_role.id 匹配） */
  roleIds: number[]
  isSuperAdmin: boolean
  /** 权限码集合，如 kb:article:create */
  permissions: string[]
}

// ---------- 空间 / 分类 ----------

export type SubjectType = 'user' | 'role'

// ---------- 后台：权限配置 ----------

/** 可分配的权限码（后端 admin.Permission 下发） */
export interface PermissionDef {
  code: string
  name: string
  group: string
}

/** 一个角色 / 人员被授予的权限码集合 */
export interface PermissionGrant {
  subject_type: SubjectType
  subject_id: number
  codes: string[]
}

export interface SpaceItem {
  id: number
  name: string
  code: string
  description: string | null
  cover: string | null
  /** 0 公开 / 1 受限 */
  is_restricted: number
  status: number
  sort: number
  created_by?: number
  created_at?: string
  updated_at?: string
  /** 后台列表附带 */
  article_count?: number
  /** 后台列表附带 */
  member_count?: number
}

export interface CategoryNode {
  id: number
  space_id: number
  parent_id: number
  name: string
  sort: number
  status: number
  /** 已发布文章数（后端 treeBySpace 附带） */
  article_count?: number
  /** 1 = 空间默认存在的「未指定目录」，永远置底且不可改名 / 移动 / 删除 */
  is_system?: number
  children?: CategoryNode[]
}

/** 空间成员授权项 */
export interface SpaceMemberItem {
  id: number
  space_id: number
  subject_type: SubjectType
  subject_id: number
  can_view: number
  can_edit: number
  can_manage: number
  created_at?: string
}

// ---------- 文档类型（与目录相互独立） ----------

export type DocTypeVisibility = 'public' | 'space' | 'restricted'

export interface DocTypeSubject {
  subject_type: SubjectType
  subject_id: number
}

export interface DocType {
  id: number
  space_id: number
  name: string
  sort: number
  visibility: DocTypeVisibility
  subjects: DocTypeSubject[]
  is_default?: number
  created_at?: string
  updated_at?: string
}

// ---------- 文章 ----------

export type ArticleStatus = 'draft' | 'published' | 'offline' | 'deleted'
export type ViewScope = 'public' | 'space' | 'restricted'
export type EditMode = 'open' | 'restricted'
export type AttachmentType = 'image' | 'video' | 'file'
export type AttachmentStatus = 'pending' | 'merging' | 'transcoding' | 'ready' | 'failed'

export interface ArticleItem {
  id: number
  space_id: number
  category_id: number
  doc_type_id: number
  title: string
  summary: string | null
  cover: string | null
  /** 卡片标题色带颜色：g1 ~ g8；历史文章为 null 时按 ID 稳定分配 */
  band_color: string | null
  /** 列表接口按 doc_type_id 批量补全的文档类型名称（未指定类型时为空串） */
  doc_type_name?: string
  /** 仅详情接口返回：后端 canEditArticle 的判定结果，控制「编辑」按钮 */
  can_edit?: boolean
  content: string
  status: ArticleStatus
  view_scope: ViewScope
  edit_mode: EditMode
  author_id: number
  view_count: number
  published_at: string | null
  created_at: string
  updated_at: string
  /** 仅详情接口返回 */
  attachments?: Attachment[]
}

/** 文章历史版本：列表不含正文，详情额外返回 content */
export interface ArticleVersion {
  id: number
  article_id: number
  version_no: number
  title: string
  status: ArticleStatus
  created_by: number
  created_at: string
  content?: string
}

export interface Attachment {
  id: number
  article_id: number
  type: AttachmentType
  original_name: string
  /** 相对路径，展示时用 knowledge.upload_prefix 拼接 */
  path: string
  thumb_path: string | null
  mime: string | null
  size: number
  /** 视频时长（秒） */
  duration: number
  status: AttachmentStatus
  fail_reason?: string | null
  created_by?: number
  created_at?: string
}

export interface ArticleStats {
  article_count: number
  video_count: number
  view_count: number
}

/** 授权名单项（可编辑 / 可见） */
export interface GrantItem {
  id?: number
  article_id: number
  subject_type: SubjectType
  subject_id: number
}

// ---------- 上传 ----------

export interface UploadInitPayload {
  filename: string
  size: number
  type: AttachmentType
}

export interface UploadInitResult {
  upload_id: string
  chunk_size: number
  chunk_total: number
}

export interface UploadCheckResult {
  upload_id: string
  chunk_total: number
  /** 已上传的分片索引 */
  uploaded: number[]
  status: string
}

// ---------- 通用 ----------

export interface PageResult<T> {
  total: number
  page: number
  size: number
  list: T[]
}

/** 后台知识库运行统计（GET /kbapi/admin/kb/stats） */
export interface KbStats {
  used_bytes: number
  total_bytes: number
  transcoding: number
  failed: number
}

/** 后台操作日志（数据库分区表 operation_log） */
export interface OperationLogItem {
  id: number
  user_id: number
  display_user_id?: number
  username: string | null
  action: string
  detail: string | null
  ip: string | null
  method: string | null
  path: string | null
  result: string
  object_type?: string
  object_id: number
  object_label?: string
  space_id: number
  space_name?: string
  duration_ms: number
  created_at: string
}

export interface OperationLogQuery {
  page?: number
  size?: number
  keyword?: string
  username?: string
  action?: string
  result?: string
  date_from?: string
  date_to?: string
}

/** 统一响应体（响应拦截器直接返回整个 body） */
export interface ApiResult<T> {
  code: number
  msg: string
  data: T
}

/** 角色 / 人员目录项（来自 WIKI 本地快照，仅含展示所需字段） */
export interface SubjectOption {
  subject_type: SubjectType
  subject_id: number
  /** 展示名：角色取 name、人员取 nickname（回退账号） */
  name: string
  /** 角色编码或登录账号，用于重名区分 */
  code?: string
  /** 1 启用 / 0 停用；历史授权对象可能为 0 */
  status?: number
  /** 人员是否拥有 super_admin 角色；仅 user 类型返回 */
  is_superadmin?: boolean
}
