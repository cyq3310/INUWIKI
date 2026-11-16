# 更新日志 (CHANGELOG)

> 每次发布 / 重要提交请追加一个版本条目。

## [v0.1.0] 2026-11-16
### 变更内容
- 功能：首次上传 INUWIKI 知识库项目（ThinkPHP 8 后端 + Vue3/Vite 前端）。包含空间 / 内容 / 文章 / 权限 / 学科 / 文档类型等核心模块，以及文章版本管理、回收站、权限授予等功能
- 修复：无

### 数据库结构
- 有
- 迁移方式：执行 `backend/database/migrations/` 下全部 13 个 ThinkPHP 迁移脚本（含建表、种子权限、视图、字段新增及权限拆分）

### 升级注意事项
- 首次部署需先运行迁移；`backend/public/uploads/` 为用户上传目录，不纳入版本管理，部署时需单独创建并保证可写
- 不要提交测试数据、数据库导出及 AI 工具本地产物；详见《提交规范.md》

## [v0.1.1] 2026-11-16
### 变更内容
- 功能：新增操作日志。后端以 JSON Lines 文件日志 + 按月分区 `operation_log` 表双写（中间件自动记录，支持真实客户端 IP），新增只读查询接口 `kbapi/operation-logs`（对所有登录用户开放）；新增 `kb:log-gc`（日志清理）与 `oplog:maintain`（分区维护）两个定时任务；前端新增"操作日志"管理页，并补充对象类型区分与相关配置/部署说明
- 修复：无

### 数据库结构
- 有
- 迁移方式：执行 `backend/database/migrations/` 下 4 个新迁移（007 创建按月分区 `operation_log` 表；008/009 先加后撤 `kb:oplog:view` 权限，最终操作日志对所有登录用户可见；010 新增 `object_type` 字段）

### 升级注意事项
- 部署后需运行 `php think migrate:run`；配置两条定时任务：`kb:log-gc`（每日清理 180 天前的日志）、`oplog:maintain`（每月 1 日维护分区）。操作日志目录 `backend/runtime/oplog/` 需可写
- 若部署时前面还有反向代理，需透传 `X-Real-IP` / `X-Forwarded-For` 并把该代理 IP/CIDR 配到 `myconfig.oplog.trusted_proxies`，否则日志中的客户端 IP 不准
- 未执行迁移时查询接口会自动建表兜底，但规范部署务必执行迁移

## [版本号] YYYY-MM-DD
### 变更内容
- 功能：
- 修复：

### 数据库结构
- 有 / 无
- 迁移方式：（如执行 database/xxx 迁移脚本，或手动执行 SQL）

### 升级注意事项
-
