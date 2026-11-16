import { createRouter, createWebHistory } from 'vue-router'
import { useUserStore } from '../stores/user'
import { useWikiStore } from '../stores/wiki'
import { siteName } from '../config/runtime'
import { redirectToLogin } from '../utils/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'Login',
      component: () => import('../views/Login.vue'),
      meta: { title: '登录' },
    },
    {
      path: '/',
      component: () => import('../layout/PortalLayout.vue'),
      children: [
        {
          path: '',
          name: 'WikiList',
          component: () => import('../views/portal/WikiList.vue'),
          meta: { title: 'WIKI' },
        },
        {
          path: 'wiki/:id',
          name: 'WikiDetail',
          component: () => import('../views/portal/WikiDetail.vue'),
          meta: { title: '知识详情' },
        },
      ],
    },
    {
      path: '/admin',
      component: () => import('../layout/AdminLayout.vue'),
      redirect: '/admin/wiki',
      children: [
        {
          // 列表不再以 kb:article:list 为门票：无该权限仍可进入并查看自己创建的文章
          path: 'wiki',
          name: 'AdminWikiList',
          component: () => import('../views/admin/WikiList.vue'),
          meta: { title: '知识管理' },
        },
        {
          path: 'wiki/new',
          name: 'AdminWikiCreate',
          component: () => import('../views/admin/WikiEdit.vue'),
          meta: { title: '新建知识', perm: 'kb:article:create' },
        },
        {
          // 本人默认可编辑自己的文章，权限由后端 ArticlePolicy 按对象判定
          path: 'wiki/:id',
          name: 'AdminWikiEdit',
          component: () => import('../views/admin/WikiEdit.vue'),
          meta: { title: '编辑知识' },
        },
        {
          path: 'spaces',
          name: 'AdminSpaces',
          component: () => import('../views/admin/SpaceManage.vue'),
          // 仅「创建空间」权限的角色也要能进入列表页新建空间
          meta: { title: '空间管理', perm: ['kb:space:manage', 'kb:space:create'] },
        },
        {
          path: 'config',
          name: 'AdminConfig',
          component: () => import('../views/admin/ConfigManage.vue'),
          // 三个页签各自独立授权：有任一权限即可进入，页签再按单项权限显隐
          meta: {
            title: '配置管理',
            perm: ['kb:category:manage', 'kb:doc_type:manage', 'kb:permission:manage'],
          },
        },
        {
          path: 'global',
          name: 'AdminGlobal',
          component: () => import('../views/admin/GlobalManage.vue'),
          meta: { title: '全局管理', perm: 'kb:global:manage' },
        },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  document.title = to.meta.title ? `${to.meta.title} - ${siteName()}` : siteName()

  if (to.path === '/login') {
    return true
  }

  const store = useUserStore()
  if (!store.isLoggedIn) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }

  if (!store.profile) {
    await store.fetchProfile().catch(console.error)
  }
  // 获取档案失败（Token 失效 / 账号被禁用）时兜底回登录界面
  if (!store.profile) {
    redirectToLogin()
    return false
  }

  if (to.path.startsWith('/admin')) {
    // 从门户详情页带 can_edit 快照进入编辑页时以后端 canEditArticle 结论为准：
    // 「空间 can_edit / 编辑名单」授权的用户可能没有 kb:article:update 权限码。
    const fromArticleEdit = to.name === 'AdminWikiEdit' && useWikiStore().editRow?.can_edit === true
    if (!store.isAdmin && !fromArticleEdit) {
      return { path: '/' }
    }
    const perm = to.meta.perm as string | string[] | undefined
    const perms = perm ? (Array.isArray(perm) ? perm : [perm]) : []
    // 数组语义为「任一权限即可进入」：配置管理下三个页签各自独立授权
    if (perms.length && !fromArticleEdit && !perms.some((code) => store.hasPermission(code))) {
      // 无该菜单权限时落到第一个有权限的后台页
      const children = (router.options.routes.find((r) => r.path === '/admin')?.children ?? []) as {
        path: string
        meta?: { perm?: string | string[] }
      }[]
      const first = children.find((r) => {
        const rp = r.meta?.perm
        if (!rp) return false
        return (Array.isArray(rp) ? rp : [rp]).some((code) => store.hasPermission(code))
      })
      return first ? { path: `/admin/${first.path}` } : { path: '/' }
    }
  }

  return true
})

export default router
