<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { ChevronDown, Home, LayoutDashboard, LogOut, Search } from 'lucide-vue-next'
import KbSpaceSelect from '../components/KbSpaceSelect.vue'
import { useUserStore } from '../stores/user'
import { useWikiStore } from '../stores/wiki'
import { getRuntimeConfig, siteName } from '../config/runtime'

const store = useUserStore()
const wiki = useWikiStore()
const router = useRouter()
const route = useRoute()

const keyword = ref(wiki.keyword)
const nickname = computed(() => store.profile?.nickname || store.profile?.username || '')
const isWiki = computed(() => route.path === '/' || route.path.startsWith('/wiki'))

const portalSpaceId = computed({
  get: () => wiki.portalSpaceId,
  set: (value: number | '') => wiki.setPortalSpaceId(value),
})
const spacesLoading = ref(true)
onMounted(async () => {
  try {
    await wiki.loadSpaces()
  } catch {
    // 列表页会重试；顶栏只需保持可展开的空态
  } finally {
    spacesLoading.value = false
  }
})
// 在详情页切换空间时回到列表，让新空间的数据结构可见
watch(portalSpaceId, () => {
  if (route.name !== 'WikiList') router.push('/')
})

const submitSearch = () => {
  wiki.setKeyword(keyword.value.trim())
  if (!isWiki.value) {
    router.push('/')
  }
}

const handleCommand = (command: string) => {
  if (command === 'admin') {
    router.push('/admin/wiki')
  } else if (command === 'portal') {
    const { eip } = getRuntimeConfig()
    if (eip.base_url) window.location.href = eip.base_url
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
      <div class="brand cursor-pointer" @click="router.push('/')">
        <div class="brand-mark">W</div>
        <div>
          <div class="brand-name">{{ siteName() }}</div>
        </div>
      </div>

      <div class="topbar-space">
        <KbSpaceSelect v-model="portalSpaceId" :spaces="wiki.spaces" :loading="spacesLoading" :fallback-name="wiki.currentSpaceName" allow-all compact />
      </div>

      <nav class="nav">
        <a href="javascript:void(0)" :class="{ active: isWiki }" @click="router.push('/')">WIKI</a>
      </nav>

      <div class="topbar-right">
        <div class="search">
          <Search :size="14" color="#8A93A6" />
          <input
            v-model="keyword"
            placeholder="搜索知识、文档、视频…"
            @keyup.enter="submitSearch"
            @blur="wiki.setKeyword(keyword.trim())"
          />
        </div>

        <el-dropdown trigger="click" @command="handleCommand">
          <div class="flex cursor-pointer items-center gap-1">
            <div class="avatar">{{ nickname.slice(0, 1) || 'W' }}</div>
            <ChevronDown :size="14" color="#8A93A6" />
          </div>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-if="store.isAdmin" command="admin">
                <LayoutDashboard :size="15" class="mr-1.5 inline-block align-[-2px]" />后台管理
              </el-dropdown-item>
              <el-dropdown-item command="portal">
                <Home :size="15" class="mr-1.5 inline-block align-[-2px]" />返回 EIP 门户
              </el-dropdown-item>
              <el-dropdown-item command="logout" divided>
                <LogOut :size="15" class="mr-1.5 inline-block align-[-2px]" />退出登录
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </header>

    <main>
      <router-view />
    </main>
  </div>
</template>
