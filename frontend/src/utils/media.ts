import { getRuntimeConfig } from '../config/runtime'

/**
 * 媒体播放 / 附件下载地址：GET /kbapi/articles/:id/media/:attachment_id
 * 生产由 Nginx X-Accel-Redirect 直发（支持 Range 拖拽），本地由 PHP 直接输出字节。
 *
 * 资源 URL 固定走同源 /kbapi（与 KB_ASSET_URL 一致）：<a href>、<video src> 由
 * 浏览器直接发起，无法携带 Authorization 头，只能依赖同域的 kb_asset 资源票据
 * cookie；跨域的 api.base_url 不参与拼接，否则票据不会随请求发送。
 *
 * 仅在后端额外支持 ?token= 校验并显式开启 VITE_MEDIA_TOKEN_IN_QUERY 时附上令牌。
 */
export function mediaPlayUrl(articleId: number, attachmentId: number): string {
  const { api } = getRuntimeConfig()
  let prefix = ''
  if (/^https?:\/\//i.test(api.base_url)) {
    try {
      const absolute = new URL(api.base_url)
      if (absolute.origin === window.location.origin) prefix = absolute.origin
    } catch {
      prefix = ''
    }
  }
  const url = `${prefix}/kbapi/articles/${articleId}/media/${attachmentId}`
  if (import.meta.env.VITE_MEDIA_TOKEN_IN_QUERY === 'true') {
    const token = localStorage.getItem('inuwiki_token') || ''
    if (token) {
      return `${url}?token=${encodeURIComponent(token)}`
    }
  }
  return url
}
