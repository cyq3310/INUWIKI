<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Lock, Plus, Trash2 } from 'lucide-vue-next'
import KbSubjectPicker from '../../components/KbSubjectPicker.vue'
import { directoryApi, spaceApi } from '../../api'
import { useUserStore } from '../../stores/user'
import { useWikiStore } from '../../stores/wiki'
import type { SpaceMemberItem, SubjectOption, SubjectType } from '../../types'
import {
  clampSpaceCode,
  limitSpaceName,
  MAX_CODE_LENGTH,
  SPACE_NAME_MAX_UNITS,
  spaceCodeFromName,
  spaceNameUnits,
  uniqueSpaceCode,
} from '../../utils/space-code'

/**
 * 后台 · 空间管理
 * 上半：空间卡片（名称 / 编码 / 公开或受限 / 文章数 / 成员数）
 * 抽屉：新建或编辑空间（基础信息 + 受限开关）+ 成员授权（按角色与按人员并存，可查看 / 可编辑 / 可管理三档）
 * 授权接口：GET / POST /kbapi/admin/spaces/:id/members，DELETE 同路径（按 subject_type + subject_id）。
 */

type PermKey = 'can_view' | 'can_edit' | 'can_manage'

const wiki = useWikiStore()
const store = useUserStore()

/** 新建空间单独由 kb:space:create 控制；编辑 / 删除 / 成员授权仍归 kb:space:manage */
const canCreate = computed(() => store.hasPermission('kb:space:create'))
const canManage = computed(() => store.hasPermission('kb:space:manage'))

const loading = ref(false)
const drawerVisible = ref(false)
const saving = ref(false)

const form = reactive({
  id: 0,
  name: '',
  code: '',
  description: '',
  is_restricted: false,
  status: 1,
  sort: 0,
})

/** 当前抽屉对应的成员授权列表 */
const members = ref<SpaceMemberItem[]>([])
const memberLoading = ref(false)
const grantType = ref<SubjectType>('role')
/** 目录解析结果：subject_type:subject_id → 名称（目录不可用时降级为 ID 展示） */
const nameMap = ref<Record<string, string>>({})

const PERMS: { key: PermKey; label: string }[] = [
  { key: 'can_view', label: '可查看' },
  { key: 'can_edit', label: '可编辑' },
  { key: 'can_manage', label: '可管理' },
]

const isEdit = computed(() => form.id > 0)

/** 用户是否手改过编码：手改后不再随名称自动覆盖 */
const codeEdited = ref(false)

/** 名称宽度额度：8 汉字 / 12 英文字符，混排按比例折算 */
const nameUnits = computed(() => spaceNameUnits(form.name))
const nameHint = computed(
  () => `已用 ${nameUnits.value} / ${SPACE_NAME_MAX_UNITS}（最多 8 个汉字或 12 个英文字符）`,
)

/** 新建时，名称变化即生成编码；编辑态编码不可改，跳过 */
watch(
  () => form.name,
  () => {
    if (isEdit.value || codeEdited.value) return
    const base = spaceCodeFromName(form.name)
    form.code = base ? uniqueSpaceCode(base, wiki.adminSpaces.map((s) => s.code)) : ''
  },
  { flush: 'sync' },
)

/** 名称实时按额度截断：原生 maxlength 会把汉字也当成 1 个字符 */
function onNameInput(event: Event): void {
  if (isEdit.value) return
  const input = event.target as HTMLInputElement
  const limited = limitSpaceName(input.value)
  if (input.value !== limited) input.value = limited
  form.name = limited
}

/** 编码实时裁剪到 16 字符，超长时保留 -project 后缀 */
function onCodeInput(event: Event): void {
  const input = event.target as HTMLInputElement
  const clamped = clampSpaceCode(input.value)
  if (input.value !== clamped) input.value = clamped
  form.code = clamped
  codeEdited.value = clamped.trim().length > 0
}

/** 授权对象展示名：目录命中用真名，否则回落到 ID */
const subjectName = (member: SpaceMemberItem): string => {
  const hit = nameMap.value[`${member.subject_type}:${member.subject_id}`]
  if (hit) return hit
  return `${member.subject_type === 'role' ? '角色' : '人员'} #${member.subject_id}`
}

async function loadSpaces(force = false): Promise<void> {
  loading.value = true
  try {
    await wiki.loadAdminSpaces(force)
  } finally {
    loading.value = false
  }
}

/** 按 ID 精确解析已授权对象名称，不受搜索条数限制，也能解析历史停用对象 */
async function resolveNames(rows: SpaceMemberItem[]): Promise<void> {
  for (const type of ['role', 'user'] as SubjectType[]) {
    const ids = [
      ...new Set(
        rows
          .filter(
            (row) =>
              row.subject_type === type &&
              !nameMap.value[`${type}:${Number(row.subject_id)}`],
          )
          .map((row) => Number(row.subject_id)),
      ),
    ]
    if (!ids.length) continue
    try {
      const res = await directoryApi.resolve(type, ids)
      for (const subject of res.data?.subjects ?? []) {
        nameMap.value[`${subject.subject_type}:${Number(subject.subject_id)}`] = subject.name
      }
    } catch {
      // 目录接口不可用时保持「角色 / 人员 #ID」展示
    }
  }
}

function openCreate(): void {
  if (!canCreate.value) return
  codeEdited.value = false
  form.id = 0
  form.name = ''
  form.code = ''
  form.description = ''
  form.is_restricted = false
  form.status = 1
  form.sort = 0
  members.value = []
  drawerVisible.value = true
}

function openEdit(spaceId: number): void {
  if (!canManage.value) return
  const space = wiki.adminSpaces.find((s) => Number(s.id) === Number(spaceId))
  if (!space) return
  form.id = space.id
  form.name = space.name
  form.code = space.code
  form.description = space.description ?? ''
  form.is_restricted = space.is_restricted === 1
  form.status = space.status ?? 1
  form.sort = space.sort ?? 0
  drawerVisible.value = true
  void loadMembers(space.id)
}

async function loadMembers(spaceId: number): Promise<void> {
  memberLoading.value = true
  try {
    const res = await spaceApi.members(spaceId)
    members.value = res.data?.members ?? []
    await resolveNames(members.value)
  } catch {
    members.value = []
  } finally {
    memberLoading.value = false
  }
}

async function saveSpace(): Promise<void> {
  if (!form.name.trim()) {
    ElMessage.warning('请填写空间名称')
    return
  }
  if (!isEdit.value && !form.code.trim()) {
    ElMessage.warning('请填写空间编码（新建后不可修改）')
    return
  }
  if (!isEdit.value && spaceNameUnits(form.name) > SPACE_NAME_MAX_UNITS) {
    ElMessage.warning('空间名称最多 8 个汉字或 12 个英文字符')
    return
  }
  if (!isEdit.value && form.code.trim().length > MAX_CODE_LENGTH) {
    ElMessage.warning(`空间编码最多 ${MAX_CODE_LENGTH} 个字符`)
    return
  }
  saving.value = true
  try {
    const payload = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      is_restricted: form.is_restricted ? 1 : 0,
      status: form.status,
      sort: form.sort,
    }
    if (isEdit.value) {
      await spaceApi.update(form.id, payload)
      ElMessage.success('空间已保存')
    } else {
      const res = await spaceApi.create({ ...payload, code: form.code.trim() })
      ElMessage.success('空间创建成功')
      const newId = Number(res.data?.id || 0)
      if (newId) {
        form.id = newId
        // 仅有创建权限的角色读不了成员授权接口，避免 403 误提示
        if (canManage.value) await loadMembers(newId)
      }
    }
    await loadSpaces(true)
  } finally {
    saving.value = false
  }
}

async function toggleStatus(): Promise<void> {
  if (!isEdit.value) return
  const next = form.status === 1 ? 0 : 1
  await spaceApi.update(form.id, { status: next })
  form.status = next
  ElMessage.success(next === 1 ? '空间已启用' : '空间已停用（入口不再对成员可见）')
  await loadSpaces(true)
}

async function removeSpace(): Promise<void> {
  if (!isEdit.value) return
  const space = wiki.adminSpaces.find((s) => Number(s.id) === Number(form.id))
  try {
    await ElMessageBox.confirm(
      `确定删除空间「${space?.name || form.name}」吗？空间下仍有文章时会被拒绝；删除同时清理其分类与成员授权。`,
      '删除空间',
      { type: 'error', confirmButtonText: '删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  await spaceApi.remove(form.id)
  ElMessage.success('空间已删除')
  drawerVisible.value = false
  await loadSpaces(true)
}

/** 切换单档权限：setMember 幂等（存在即更新） */
async function togglePerm(member: SpaceMemberItem, key: PermKey): Promise<void> {
  const next = member[key] === 1 ? 0 : 1
  await spaceApi.setMember(form.id, {
    subject_type: member.subject_type,
    subject_id: member.subject_id,
    can_view: key === 'can_view' ? next : member.can_view,
    can_edit: key === 'can_edit' ? next : member.can_edit,
    can_manage: key === 'can_manage' ? next : member.can_manage,
  })
  member[key] = next
  ElMessage.success('授权已更新')
}

/** 搜索选择器选中即写入授权，名称来自目录结果，无需手填 */
async function addMember(option: SubjectOption): Promise<void> {
  const id = Number(option.subject_id)
  if (!id || id <= 0) return
  nameMap.value[`${option.subject_type}:${id}`] = option.name
  await spaceApi.setMember(form.id, {
    subject_type: option.subject_type,
    subject_id: id,
    can_view: 1,
    can_edit: 0,
    can_manage: 0,
  })
  ElMessage.success(`已添加「${option.name}」（默认仅「可查看」，可再勾选提升）`)
  await loadMembers(form.id)
}

/** 批量保存：逐条幂等写回（与后端 setMember 语义一致） */
async function saveGrants(): Promise<void> {
  for (const member of members.value) {
    await spaceApi.setMember(form.id, {
      subject_type: member.subject_type,
      subject_id: member.subject_id,
      can_view: member.can_view,
      can_edit: member.can_edit,
      can_manage: member.can_manage,
    })
  }
  ElMessage.success('授权已保存')
  await loadMembers(form.id)
}

async function removeMember(member: SpaceMemberItem): Promise<void> {
  await spaceApi.removeMember(form.id, member.subject_type, member.subject_id)
  members.value = members.value.filter((row) => row.id !== member.id)
  ElMessage.success('授权已移除')
  await loadSpaces(true)
}

const membersOf = (type: SubjectType) => members.value.filter((row) => row.subject_type === type)
/** 当前类型下已授权的 ID，供搜索选择器标记「已添加」 */
const selectedMemberIds = computed(() =>
  membersOf(grantType.value).map((row) => Number(row.subject_id)),
)

onMounted(() => {
  void loadSpaces()
})
</script>

<template>
  <div class="page-max" style="padding: 6px 28px 40px">
    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 18px">
      <div>
        <div style="font-size: 24px; font-weight: 600">空间管理</div>
        <div style="font-size: 13px; color: var(--text-2); margin-top: 4px">
          按空间隔离知识内容；受限空间仅授权成员可见，且不参与全站统计。
        </div>
      </div>
      <button v-if="canCreate" type="button" class="btn btn-primary" style="margin-left: auto" @click="openCreate">
        <Plus :size="15" /> 新建空间
      </button>
    </div>

    <div v-if="loading" class="kb-empty">加载中…</div>

    <div v-else-if="wiki.adminSpaces.length" class="grid rise d1">
      <div
        v-for="space in wiki.adminSpaces"
        :key="space.id"
        class="space-card"
        :class="{ 'is-restricted': space.is_restricted === 1, 'is-disabled': space.status !== 1 }"
        @click="openEdit(space.id)"
      >
        <div class="space-card-head">
          <span class="space-card-name">
            <Lock v-if="space.is_restricted === 1" :size="13" class="space-card-lock" />
            <span class="truncate">{{ space.name }}</span>
          </span>
          <span class="pill" :class="space.is_restricted === 1 ? 'amber' : 'green'">
            {{ space.is_restricted === 1 ? '受限' : '公开' }}
          </span>
        </div>

        <div class="space-card-code">编码：{{ space.code }}</div>

        <div class="space-card-stats">
          <div><b class="space-stat-articles">{{ space.article_count ?? 0 }}</b><small>文章</small></div>
          <div><b class="space-stat-members">{{ space.is_restricted === 1 ? (space.member_count ?? 0) : '全部' }}</b><small>成员</small></div>
        </div>

        <span v-if="space.status !== 1" class="space-card-flag">已停用</span>
      </div>
    </div>

    <div v-else class="kb-empty rise d1">
      <div class="kb-empty-icon">🗂</div>
      <div style="margin-top: 10px">还没有空间</div>
      <div v-if="canCreate" style="margin-top: 6px; font-size: 12px">点击右上角「新建空间」创建第一个知识空间。</div>
    </div>

    <!-- 新建 / 编辑抽屉 -->
    <el-drawer v-model="drawerVisible" :title="isEdit ? '编辑空间' : '新建空间'" size="720px">
      <div>
        <div class="side-card-title">基础信息</div>
        <div class="field">
          <label><span class="required">*</span> 空间名称</label>
          <input v-model="form.name" class="input" placeholder="如：荣耀仙灵项目" @input="onNameInput" />
          <div class="cell-sub">{{ nameHint }}</div>
        </div>
        <div class="field">
          <label><span class="required">*</span> 空间编码（新建后不可修改）</label>
          <input v-model="form.code" class="input" placeholder="如：ryxl-project" :disabled="isEdit" @input="onCodeInput" />
          <div class="cell-sub">
            最长 {{ MAX_CODE_LENGTH }} 个字符；超长时自动裁剪前缀、保留 -project 后缀，编码由名称自动生成
          </div>
        </div>
        <div class="field">
          <label>空间描述</label>
          <textarea v-model="form.description" class="input" placeholder="空间用途说明，会展示在门户详情页"></textarea>
        </div>

        <div class="side-card-title" style="margin-top: 18px">空间类型</div>
        <div class="scope-opts">
          <div class="scope-opt" :class="{ active: !form.is_restricted }" @click="form.is_restricted = false">
            <span class="radio"></span>
            <div><b>公开空间</b><p>所有登录用户可见，成员均可创建内容</p></div>
          </div>
          <div class="scope-opt" :class="{ active: form.is_restricted }" @click="form.is_restricted = true">
            <span class="radio"></span>
            <div><b>受限空间</b><p>仅授权成员可见，内容不计入全站统计</p></div>
          </div>
        </div>

        <div v-if="isEdit && canManage" style="margin-top: 22px">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px">
            <div class="side-card-title" style="margin: 0">成员授权</div>
            <span v-if="form.is_restricted" class="pill amber">受限空间</span>
            <button type="button" class="btn btn-sm" style="margin-left: auto" @click="saveGrants">保存授权</button>
          </div>

          <div class="tabs" style="margin-bottom: 0">
            <div class="tab-item" :class="{ active: grantType === 'role' }" @click="grantType = 'role'">按角色授权</div>
            <div class="tab-item" :class="{ active: grantType === 'user' }" @click="grantType = 'user'">按人员授权</div>
            <div style="margin-left: auto; align-self: center; font-size: 12px; color: var(--text-3)">
              两种授权方式并存，权限取并集
            </div>
          </div>

          <div class="grant-panel" style="margin-top: 14px">
            <div class="grant-head">
              <span style="font-size: 13px; font-weight: 500">{{ grantType === 'role' ? '角色' : '人员' }}</span>
            </div>

            <div v-for="member in membersOf(grantType)" :key="member.id" class="grant-row">
              <div class="who">
                <span :class="member.subject_type === 'role' ? 'tag-role' : 'tag-user'">
                  {{ member.subject_type === 'role' ? '角色' : '人员' }}
                </span>
                {{ subjectName(member) }}
              </div>
              <div class="grant-checks">
                <span
                  v-for="perm in PERMS"
                  :key="perm.key"
                  class="chk"
                  :class="{ on: member[perm.key] === 1 }"
                  @click="togglePerm(member, perm.key)"
                >
                  <i></i>{{ perm.label }}
                </span>
              </div>
              <button type="button" class="link danger" @click="removeMember(member)">移除</button>
            </div>

            <div v-if="!membersOf(grantType).length" class="cell-sub" style="padding: 12px 14px">
              暂无{{ grantType === 'role' ? '角色' : '人员' }}授权
            </div>
          </div>

          <div style="display: flex; gap: 8px; margin-top: 12px; align-items: center">
            <KbSubjectPicker
              :type="grantType"
              :selected-ids="selectedMemberIds"
              @select="addMember"
            />
          </div>

          <div
            style="
              margin-top: 16px;
              padding: 12px 14px;
              background: #f6f9ff;
              border: 1px solid #e4eafb;
              border-radius: var(--r-s);
              font-size: 12px;
              color: var(--text-2);
              line-height: 1.8;
            "
          >
            未授权成员<b>完全看不到</b>受限空间：空间入口、其下分类页签、文章、搜索结果、相关推荐均不返回；该空间内容也不计入全站统计。
          </div>
        </div>
        <div v-else-if="!isEdit && canManage" class="cell-sub" style="margin-top: 16px">
          空间创建后即可在此维护成员授权（受限空间才需要）。
        </div>

        <div style="display: flex; gap: 8px; margin-top: 20px">
          <button
            type="button"
            class="btn btn-primary"
            style="flex: 1; justify-content: center"
            :disabled="saving || !(isEdit ? canManage : canCreate)"
            @click="saveSpace"
          >
            保存
          </button>
          <button v-if="isEdit && canManage" type="button" class="btn" style="flex: 1; justify-content: center" @click="toggleStatus">
            {{ form.status === 1 ? '停用' : '启用' }}
          </button>
          <button v-if="isEdit && canManage" type="button" class="btn" style="flex: none" @click="removeSpace">
            <Trash2 :size="14" />
          </button>
        </div>
      </div>
    </el-drawer>
  </div>
</template>
