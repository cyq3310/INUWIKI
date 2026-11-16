import { defineStore } from 'pinia'
import { authApi } from '../api'
import type { UserProfile } from '../types'

export const useUserStore = defineStore('user', {
  state: () => ({
    token: localStorage.getItem('inuwiki_token') || '',
    profile: null as UserProfile | null,
  }),
  getters: {
    isLoggedIn: (state) => !!state.token,
    /** 拥有任一 kb:* 权限码即视为知识库管理员 */
    isAdmin: (state) =>
      !!state.profile &&
      (state.profile.isSuperAdmin || state.profile.permissions.some((code) => code.startsWith('kb:'))),
    displayName: (state) => state.profile?.nickname || state.profile?.username || '',
  },
  actions: {
    async login(username: string, password: string) {
      const res = await authApi.login(username, password)
      this.token = res.data.token
      this.profile = res.data.user
      localStorage.setItem('inuwiki_token', res.data.token)
    },
    async fetchProfile() {
      const res = await authApi.profile()
      this.profile = res.data
    },
    async logout() {
      authApi.logout().catch(console.error)
      this.token = ''
      this.profile = null
      localStorage.removeItem('inuwiki_token')
    },
    /** 是否拥有指定权限码（超管恒为 true） */
    hasPermission(code: string): boolean {
      if (!this.profile) return false
      return this.profile.isSuperAdmin || this.profile.permissions.includes(code)
    },
  },
})
