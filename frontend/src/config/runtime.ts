import axios from 'axios'

/**
 * 运行时配置：应用启动时从后端 GET /kbapi/config 拉取，前后端共用同一份 backend/config/myconfig.php
 * （仅 frontend_public.keys 白名单内的键会下发）。接口不可用时使用内置默认值降级，不阻断首屏渲染。
 */

export interface RuntimeConfig {
  /** 环境标识：local | staging | production */
  env: string
  site: { name: string; version: string; copyright: string; icp: string }
  /** api.base_url 为最终使用的接口根地址；api.timeout 单位毫秒 */
  api: { base_url: string; timeout: number }
  web: { base_url: string }
  /** EIP 门户地址（返回门户跳转用） */
  eip: { base_url: string }
  resource: { mode: 'local' | 'cdn'; base_url: string }
  portal: { page_size: number }
}

/** 内置默认值：与后端 myconfig 缺省值保持一致，仅作降级使用 */
const DEFAULT_CONFIG: RuntimeConfig = {
  env: 'production',
  site: { name: '吟游廊 WIKI', version: '1.0.0', copyright: 'INUEIP', icp: '' },
  api: { base_url: '/kbapi', timeout: 15000 },
  web: { base_url: '' },
  eip: { base_url: '' },
  resource: { mode: 'local', base_url: '' },
  portal: { page_size: 12 },
}

const CONFIG_URL = '/kbapi/config'

/**
 * 上传资源静态前缀：仅 CDN 模式或运维直出时使用，与 myconfig 的 knowledge.upload_prefix 一致。
 * 库里只存相对路径（如 image/2026/11/xxx.png），展示时在此拼接。
 */
export const KB_UPLOAD_PREFIX = '/uploads/knowledge'

/**
 * 站内图片资源接口：URL 只带稳定的 path（不含任何凭证），由后端校验
 * HttpOnly cookie 中的资源票据与文章可见性。可安全写进正文 HTML 长期保存。
 */
export const KB_ASSET_URL = '/kbapi/assets'

let cached: RuntimeConfig = { ...DEFAULT_CONFIG }

/** 用后端返回值覆盖默认值，缺字段保持默认，避免结构不完整导致前端报错 */
function normalize(data: Record<string, unknown>): RuntimeConfig {
  const site = (data.site ?? {}) as RuntimeConfig['site']
  const deploy = (data.deploy ?? {}) as {
    env?: string
    api?: { base_url?: string; timeout?: number }
    web?: { base_url?: string }
    eip?: { base_url?: string }
    resource?: { mode?: 'local' | 'cdn'; base_url?: string }
  }
  const apiTimeout = Number(deploy.api?.timeout ?? 0)
  return {
    env: deploy.env ?? DEFAULT_CONFIG.env,
    site: { ...DEFAULT_CONFIG.site, ...site },
    api: {
      // 后端 myconfig.deploy.api.timeout 单位为秒，前端统一用毫秒
      base_url: deploy.api?.base_url ?? DEFAULT_CONFIG.api.base_url,
      timeout: apiTimeout > 0 ? apiTimeout * 1000 : DEFAULT_CONFIG.api.timeout,
    },
    web: { base_url: deploy.web?.base_url ?? DEFAULT_CONFIG.web.base_url },
    eip: { base_url: deploy.eip?.base_url ?? DEFAULT_CONFIG.eip.base_url },
    resource: { ...DEFAULT_CONFIG.resource, ...(deploy.resource ?? {}) },
    portal: { ...DEFAULT_CONFIG.portal },
  }
}

/**
 * 确定实际使用的接口根地址：
 * - 相对路径（如 /kbapi）直接使用；
 * - 绝对地址与当前页面同源时使用；
 * - 跨源地址在生产直接使用（依赖后端 CORS 配置），开发环境仍走 vite 代理。
 */
function resolveApiBase(baseUrl: string): string {
  if (!baseUrl) return DEFAULT_CONFIG.api.base_url
  if (!/^https?:\/\//i.test(baseUrl)) return baseUrl
  try {
    const url = new URL(baseUrl)
    if (url.origin === window.location.origin) return baseUrl
    return import.meta.env.DEV ? DEFAULT_CONFIG.api.base_url : baseUrl
  } catch {
    return DEFAULT_CONFIG.api.base_url
  }
}

/** 应用启动阶段调用一次；失败返回内置默认值 */
export async function loadRuntimeConfig(): Promise<RuntimeConfig> {
  try {
    const res = await axios.get(CONFIG_URL, { timeout: 8000, baseURL: '' })
    if (res.data?.code === 0 && res.data?.data) {
      const cfg = normalize(res.data.data as Record<string, unknown>)
      cfg.api.base_url = resolveApiBase(cfg.api.base_url)
      cached = cfg
    }
  } catch {
    // 配置接口不可用时静默降级，沿用内置默认值
  }
  return cached
}

/** 读取已缓存的配置（未加载完成时为默认值） */
export function getRuntimeConfig(): RuntimeConfig {
  return cached
}

/** 解析上传资源（封面图 / 视频封面 / 附件）的可访问地址 */
export function resolveUploadUrl(path: string): string {
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return path
  const rel = path.replace(/^\/+/, '')
  const { mode, base_url } = cached.resource
  if (mode === 'cdn' && base_url) {
    return base_url.replace(/\/+$/, '') + `/${rel}`
  }
  return `${KB_ASSET_URL}?path=${encodeURIComponent(rel)}`
}

/** 站点名（配置未就绪时用默认值） */
export function siteName(): string {
  return cached.site.name || DEFAULT_CONFIG.site.name
}
