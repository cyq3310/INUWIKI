import axios from 'axios'
import { ElMessage } from 'element-plus'
import { getRuntimeConfig } from '../config/runtime'
import { normalizeIds } from '../utils/normalize-ids'
import { redirectToLogin } from '../utils/auth'
import { getCurrentSpaceId } from '../utils/space-context'

declare module 'axios' {
  /** 静默失败：可选接口（如统计 / 目录）探测失败时不弹错误提示 */
  interface AxiosRequestConfig {
    silent?: boolean
  }
}

const request = axios.create({
  baseURL: '/kbapi', // 缺省值；实际地址由运行时配置（后端 myconfig）下发
  timeout: 15000,
})

request.interceptors.request.use((config) => {
  // 接口根地址与超时统一取自后端 myconfig，避免前端写死 IP/端口
  const { api } = getRuntimeConfig()
  if (api.base_url) config.baseURL = api.base_url
  if (api.timeout > 0) config.timeout = api.timeout

  const token = localStorage.getItem('inuwiki_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  // 顶栏选中的空间：后端据此计算本次请求的有效权限（空间配置整包覆盖全局）
  config.headers['X-Space-Id'] = String(getCurrentSpaceId())
  return config
})

request.interceptors.response.use(
  (response) => {
    const body = response.data
    if (body && body.code === 0) {
      normalizeIds(body)
      return body
    }
    const msg = body?.msg || '请求失败'
    if (body?.code === 401) {
      redirectToLogin()
    } else if (!response.config.silent) {
      ElMessage.error(msg)
    }
    return Promise.reject(new Error(msg))
  },
  (error) => {
    const status = error.response?.status
    const code = error.response?.data?.code
    if (status === 401 || code === 401) {
      redirectToLogin()
    }
    const msg = error.response?.data?.msg || error.message || '网络异常，请稍后重试'
    if (!error.config?.silent) {
      ElMessage.error(msg)
    }
    return Promise.reject(new Error(msg))
  },
)

export default request
