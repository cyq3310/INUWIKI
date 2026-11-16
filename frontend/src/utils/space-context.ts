/**
 * 「前端顶栏选中空间」的运行时上下文。
 *
 * 由 AdminLayout 在切换空间时写入，api/request.ts 的请求拦截器把它放到
 * X-Space-Id 头上；后端据此计算本次请求的有效权限集合（无操作对象时按它判定）。
 * 单独成模块，避免 request.ts 与 stores/wiki 产生循环依赖。
 */
let currentSpaceId = 0

export function setCurrentSpaceId(id: number | string): void {
  const n = Number(id)
  currentSpaceId = Number.isFinite(n) && n > 0 ? Math.trunc(n) : 0
}

export function getCurrentSpaceId(): number {
  return currentSpaceId
}
