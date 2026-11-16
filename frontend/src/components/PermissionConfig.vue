<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Pencil, RefreshCw, Trash2 } from 'lucide-vue-next'
import KbSubjectPicker from './KbSubjectPicker.vue'
import { directoryApi, permissionApi } from '../api'
import type { PermissionDef, PermissionGrant, SubjectOption, SubjectType } from '../types'

/**
 * 权限配置（两种固定模式，界面风格统一复用配置管理页的 cfg-* 设计系统）：
 * - role-global：全局管理页，space_id=0，按角色，对所有空间生效；
 * - user-space：配置管理页，space_id=当前空间，按人员，整包覆盖全局角色配置。
 * 两者都不再提供「按角色 / 按人员」下拉切换。
 */
const props = withDefaults(
  defineProps<{
    mode: 'role-global' | 'user-space'
    spaceId: number
    refreshSeq?: number
  }>(),
  { refreshSeq: 0 },
)

const subjectType = computed<SubjectType>(() => (props.mode === 'role-global' ? 'role' : 'user'))
const scopeLabel = computed(() => (props.mode === 'role-global' ? '角色' : '人员'))
const needSpace = computed(() => props.mode === 'user-space' && !(props.spaceId > 0))

const loading = ref(false)
const saving = ref(false)
const defs = ref<PermissionDef[]>([])
const grants = ref<PermissionGrant[]>([])
const dialog = ref(false)
const editing = ref<PermissionGrant | null>(null)
const selectedCodes = ref<string[]>([])
const nameMap = ref<Record<string, string>>({})
const superAdminKeys = ref<Record<string, boolean>>({})
const requiredCodes = ref<string[]>([])
const missingCodes = computed(() => requiredCodes.value.filter((code) => !selectedCodes.value.includes(code)))
const missingText = computed(() => missingCodes.value.map((code) => permName(code)).join('、'))

const groups = computed(() => {
  const list: { name: string; items: PermissionDef[] }[] = []
  for (const def of defs.value) {
    let group = list.find((g) => g.name === def.group)
    if (!group) {
      group = { name: def.group, items: [] }
      list.push(group)
    }
    group.items.push(def)
  }
  return list
})

const visibleGrants = computed(() => grants.value.filter((g) => g.subject_type === subjectType.value))
const selectedIds = computed(() => visibleGrants.value.map((g) => g.subject_id))

function key(s: { subject_type: string; subject_id: number | string }): string {
  return `${s.subject_type}:${Number(s.subject_id)}`
}

function label(s: { subject_type: string; subject_id: number | string }): string {
  return nameMap.value[key(s)] || `#${s.subject_id}`
}

function isProtected(s: { subject_type: string; subject_id: number | string }): boolean {
  return props.mode === 'user-space' && s.subject_type === 'user' && superAdminKeys.value[key(s)] === true
}

function permName(code: string): string {
  return defs.value.find((d) => d.code === code)?.name || code
}

async function loadRequiredCodes(subjectId: number): Promise<void> {
  requiredCodes.value = []
  if (props.mode !== 'user-space' || subjectId <= 0) return
  try {
    const res = await permissionApi.globalRequired(props.spaceId, subjectId)
    const allowed = new Set(defs.value.map((d) => d.code))
    requiredCodes.value = (res.data?.codes ?? []).filter((code) => allowed.has(code))
  } catch {
    // 查询失败时不阻断编辑，保存仍由服务端做最终校验
  }
}

async function resolveNames(): Promise<void> {
  const wanted: Record<SubjectType, number[]> = { role: [], user: [] }
  for (const g of grants.value) {
    if (!nameMap.value[key(g)]) wanted[g.subject_type].push(Number(g.subject_id))
  }
  for (const type of ['role', 'user'] as SubjectType[]) {
    const ids = [...new Set(wanted[type])]
    if (!ids.length) continue
    try {
      const res = await directoryApi.resolve(type, ids)
      for (const sub of res.data?.subjects ?? []) {
        nameMap.value[key(sub)] = sub.name
        if (sub.is_superadmin) superAdminKeys.value[key(sub)] = true
      }
    } catch {
      // 目录快照不可用时保留 #ID 兜底
    }
  }
}

async function load(): Promise<void> {
  nameMap.value = {}
  superAdminKeys.value = {}
  requiredCodes.value = []
  if (needSpace.value) {
    defs.value = []
    grants.value = []
    return
  }
  loading.value = true
  try {
    const res = await permissionApi.list(props.spaceId)
    // 空间级按人员配置不展示「空间管理」页签；后端已按范围下发，这里再做一层兜底
    const scopeDefs = (res.data?.permissions ?? []).filter(
      (d) => props.mode !== 'user-space' || d.group !== '空间管理',
    )
    const scopeCodes = new Set(scopeDefs.map((d) => d.code))
    defs.value = scopeDefs
    // 历史数据里若含全局专属权限，不回显、也不会被再次保存
    grants.value = (res.data?.grants ?? []).map((g) => ({
      ...g,
      codes: g.codes.filter((code) => scopeCodes.has(code)),
    }))
    await resolveNames()
  } catch {
    // request 拦截器已提示
  } finally {
    loading.value = false
  }
}

async function addSubject(option: SubjectOption): Promise<void> {
  const id = Number(option.subject_id)
  if (!id) return
  const subject: PermissionGrant = { subject_type: subjectType.value, subject_id: id, codes: [] }
  if (option.is_superadmin) {
    ElMessage.warning('超级管理员始终拥有全部权限，无需且不能单独配置')
    return
  }
  nameMap.value[key(subject)] = option.name
  await loadRequiredCodes(id)
  editing.value = subject
  selectedCodes.value = [...requiredCodes.value]
  dialog.value = true
}

async function openEdit(g: PermissionGrant): Promise<void> {
  if (isProtected(g)) {
    ElMessage.warning('超级管理员始终拥有全部权限，无需且不能单独配置')
    return
  }
  await loadRequiredCodes(Number(g.subject_id))
  editing.value = { ...g }
  const allowed = new Set(defs.value.map((d) => d.code))
  selectedCodes.value = g.codes.filter((code) => allowed.has(code))
  dialog.value = true
}

async function save(): Promise<void> {
  const target = editing.value
  if (!target) return
  if (isProtected(target)) {
    ElMessage.warning('超级管理员始终拥有全部权限，无需且不能单独配置')
    dialog.value = false
    return
  }
  if (missingCodes.value.length) {
    ElMessage.warning(`请先补齐全局角色权限：${missingText.value}`)
    return
  }
  saving.value = true
  try {
    await permissionApi.save({
      space_id: props.spaceId,
      subject_type: target.subject_type,
      subject_id: target.subject_id,
      codes: selectedCodes.value,
    })
    ElMessage.success('权限配置已保存')
    dialog.value = false
    await load()
  } catch {
    // request 拦截器已提示
  } finally {
    saving.value = false
  }
}

async function remove(g: PermissionGrant): Promise<void> {
  if (isProtected(g)) {
    ElMessage.warning('超级管理员始终拥有全部权限，无需且不能单独配置')
    return
  }
  const text = `${scopeLabel.value} · ${label(g)}`
  try {
    await ElMessageBox.confirm(`确定移除「${text}」的全部权限配置吗？`, '移除权限配置', {
      type: 'warning',
      confirmButtonText: '移除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  try {
    await permissionApi.remove(props.spaceId, g.subject_type, g.subject_id)
    ElMessage.success('已移除')
    await load()
  } catch {
    // request 拦截器已提示
  }
}

watch([() => props.refreshSeq, () => props.spaceId], () => { void load() })
onMounted(() => { void load() })
</script>

<template>
  <div class="cfg-card perm-card rise d2">
    <div class="cfg-head">
      <span>权限配置 · {{ visibleGrants.length }} 个{{ scopeLabel }}</span>
      <div class="perm-add">
        <KbSubjectPicker
          :type="subjectType"
          :selected-ids="selectedIds"
          :disable-super-admin="mode === 'user-space'"
          @select="addSubject"
        />
        <button type="button" class="btn btn-sm" :disabled="loading" @click="load">
          <RefreshCw :size="14" /> 刷新
        </button>
      </div>
    </div>

    <div class="cfg-desc">
      <template v-if="mode === 'role-global'">
        按<b>角色</b>配置全局权限，对所有空间生效。若某人员在某空间的「配置管理 - 权限管理」中已有配置，
        该空间<b>以人员配置为准，整包覆盖</b>此处的角色配置。超级管理员始终拥有全部权限。
      </template>
      <template v-else>
        按<b>人员</b>配置<b>当前空间</b>的权限。此处一旦配置，即<b>整包覆盖</b>全局角色配置；
        新配置必须<b>完整包含</b>全局角色权限，不能缩小范围；移除本条后回落全局。EIP 已有权限不再叠加。
        超级管理员始终拥有全部权限，不可在此选择或编辑。
        「空间管理」与「权限管理」类权限仅在「全局管理 - 权限管理」中按角色配置。
      </template>
    </div>

    <div v-if="needSpace" class="perm-empty">
      请先在顶栏选择一个具体空间，再配置该空间的人员权限。
    </div>
    <table v-else class="table doc-type-table">
      <thead>
        <tr>
          <th style="width: 210px">授权对象</th>
          <th>已授权限</th>
          <th style="width: 180px">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="g in visibleGrants" :key="key(g)">
          <td>
            <span class="perm-subject" :class="g.subject_type === 'role' ? 'role' : 'user'">
              {{ scopeLabel }} · {{ label(g) }}
            </span>
            <span v-if="isProtected(g)" class="perm-super">超级管理员 · 不可编辑</span>
          </td>
          <td>
            <div class="perm-codes">
              <span v-for="code in g.codes" :key="code" class="perm-code" :title="code">{{ permName(code) }}</span>
              <span v-if="!g.codes.length" class="cell-sub">未配置任何权限</span>
            </div>
          </td>
          <td>
            <button type="button" class="btn" :disabled="isProtected(g)" @click="openEdit(g)"><Pencil :size="13" /> 配置</button>
            <button type="button" class="btn cfg-btn cfg-danger" :disabled="isProtected(g)" @click="remove(g)"><Trash2 :size="13" /> 移除</button>
          </td>
        </tr>
        <tr v-if="!visibleGrants.length">
          <td colspan="3" class="cell-sub" style="text-align: center; padding: 22px">
            {{ loading ? '加载中…' : `暂无配置，使用上方搜索框添加${scopeLabel}` }}
          </td>
        </tr>
      </tbody>
    </table>

    <el-dialog
      v-model="dialog"
      :title="`配置权限 · ${scopeLabel} ${editing ? label(editing) : ''}`"
      width="560px"
    >
      <div class="cfg-desc" style="margin-bottom: 14px">
        勾选的权限将授予该{{ scopeLabel }}，保存后对其账号立即生效（重新进入页面会刷新菜单）。
      </div>
      <div v-if="mode === 'user-space'" class="perm-required-tip">
        全局角色基础权限：{{ requiredCodes.length ? requiredCodes.map((code) => permName(code)).join('、') : '无' }}。
        空间配置必须完整包含这些权限。
      </div>
      <div v-if="missingCodes.length" class="perm-required-warning">
        尚缺少全局角色权限：{{ missingText }}，补齐后才能保存。
      </div>
      <div v-for="group in groups" :key="group.name" class="perm-group">
        <div class="perm-group-title">{{ group.name }}</div>
        <div class="perm-group-items">
          <label v-for="def in group.items" :key="def.code" class="perm-check" :title="def.code">
            <input v-model="selectedCodes" type="checkbox" :value="def.code" />
            <span>{{ def.name }}</span>
            <span v-if="requiredCodes.includes(def.code)" class="perm-required-tag">全局必需</span>
          </label>
        </div>
      </div>
      <template #footer>
        <button type="button" class="btn" @click="dialog = false">取消</button>
        <button type="button" class="btn btn-primary" :disabled="saving || missingCodes.length > 0" @click="save">保存</button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.cfg-card.perm-card { min-height: 700px; }
.perm-add { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.perm-add .btn-sm { height: 32px; }
.perm-empty {
  padding: 48px 18px;
  text-align: center;
  color: var(--text-3, #9aa3b5);
  font-size: 13px;
}
.perm-codes { display: flex; flex-wrap: wrap; gap: 6px; }
.perm-subject {
  display: inline-flex;
  align-items: center;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 12px;
  background: var(--tag-u, #eafaf1);
  color: var(--tag-u-c, #1f9d57);
}
.perm-subject.role {
  background: var(--tag-r, #eef1ff);
  color: var(--tag-r-c, #5566ff);
}
.perm-super {
  display: inline-block;
  margin-left: 6px;
  font-size: 11px;
  color: var(--text-3, #8a93a6);
}
.perm-code {
  display: inline-block;
  padding: 2px 9px;
  border-radius: 6px;
  font-size: 12px;
  background: #f2f4f8;
  color: #5b6478;
}
.perm-group { margin-bottom: 14px; }
.perm-group-title { font-size: 13px; font-weight: 600; margin-bottom: 8px; }
.perm-group-items { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; }
.perm-check { display: flex; align-items: center; gap: 7px; font-size: 13px; cursor: pointer; }
.perm-required-tip {
  margin-bottom: 12px;
  padding: 9px 12px;
  border: 1px solid var(--primary-line, #d6e2ff);
  border-radius: 8px;
  background: #f8faff;
  color: var(--text-2, #5b6577);
  font-size: 12px;
  line-height: 1.6;
}
.perm-required-warning {
  margin-bottom: 12px;
  padding: 9px 12px;
  border: 1px solid #ffd7d7;
  border-radius: 8px;
  background: #fff1f1;
  color: var(--red, #e5484d);
  font-size: 12px;
  line-height: 1.6;
}
.perm-required-tag {
  padding: 1px 5px;
  border-radius: 999px;
  background: var(--primary-weak, #eef3ff);
  color: var(--primary);
  font-size: 10px;
  white-space: nowrap;
}
</style>
