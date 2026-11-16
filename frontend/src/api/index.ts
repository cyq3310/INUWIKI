import request from './request'
import type {
  ArticleItem,
  ArticleStats,
  ArticleVersion,
  Attachment,
  AttachmentType,
  CategoryNode,
  DocType,
  DocTypeSubject,
  DocTypeVisibility,
  GrantItem,
  OperationLogItem,
  OperationLogQuery,
  PageResult,
  PermissionDef,
  PermissionGrant,
  SpaceItem,
  SpaceMemberItem,
  SubjectOption,
  SubjectType,
  UploadCheckResult,
  UploadInitPayload,
  UploadInitResult,
  UserProfile,
} from '../types'

// ---------- 认证 ----------
export const authApi = {
  login: (username: string, password: string) =>
    request.post('/auth/login', { username, password }) as Promise<{ data: { token: string; user: UserProfile } }>,
  logout: () => request.post('/auth/logout'),
  profile: () => request.get('/auth/profile') as Promise<{ data: UserProfile }>,
}

// ---------- 门户 ----------
export interface ArticleQuery {
  space_id?: number | ''
  category_id?: number | ''
  doc_type_id?: number | ''
  keyword?: string
  page?: number
  size?: number
}

export const portalApi = {
  /** 可见空间列表 */
  spaces: () => request.get('/spaces') as Promise<{ data: { spaces: SpaceItem[] } }>,
  /** 某空间的分类树（未授权空间返回空数组） */
  categories: (spaceId: number) =>
    request.get(`/spaces/${spaceId}/categories`) as Promise<{ data: { categories: CategoryNode[] } }>,
  /** 知识列表（仅已发布 + 可见空间 + 可见范围过滤后） */
  articles: (params: ArticleQuery) => request.get('/articles', { params }) as Promise<{ data: PageResult<ArticleItem> }>,
  /** 全站统计（口径 A：只含公开空间） */
  stats: () => request.get('/articles/stats') as Promise<{ data: ArticleStats }>,
  /** 知识详情（含 attachments） */
  article: (id: number) => request.get(`/articles/${id}`) as Promise<{ data: { article: ArticleItem } }>,
  /** 门户只读：已发布历史版本列表 */
  versions: (id: number) =>
    request.get(`/articles/${id}/versions`) as Promise<{ data: { list: ArticleVersion[] } }>,
  /** 门户只读：指定已发布历史版本正文 */
  version: (id: number, versionId: number) =>
    request.get(`/articles/${id}/versions/${versionId}`) as Promise<{ data: { version: ArticleVersion } }>,
}

// ---------- 后台：空间 ----------
export const spaceApi = {
  /** silent：空间列表探测失败会回落门户接口，属预期降级，不弹 403 提示 */
  list: (silent = false) =>
    request.get('/admin/spaces', { silent }) as Promise<{ data: { spaces: SpaceItem[] } }>,
  create: (data: Record<string, unknown>) => request.post('/admin/spaces', data) as Promise<{ data: { id: number } }>,
  update: (id: number, data: Record<string, unknown>) => request.put(`/admin/spaces/${id}`, data),
  remove: (id: number) => request.delete(`/admin/spaces/${id}`),
  members: (id: number) => request.get(`/admin/spaces/${id}/members`) as Promise<{ data: { members: SpaceMemberItem[] } }>,
  setMember: (
    id: number,
    data: { subject_type: SubjectType; subject_id: number; can_view: number; can_edit: number; can_manage: number },
  ) => request.post(`/admin/spaces/${id}/members`, data),
  removeMember: (id: number, subjectType: SubjectType, subjectId: number) =>
    request.delete(`/admin/spaces/${id}/members`, { params: { subject_type: subjectType, subject_id: subjectId } }),
}

// ---------- 后台：分类 ----------
export const categoryApi = {
  /** silent：目录树探测失败会回落门户目录树，属预期降级，不弹 403 提示 */
  list: (spaceId: number, silent = false) =>
    request.get('/admin/categories', { params: { space_id: spaceId }, silent }) as Promise<{ data: { categories: CategoryNode[] } }>,
  create: (data: { space_id: number; parent_id?: number; name: string; sort?: number }) =>
    request.post('/admin/categories', data) as Promise<{ data: { id: number } }>,
  update: (id: number, data: Record<string, unknown>) => request.put(`/admin/categories/${id}`, data),
  remove: (id: number) => request.delete(`/admin/categories/${id}`),
}

// ---------- 后台：文档类型（与目录相互独立） ----------
export const docTypeApi = {
  /** 后台列表（需 kb:doc_type:manage） */
  list: (spaceId: number) =>
    request.get('/admin/doc-types', { params: { space_id: spaceId } }) as Promise<{ data: { doc_types: DocType[] } }>,
  create: (data: { space_id: number; name: string; sort?: number; visibility?: DocTypeVisibility; subjects?: DocTypeSubject[] }) =>
    request.post('/admin/doc-types', data),
  update: (id: number, data: Record<string, unknown>) => request.put(`/admin/doc-types/${id}`, data),
  remove: (id: number) => request.delete(`/admin/doc-types/${id}`),
  /** 文章创建页读取（仅需登录） */
  bySpace: (spaceId: number) =>
    request.get(`/spaces/${spaceId}/doc-types`) as Promise<{ data: { doc_types: DocType[] } }>,
}

// ---------- 后台：文章 ----------
export interface AdminArticleQuery extends ArticleQuery {
  status?: string
}

export const articleApi = {
  /** 后台列表：含草稿与下架（按可见空间收敛） */
  list: (params: AdminArticleQuery) => request.get('/admin/articles', { params }) as Promise<{ data: PageResult<ArticleItem> }>,
  create: (data: Record<string, unknown>) => request.post('/admin/articles', data) as Promise<{ data: { id: number } }>,
  update: (id: number, data: Record<string, unknown>) => request.put(`/admin/articles/${id}`, data),
  remove: (id: number) => request.delete(`/admin/articles/${id}`),
  /** 上架 / 下架 */
  setStatus: (id: number, status: 'published' | 'offline') => request.post(`/admin/articles/${id}/status`, { status }),
  /** 授权名单：kind=editor 可编辑 / viewer 可见 */
  grants: (id: number, kind: 'editor' | 'viewer') =>
    request.get(`/admin/articles/${id}/grants`, { params: { kind } }) as Promise<{ data: { grants: GrantItem[] } }>,
  setGrants: (id: number, kind: 'editor' | 'viewer', subjects: { subject_type: SubjectType; subject_id: number }[]) =>
    request.post(`/admin/articles/${id}/grants`, { kind, subjects }),
  /**
   * 把已上传附件挂到文章上（后端 route/app.php 暂未提供该接口，属可选能力）。
   * 静默调用：后端未实现时不弹错误提示，前端降级为「本次会话附件」展示。
   */
  attach: (id: number, attachmentIds: number[]) =>
    request.post(`/admin/articles/${id}/attachments`, { attachment_ids: attachmentIds }, { silent: true }) as Promise<{
      data: unknown
    }>,
  /** 后台详情：编辑页刷新时取回完整行（草稿 / 下架也能拿到） */
  detail: (id: number) =>
    request.get(`/admin/articles/${id}`) as Promise<{ data: { article: ArticleItem } }>,
  /** 回收站：恢复 / 彻底清除（清除会立即物理删除附件与图片） */
  restore: (id: number) => request.post(`/admin/articles/${id}/restore`),
  purge: (id: number) => request.post(`/admin/articles/${id}/purge`),
  /** 历史版本：只存文本快照，回滚后当前内容也会留一条版本 */
  versions: (id: number) =>
    request.get(`/admin/articles/${id}/versions`) as Promise<{ data: { list: ArticleVersion[] } }>,
  versionDetail: (id: number, vid: number) =>
    request.get(`/admin/articles/${id}/versions/${vid}`) as Promise<{ data: { version: ArticleVersion } }>,
  restoreVersion: (id: number, vid: number) =>
    request.post(`/admin/articles/${id}/versions/${vid}/restore`),
}

// ---------- 后台：上传 ----------
export const uploadApi = {
  /** 初始化分片任务 */
  init: (payload: UploadInitPayload) =>
    request.post('/admin/kb/uploads/init', payload) as Promise<{ data: UploadInitResult }>,
  /** 已上传分片索引（断点续传） */
  check: (uploadId: string) =>
    request.get('/admin/kb/uploads/check', { params: { upload_id: uploadId } }) as Promise<{ data: UploadCheckResult }>,
  /** 单片上传 */
  chunk: (uploadId: string, index: number, blob: Blob, type: AttachmentType) => {
    const form = new FormData()
    form.append('file', blob)
    form.append('upload_id', uploadId)
    form.append('index', String(index))
    form.append('type', type)
    return request.post('/admin/kb/uploads/chunk', form) as Promise<{ data: { index: number } }>
  },
  /** 合并 + 入队转码，立即返回 */
  complete: (uploadId: string, type: AttachmentType) =>
    request.post('/admin/kb/uploads/complete', { upload_id: uploadId, type }) as Promise<{
      data: { attachment_id: number; status: string; type: string }
    }>,
  /** 附件状态轮询（pending / merging / transcoding / ready / failed） */
  attachment: (id: number) =>
    request.get('/admin/kb/attachments', { params: { id } }) as Promise<{ data: Attachment }>,
  /** 图片快捷上传（封面 / 正文配图） */
  image: (file: File) => {
    const form = new FormData()
    form.append('file', file)
    return request.post('/admin/kb/images', form) as Promise<{ data: { id: number; path: string } }>
  },
}

// ---------- 后台：权限配置 ----------
/** space_id=0 全局按角色；space_id>0 该空间按人员（整包覆盖全局） */
export const permissionApi = {
  /** 权限码清单 + 指定范围下所有已配置对象 */
  list: (spaceId = 0) =>
    request.get('/admin/permissions', { params: { space_id: spaceId } }) as Promise<{
      data: { permissions: PermissionDef[]; grants: PermissionGrant[] }
    }>,
  /** 空间人员配置必须至少包含的全局角色权限码 */
  globalRequired: (spaceId: number, subjectId: number) =>
    request.get('/admin/permissions/global-required', {
      params: { space_id: spaceId, subject_id: subjectId },
    }) as Promise<{ data: { codes: string[] } }>,
  /** 全量覆盖某个角色 / 人员在指定范围的权限码集合 */
  save: (data: { space_id?: number; subject_type: SubjectType; subject_id: number; codes: string[] }) =>
    request.post('/admin/permissions', { space_id: 0, ...data }),
  /** 移除某个对象在指定范围的全部配置（移除后该空间回落全局） */
  remove: (spaceId: number, subjectType: SubjectType, subjectId: number) =>
    request.post('/admin/permissions/remove', {
      space_id: spaceId,
      subject_type: subjectType,
      subject_id: subjectId,
    }),
}

// ---------- 后台：操作日志 ----------
/** 只读查询数据库操作日志；actions 为当前库内已有动作，用于筛选下拉 */
export const oplogApi = {
  list: (params: OperationLogQuery = {}) =>
    request.get('/admin/operation-logs', { params }) as Promise<{
      data: PageResult<OperationLogItem> & {
        actions: string[]
        source?: string
        notice?: string
      }
    }>,
}

// ---------- 可选能力（后端暂未提供，静默降级） ----------

/** 后台存储 / 转码统计。后端 route/app.php 暂无此接口，返回 null 时前端显示占位。 */
export const kbStatsApi = {
  fetch: () =>
    request.get('/admin/kb/stats', { silent: true }) as Promise<{
      data: { used_bytes: number; total_bytes: number; transcoding: number; failed: number }
    }>,
}

/**
 * 角色 / 人员目录（GET /kbapi/admin/subjects，展示字段来自 WIKI 本地快照 kb_subject）。
 * 快照由 kb:subject-sync 定时从 EIP 同步；人员的 is_superadmin 标记由服务端实时补充，
 * EIP 角色库不可用时降级为未标记，不影响搜索与名称解析。
 * search 用于添加授权时的搜索选择；resolve 按 ID 精确解析已授权名单的名称，
 * 不受搜索模式的 20 条上限限制，也能解析已停用 / 软删的历史对象。
 * 快照未就绪或查询异常时静默失败，页面降级为「角色 / 人员 #ID」。
 */
export const directoryApi = {
  search: (type: SubjectType, keyword: string) =>
    request.get('/admin/subjects', { params: { type, keyword }, silent: true }) as Promise<{
      data: { subjects: SubjectOption[] }
    }>,
  resolve: (type: SubjectType, ids: number[]) =>
    request.get('/admin/subjects', { params: { type, ids: ids.join(',') }, silent: true }) as Promise<{
      data: { subjects: SubjectOption[] }
    }>,
}
