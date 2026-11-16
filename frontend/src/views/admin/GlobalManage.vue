<script setup lang="ts">
import { computed, ref } from 'vue'
import { Lock, RefreshCw } from 'lucide-vue-next'
import PermissionConfig from '../../components/PermissionConfig.vue'
import { useUserStore } from '../../stores/user'

/**
 * 后台 · 全局管理：维护不随空间变化的 WIKI 全局规则。
 * 骨架与「配置管理」一致；目前只有「权限管理」（按角色）。
 */
const store = useUserStore()
/** 全局页内的权限配置读写仍按现有规则，需要「权限管理」权限 */
const canEditPermissions = computed(() => store.hasPermission('kb:permission:manage'))

const refreshSeq = ref(0)

function refresh(): void {
  refreshSeq.value += 1
}
</script>

<template>
  <div class="page-max cfg-page">
    <div class="toolbar rise d1 cfg-toolbar">
      <div>
        <h1 class="cfg-title">全局管理</h1>
        <p class="cfg-sub">
          管理 WIKI 的全局规则，对所有空间生效。空间内可在「配置管理 - 权限管理」按人员单独配置并覆盖此处规则。
        </p>
      </div>
      <div class="cfg-toolbar-right">
        <button type="button" class="btn" @click="refresh">
          <RefreshCw :size="14" /> 刷新
        </button>
      </div>
    </div>

    <div class="cfg-layout">
      <aside class="cfg-nav">
        <div class="cfg-nav-cap">全局配置项</div>
        <div class="cfg-nav-item active"><Lock :size="16" /> 权限管理</div>
      </aside>
      <div class="cfg-panel">
        <!-- 剩余功能与当前一致：全局权限配置读写仍需「权限管理」权限 -->
        <PermissionConfig v-if="canEditPermissions" mode="role-global" :space-id="0" :refresh-seq="refreshSeq" />
        <div v-else class="cfg-empty rise d2">
          当前角色没有「权限管理」权限，无法编辑全局权限配置。
        </div>
      </div>
    </div>
  </div>
</template>
