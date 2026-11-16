<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { ChevronDown, Home, LogOut } from 'lucide-vue-next'
import KbSpaceSelect from '../components/KbSpaceSelect.vue'
import { useUserStore } from '../stores/user'
import { useWikiStore } from '../stores/wiki'
import { getRuntimeConfig, siteName } from '../config/runtime'
import { setCurrentSpaceId } from '../utils/space-context'

const store = useUserStore()
const wiki = useWikiStore()
const router = useRouter()
const route = useRoute()

const nickname = computed(() => store.profile?.nickname || store.profile?.username || '')
const envLabel = computed(() => {
  const env = getRuntimeConfig().env
  return env === 'production' ? '生产环境' : env === 'staging' ? '预发环境' : '本地环境'
})
const envPill = computed(() => (getRuntimeConfig().env === 'production' ? 'blue' : 'amber'))

const isWiki = computed(() => route.path.startsWith('/admin/wiki'))
const isSpaces = computed(() => route.path.startsWith('/admin/spaces'))
const isConfig = computed(() => route.path.startsWith('/admin/config'))
const isGlobal = computed(() => route.path.startsWith('/admin/global'))
const isOplogs = computed(() => route.path.startsWith('/admin/operation-logs'))

/** 空间管理入口：可管理空间或仅可创建空间的角色都应能进入 */
const canSpaces = computed(() =>
  store.hasPermission('kb:space:manage') || store.hasPermission('kb:space:create'),
)

/** 配置管理下三个页签各自独立授权：拥有任一配置权限即可进入配置管理 */
const CONFIG_PERMS = ['kb:category:manage', 'kb:doc_type:manage', 'kb:permission:manage']
const canConfig = computed(() => CONFIG_PERMS.some((code) => store.hasPermission(code)))
/** 全局管理入口由「空间管理」分组下的「全局管理」权限单独控制 */
const canGlobal = computed(() => store.hasPermission('kb:global:manage'))
/** 操作日志对所有登录用户开放，不做权限码门槛 */

const adminSpaceId = computed({
  get: () => wiki.adminSpaceId,
  set: (value: number | '') => wiki.setAdminSpaceId(value),
})

// 顶栏选中空间同步给请求拦截器（X-Space-Id），后端据此计算有效权限
watch(adminSpaceId, (value) => setCurrentSpaceId(value === '' ? 0 : value), { immediate: true })
const spacesLoading = ref(true)
onMounted(async () => {
  try {
    await wiki.loadAdminSpaces()
    // 从「新增文章」直接落到创建页时，顶栏跟随 URL 指定的空间
    const querySpaceId = Number(route.query.space_id || 0)
    if (route.name === 'AdminWikiCreate' && querySpaceId > 0) {
      wiki.setAdminSpaceId(querySpaceId)
    }
    // 其余情况保持本地记忆的当前空间；无记忆时是「全部空间」，不再落到第一个空间
  } catch {
    // 列表页会重试；顶栏保持当前记忆或「全部空间」
  } finally {
    spacesLoading.value = false
  }
})

const handleCommand = (command: string) => {
  if (command === 'portal') {
    router.push('/')
  } else if (command === 'logout') {
    ElMessageBox.confirm('会话为「当天有效」，退出后需要重新登录。确定退出吗？', '退出登录', { type: 'warning' })
      .then(async () => {
        await store.logout()
        router.push('/login')
      })
      .catch(() => undefined)
  }
}
</script>

<template>
  <div class="min-h-screen bg-page">
    <header class="topbar">
      <div
        class="brand cursor-pointer"
        role="link"
        tabindex="0"
        title="返回知识库主页"
        aria-label="返回知识库主页"
        @click="router.push('/')"
        @keydown.enter="router.push('/')"
        @keydown.space.prevent="router.push('/')"
      >
        <div class="brand-mark">W</div>
        <div>
          <div class="brand-name">{{ siteName() }} · 管理后台</div>
        </div>
      </div>

      <div class="topbar-space">
        <KbSpaceSelect v-model="adminSpaceId" :spaces="wiki.adminSpaces" :loading="spacesLoading" :fallback-name="wiki.currentSpaceName" allow-all compact />
      </div>

      <nav class="nav">
        <a
          v-if="store.isAdmin"
          href="javascript:void(0)"
          :class="{ active: isWiki }"
          @click="router.push('/admin/wiki')"
        >
          知识管理
        </a>
        <a
          v-if="canSpaces"
          href="javascript:void(0)"
          :class="{ active: isSpaces }"
          @click="router.push('/admin/spaces')"
        >
          空间管理
        </a>
        <a
          v-if="canConfig"
          href="javascript:void(0)"
          :class="{ active: isConfig }"
          @click="router.push('/admin/config')"
        >
          配置管理
        </a>
        <a
          v-if="canGlobal"
          href="javascript:void(0)"
          :class="{ active: isGlobal }"
          @click="router.push('/admin/global')"
        >
          全局管理
        </a>
        <a
          href="javascript:void(0)"
          :class="{ active: isOplogs }"
          @click="router.push('/admin/operation-logs')"
        >
          操作日志
        </a>
      </nav>

      <div class="topbar-right">
        <span class="pill" :class="envPill">{{ envLabel }}</span>
        <el-dropdown trigger="click" @command="handleCommand">
          <div class="flex cursor-pointer items-center gap-1">
            <div class="avatar">{{ nickname.slice(0, 1) || '管' }}</div>
            <ChevronDown :size="14" color="#8A93A6" />
          </div>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="portal">
                <Home :size="15" class="mr-1.5 inline-block align-[-2px]" />返回知识库
              </el-dropdown-item>
              <el-dropdown-item command="logout" divided>
                <LogOut :size="15" class="mr-1.5 inline-block align-[-2px]" />退出登录
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </header>

    <!-- 横向内边距交由子页面（.layout / .page-max 各自的 28px）负责，避免叠加成 56px -->
    <main class="pb-10 pt-6">
      <router-view />
    </main>
  </div>
</template>
