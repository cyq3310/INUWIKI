<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { RefreshCw, RotateCcw, Search } from 'lucide-vue-next'
import { oplogApi } from '../../api'
import type { OperationLogItem } from '../../types'

/** 后台 · 操作日志：只读查询按月分区的 operation_log 表 */
const loading = ref(false)
const rows = ref<OperationLogItem[]>([])
const total = ref(0)
const notice = ref('')

const query = reactive({
  page: 1,
  size: 10,
  keyword: '',
  username: '',
  result: '',
  date_from: '',
  date_to: '',
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await oplogApi.list({
      page: query.page,
      size: query.size,
      keyword: query.keyword || undefined,
      username: query.username || undefined,
      result: query.result || undefined,
      date_from: query.date_from || undefined,
      date_to: query.date_to || undefined,
    })
    rows.value = res.data?.list ?? []
    total.value = Number(res.data?.total ?? 0)
    notice.value = res.data?.notice ?? ''
  } catch {
    rows.value = []
    total.value = 0
    notice.value = '操作日志接口读取失败，请检查后端服务与部署版本。'
  } finally {
    loading.value = false
  }
}

function search(): void {
  query.page = 1
  void load()
}

function reset(): void {
  query.page = 1
  query.size = 10
  query.keyword = ''
  query.username = ''
  query.result = ''
  query.date_from = ''
  query.date_to = ''
  void load()
}

function onSizeChange(): void {
  query.page = 1
  void load()
}

function resultText(value: string): string {
  return value === 'fail' ? '失败' : '成功'
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page-max cfg-page">
    <div class="toolbar rise d1 cfg-toolbar">
      <div>
        <h1 class="cfg-title">操作日志</h1>
        <p class="cfg-sub">查询后台写操作留痕。数据同时来自按月分区的数据库表，保留期由 oplog:maintain 维护。</p>
      </div>
      <div class="cfg-toolbar-right">
        <button type="button" class="btn" @click="load"><RefreshCw :size="14" /> 刷新</button>
      </div>
    </div>

    <div v-if="notice" class="oplog-notice rise d2">{{ notice }}</div>

    <div class="card-solid rise d2 oplog-filter">
      <div class="oplog-filter-grid">
        <el-input v-model="query.keyword" class="oplog-control" placeholder="关键词：账号 / 动作 / 描述 / IP / 路径" clearable @keyup.enter="search" />
        <el-input v-model="query.username" class="oplog-control" placeholder="操作账号" clearable @keyup.enter="search" />
        <el-select v-model="query.result" class="oplog-control" placeholder="执行结果" clearable>
          <el-option label="成功" value="success" />
          <el-option label="失败" value="fail" />
        </el-select>
        <el-date-picker v-model="query.date_from" class="oplog-control" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" clearable />
        <el-date-picker v-model="query.date_to" class="oplog-control" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" clearable />
      </div>
      <div class="oplog-filter-actions">
        <button type="button" class="btn btn-primary" @click="search"><Search :size="14" /> 查询</button>
        <button type="button" class="btn" @click="reset"><RotateCcw :size="14" /> 重置</button>
      </div>
    </div>

    <div class="card-solid rise d2 oplog-table" v-loading="loading">
      <el-table :data="rows" border stripe size="small" empty-text="暂无操作日志">
        <el-table-column prop="created_at" label="时间" width="160" />
        <el-table-column label="操作人" width="150">
          <template #default="{ row }">
            <div class="cell-title">{{ row.username || (row.display_user_id ? `用户 #${row.display_user_id}` : '系统') }}</div>
            <div v-if="row.display_user_id" class="cell-sub">ID {{ row.display_user_id }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="action" label="操作类型" width="170" show-overflow-tooltip />
        <el-table-column prop="detail" label="描述" min-width="180" show-overflow-tooltip />
        <el-table-column label="请求" min-width="190" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.method || '—' }} {{ row.path || '—' }}
          </template>
        </el-table-column>
        <el-table-column prop="ip" label="IP" width="130" />
        <el-table-column label="结果" width="86">
          <template #default="{ row }">
            <span class="pill" :class="row.result === 'fail' ? 'red' : 'green'">{{ resultText(row.result) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="对象 / 空间" min-width="220">
          <template #default="{ row }">
            <div class="cell-title">{{ row.object_label || '—' }}</div>
            <div class="cell-sub">{{ row.space_name || (row.space_id ? `空间 #${row.space_id}` : '全局') }}</div>
          </template>
        </el-table-column>
        <el-table-column label="耗时" width="90">
          <template #default="{ row }">{{ row.duration_ms }} ms</template>
        </el-table-column>
      </el-table>

      <div class="oplog-pagination">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.size"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          @current-change="load"
          @size-change="onSizeChange"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.oplog-filter {
  padding: 16px;
  margin-bottom: 16px;
}

.oplog-notice {
  margin-bottom: 16px;
  padding: 10px 14px;
  border: 1px solid var(--primary-line, #cddaff);
  border-radius: 10px;
  background: var(--primary-weak, #f2f6ff);
  color: var(--primary);
  font-size: 13px;
  line-height: 1.6;
}

.oplog-filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 10px;
  min-width: 0;
}

.oplog-control {
  width: 100%;
  min-width: 0;
  max-width: 100%;
}

.oplog-filter-grid :deep(.el-input),
.oplog-filter-grid :deep(.el-select),
.oplog-filter-grid :deep(.el-date-editor) {
  width: 100% !important;
  min-width: 0;
  max-width: 100%;
  box-sizing: border-box;
}

.oplog-filter-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 12px;
}

.oplog-table {
  padding: 12px;
  min-width: 0;
  overflow: hidden;
}

.oplog-table :deep(.el-table) {
  width: 100%;
}

.oplog-pagination {
  display: flex;
  justify-content: flex-end;
  padding-top: 14px;
}

@media (max-width: 700px) {
  .oplog-filter-grid {
    grid-template-columns: 1fr;
  }
}
</style>
