# INUWIKI 部署手册（Ubuntu 22.04）

> 目标：承载 100 人同时操作（峰值并发 60–100）。
> 部署形态：单域名按路径分发，`/` 与 `/api` → INUEIP，`/kb` 与 `/kbapi` → INUWIKI。

---

## 一、目录规划

| 路径 | 用途 |
|---|---|
| `/var/www/inueip/backend`、`/var/www/inueip/frontend/dist` | 现有 EIP |
| `/var/www/inuwiki/backend`、`/var/www/inuwiki/frontend/dist` | 知识库 |
| `/data/inuwiki` | 知识库媒体存储（**建议独立挂载**，见容量规划） |
| `/var/log/supervisor/kb-worker.log` | 转码队列日志 |

`myconfig.upload.root` 需指向 `/data/inuwiki`，与 Nginx `internal-media` 的 `alias` 保持一致。

---

## 二、安装步骤

```bash
# 1. 基础组件
sudo apt update
sudo apt install -y nginx php8.2-fpm php8.2-mysql php8.2-redis php8.2-mbstring \
  php8.2-xml php8.2-curl php8.2-gd php8.2-imagick php8.2-zip redis-server \
  supervisor ffmpeg

# 2. 部署代码
sudo mkdir -p /var/www/inuwiki /data/inuwiki
sudo rsync -a --exclude=runtime --exclude=node_modules backend/ /var/www/inuwiki/backend/
sudo rsync -a frontend/dist/ /var/www/inuwiki/frontend/dist/

# 3. 后端依赖与配置
cd /var/www/inuwiki/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
#  编辑 .env：EIP_DB_*（只读 INUEIP）、KB_DB_*（读写 Inuwiki）、JWT_SECRET 换成随机 64 位串
#  编辑 config/myconfig.php：deploy.env=production、upload.root=/data/inuwiki、
#                            ffmpeg.bin=/usr/bin/ffmpeg、knowledge.* 各项

# 4. 建库与迁移
mysql -u root -e "CREATE DATABASE IF NOT EXISTS Inuwiki DEFAULT CHARACTER SET utf8mb4;"
php think migrate:run          # 建 kb_* 表（含目录快照 kb_subject），并向 INUEIP.inue_permission 注册 kb:* 权限点
php think kb:subject-sync      # 首次灌入角色/人员目录快照；之后由 crontab 定时同步

# 5. 前端构建
cd /var/www/inuwiki/frontend && npm ci && npm run build

# 6. 配置文件就位
sudo cp deploy/nginx/inuwiki.conf        /etc/nginx/conf.d/
sudo cp deploy/php-fpm/inuwiki.conf      /etc/php/8.2/fpm/pool.d/
sudo cp deploy/php/opcache.ini           /etc/php/8.2/fpm/conf.d/10-opcache.ini
sudo cp deploy/supervisor/kb-worker.conf /etc/supervisor/conf.d/

# 7. 权限
sudo chown -R www-data:www-data /var/www/inuwiki /data/inuwiki
sudo chmod -R 755 /data/inuwiki

# 8. 启动
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl restart php8.2-fpm
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start kb-worker:*

# 9. 定时任务（目录快照同步 + 清理暂存分片与回收站）
cat <<'CRON' | sudo crontab -
*/10 * * * * cd /var/www/inuwiki/backend && /usr/bin/php think kb:subject-sync
0 3 * * * cd /var/www/inuwiki/backend && /usr/bin/php think kb:gc
CRON
```

> `kb_subject` 每 10 分钟从 EIP 主库同步一次；授权目录的搜索与名称解析只读本地表，不再访问 EIP 主库。
> EIP 侧新增 / 改名最多延迟一个同步周期生效；需要立即刷新时手动执行 `php think kb:subject-sync`。
> 注意：登录校验（AuthService）与权限装配（RbacService）仍实时读取 EIP 主库，不在本次改造范围内。

---

## 三、发版流程（⚠️ 必读）

OPcache 设了 `validate_timestamps=0`，**代码更新后必须 reload php-fpm**，否则线上仍是旧代码：

```bash
sudo rsync -a backend/ /var/www/inuwiki/backend/
php think migrate:run                   # 回收站字段 + 版本表迁移（结构变更时必跑）
php think kb:asset-bind                 # 历史正文图片回填附件归属（幂等，改造后执行一次即可）
sudo systemctl reload php8.2-fpm        # 关键，不能漏
sudo supervisorctl restart kb-worker:*  # job 代码变更时
```

---

## 四、回滚预案

```bash
# 1. Nginx 回滚（保留上一版配置为 .bak）
sudo cp /etc/nginx/conf.d/inuwiki.conf.bak /etc/nginx/conf.d/inuwiki.conf
sudo nginx -t && sudo systemctl reload nginx

# 2. 代码回滚（发版前保留 /var/www/inuwiki/backend.prev）
sudo rm -rf /var/www/inuwiki/backend
sudo mv /var/www/inuwiki/backend.prev /var/www/inuwiki/backend
sudo systemctl reload php8.2-fpm

# 3. 数据库回滚（仅限本次新增表，down() 会删表，慎用）
php think migrate:rollback
```

---

## 五、容量规划

| 项 | 测算 |
|---|---|
| 单视频上限 | 1GB（转码降为 720p 后约省 40%） |
| 50 个视频 | ≈ 30–50 GB |
| 建议挂载 | `/data/inuwiki` ≥ 200 GB，独立磁盘 |
| 带宽 | 60 人 × 2 Mbps ≈ 120 Mbps，需确认内网/出口带宽 |

后台页脚展示已用容量；`kb:gc` 每日清理暂存分片与回收站（保留 30 天）。

---

## 六、压测验收方案

**工具**：`wrk` 或 `ab`。

```bash
# 安装
sudo apt install -y wrk

# 取一个超管 token
TOKEN=$(curl -s -X POST http://<host>/kbapi/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"<pwd>"}' | jq -r .data.token)

# 1) 列表接口（三档并发）
for C in 60 80 100; do
  wrk -t$C -c$C -d60s -H "Authorization: Bearer $TOKEN" \
      "http://<host>/kbapi/articles?page=1&size=10"
done

# 2) 详情接口（含正文与附件查询）
wrk -t100 -c100 -d60s -H "Authorization: Bearer $TOKEN" \
    "http://<host>/kbapi/articles/1"

# 3) 视频场景（关键：验证 PHP-FPM 不被占用）
#    30 路并发请求媒体播放，同时压列表接口
wrk -t30 -c30 -d120s -H "Authorization: Bearer $TOKEN" \
    "http://<host>/kbapi/articles/1/media/1" &
wrk -t60 -c60 -d120s -H "Authorization: Bearer $TOKEN" \
    "http://<host>/kbapi/articles?page=1&size=10"
# 观察 PHP-FPM 活跃进程数是否被视频请求占满：
watch -n1 "curl -s http://<host>/phpfpm-status"
```

**验收口径**：

| 指标 | 目标 |
|---|---|
| 60/80/100 并发下 API P95 | < 500 ms |
| 错误率 | < 0.5% |
| 30 路并发播放期间其余 API P95 劣化 | ≤ 20% |
| PHP-FPM active processes（视频压测时） | 不持续打满 `pm.max_children` |

**注**：压测必须在 Ubuntu 生产形态下执行才有效。Windows + XAMPP + PHP 内置服务器的结果不具参考价值。

---

## 七、上线检查清单

- [ ] `JWT_SECRET` 已更换为随机 64 位串（EIP 与 INUWIKI 各自独立）
- [ ] `myconfig.deploy.env = production`
- [ ] EIP 数据库连接使用**只读账号**
- [ ] OPcache 已启用，且发版脚本含 `reload php8.2-fpm`
- [ ] `/internal-media/` 为 `internal`，外部直接访问返回 404
- [ ] `/uploads/knowledge/` 与 `/kbuploads/` 返回 404，图片只能经 `/kbapi/assets` 读取
- [ ] 已执行 `php think migrate:run`，回收站字段与 `kb_article_version` 表就绪
- [ ] 已执行 `php think kb:asset-bind` 回填历史正文图片归属
- [ ] uploads 目录已禁止执行 PHP
- [ ] Supervisor 守护 `queue:work`，日志无异常
- [ ] `kb:gc` 定时任务已配置
- [ ] 已在 EIP 后台为相应角色分配 `kb:*` 权限点
- [ ] 备份策略：`/data/inuwiki` 与 `Inuwiki` 库定期备份
