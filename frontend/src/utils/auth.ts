import axios from 'axios'

const TICKET_PROBE = '/kbapi/auth/asset-ticket'

let expired = false
let probing = false

/** 统一回登录界面：清理本地凭证并带上原地址，登录后可直接返回 */
export function redirectToLogin(): void {
  if (window.location.pathname === '/login') return
  localStorage.removeItem('inuwiki_token')
  const current = window.location.pathname + window.location.search + window.location.hash
  window.location.replace(`/login?redirect=${encodeURIComponent(current)}`)
}

/**
 * <img>/<video>/<audio> 由浏览器直接发起，加载失败时前端拿不到状态码与响应体。
 * 先探测资源票据是否仍有效，确认过期才跳登录，避免普通 404 把用户误踢出。
 */
async function checkAssetTicket(): Promise<void> {
  if (expired || probing || window.location.pathname === '/login') return
  probing = true
  try {
    const res = await axios.get(TICKET_PROBE, { baseURL: '', timeout: 5000 })
    if (res.data?.code === 0 && res.data?.data?.valid === false) {
      expired = true
      redirectToLogin()
    }
  } catch {
    // 探测本身失败（网络 / 服务异常）不跳转，避免误判
  } finally {
    probing = false
  }
}

/** 全局资源加载失败守卫：捕获阶段监听，覆盖动态渲染的正文图片与播放器 */
export function installResourceAuthGuard(): void {
  window.addEventListener(
    'error',
    (event: Event) => {
      const target = event.target as HTMLElement | null
      if (!target || !['IMG', 'VIDEO', 'AUDIO', 'SOURCE'].includes(target.tagName)) return
      void checkAssetTicket()
    },
    true,
  )
}
