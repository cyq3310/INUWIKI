import { defineConfig, loadEnv, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'

// 上传资源一律走 /kbapi/assets 鉴权接口。dev server 也显式拒绝旧静态路径，
// 否则 history fallback 会把 /uploads/knowledge/... 当页面返回 200。
const denyUploadStatic = (): Plugin => ({
  name: 'deny-kb-upload-static',
  configureServer(server) {
    server.middlewares.use((req, res, next) => {
      const path = ((req as { url?: string }).url ?? '').split('?')[0]
      if (path === '/uploads/knowledge' || path.startsWith('/uploads/knowledge/')) {
        res.statusCode = 404
        res.end('Not Found')
        return
      }
      next()
    })
  },
})

// 本地开发代理目标与端口可通过 frontend/.env.development 覆盖（VITE_DEV_PROXY_TARGET / VITE_DEV_PORT）。
// 生产构建不走 dev server，接口地址由后端 myconfig 经 GET /kbapi/config 下发，不受此处影响。
export default defineConfig(({ mode }) => {
  // envDir 传 '.'，由 vite 按当前工作目录解析，避免依赖 node 类型定义
  const env = loadEnv(mode, '.', 'VITE_')
  const proxyTarget = env.VITE_DEV_PROXY_TARGET || 'http://127.0.0.1:8001'
  const port = Number(env.VITE_DEV_PORT || 5174)

  return {
    plugins: [vue(), denyUploadStatic()],
    server: {
      host: '0.0.0.0',
      port,
      allowedHosts: true,
      proxy: {
        '/kbapi': {
          target: proxyTarget,
          changeOrigin: true,
        },
        // 上传资源改走 /kbapi/assets 鉴权接口，不再代理 /uploads 静态直出
      },
    },
  }
})
