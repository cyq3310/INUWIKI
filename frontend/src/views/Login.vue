<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Lock, User } from 'lucide-vue-next'
import { useUserStore } from '../stores/user'
import { siteName } from '../config/runtime'

const store = useUserStore()
const route = useRoute()
const router = useRouter()

const form = reactive({ username: '', password: '' })
const loading = ref(false)

const submit = () => {
  if (!form.username || !form.password || loading.value) return
  loading.value = true
  store
    .login(form.username, passwordGuard(form.password))
    .then(() => {
      const redirect = (route.query.redirect as string) || '/'
      router.push(redirect)
    })
    .catch(console.error)
    .finally(() => {
      loading.value = false
    })
}

/** 透传密码（不 trim，避免误改含空格的密码） */
const passwordGuard = (value: string) => value

const onKeyup = (event: KeyboardEvent) => {
  if (event.key === 'Enter') submit()
}
</script>

<template>
  <div
    class="relative flex min-h-screen items-center justify-center overflow-hidden"
    style="background: linear-gradient(135deg, #f5f7fb 0%, #eaf0fe 55%, #e6ecfd 100%)"
  >
    <div class="pointer-events-none absolute -left-32 -top-32 h-96 w-96 rounded-full bg-[#4F7DF3]/20 blur-3xl" />
    <div class="pointer-events-none absolute -bottom-32 -right-32 h-96 w-96 rounded-full bg-[#1E3FA8]/20 blur-3xl" />

    <div
      class="relative w-[400px] rounded-[22px] border border-white/70 bg-white/70 p-10"
      style="box-shadow: 0 8px 20px rgba(43, 92, 230, 0.16), 0 24px 48px rgba(27, 35, 60, 0.13); backdrop-filter: blur(20px) saturate(160%)"
    >
      <div class="mb-8 flex flex-col items-center">
        <div
          class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-lg"
          style="background: var(--grad-primary); box-shadow: 0 4px 14px rgba(43, 92, 230, 0.35)"
        >
          <span class="text-2xl font-bold">W</span>
        </div>
        <h1 class="text-2xl font-semibold tracking-wide text-ink">{{ siteName() }}</h1>
        <p class="mt-1.5 text-sm text-ink-mute">企业知识库 · 欢迎登录</p>
      </div>

      <form class="space-y-4" @submit.prevent="submit">
        <div
          class="flex items-center gap-3 rounded-[10px] border border-line bg-white/90 px-4 transition-all focus-within:border-primary-light focus-within:ring-[3px] focus-within:ring-primary-light/15"
        >
          <User :size="17" class="shrink-0 text-ink-mute" />
          <input
            v-model="form.username"
            type="text"
            placeholder="请输入 EIP 账号"
            autocomplete="username"
            class="h-11 w-full border-none bg-transparent text-sm text-ink outline-none placeholder:text-ink-mute"
            @keyup="onKeyup"
          />
        </div>
        <div
          class="flex items-center gap-3 rounded-[10px] border border-line bg-white/90 px-4 transition-all focus-within:border-primary-light focus-within:ring-[3px] focus-within:ring-primary-light/15"
        >
          <Lock :size="17" class="shrink-0 text-ink-mute" />
          <input
            v-model="form.password"
            type="password"
            placeholder="请输入密码"
            autocomplete="current-password"
            class="h-11 w-full border-none bg-transparent text-sm text-ink outline-none placeholder:text-ink-mute"
            @keyup="onKeyup"
          />
        </div>

        <button
          type="submit"
          :disabled="loading || !form.username || !form.password"
          class="h-11 w-full rounded-[10px] text-sm font-medium text-white transition-all duration-300 hover:shadow-lg hover:brightness-105 disabled:cursor-not-allowed disabled:opacity-60"
          style="background: var(--grad-primary); box-shadow: 0 4px 14px rgba(43, 92, 230, 0.3)"
        >
          {{ loading ? '登录中…' : '登 录' }}
        </button>
      </form>

      <p class="mt-6 text-center text-xs leading-6 text-ink-mute">
        使用 EIP 门户账号密码登录<br />
        会话当天有效，次日需重新登录
      </p>
    </div>
  </div>
</template>
