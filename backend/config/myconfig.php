<?php
// +----------------------------------------------------------------------
// | INUWIKI 平台唯一配置源
// +----------------------------------------------------------------------
// | 规则来源：INUEIP 项目的 .codebuddy/rules/统一配置与发布规范.md
// | 1. 全平台"换环境可能变"的配置只允许写在本文件，禁止散落到业务代码
// | 2. 读取方式：config('myconfig.分组.键')，如 config('myconfig.upload.root')
// | 3. 值一律写真实值（含生产 IP/端口/密钥），因此本文件变更必须经用户确认后才可上传
// | 4. 本文件不得有任何输出，UTF-8 无 BOM
// | 5. 数据库连接参数属于例外：走 .env（EIP_DB_* / KB_DB_*），见 config/database.php
// +----------------------------------------------------------------------

return [

    // site：站点展示信息
    'site' => [
        'name'      => '吟游廊 WIKI',
        'version'   => '1.0.0',
        'copyright' => 'INUEIP',
        'icp'       => '',
    ],

    // deploy：部署环境。env=local|staging|production
    'deploy' => [
        'env' => 'local',

        // api：知识库后端对外地址
        'api' => [
            'scheme'   => 'http',
            'host'     => '127.0.0.1',
            'port'     => 8001,
            'base_url' => 'http://127.0.0.1:8001/kbapi',
            'timeout'  => 15,
        ],

        // web：知识库前端对外地址
        'web' => [
            'scheme'   => 'http',
            'host'     => '127.0.0.1',
            'port'     => 5174,
            'base_url' => 'http://127.0.0.1:5174',
        ],

        // eip：EIP 门户地址（用于"返回门户"等跳转）
        'eip' => [
            'base_url' => 'http://127.0.0.1:5173',
        ],

        // resource：静态资源来源。mode=local 走站点自身路径；mode=cdn 用 base_url
        'resource' => [
            'mode'     => 'local',
            'base_url' => '',
        ],

        // cors：跨域白名单（正式环境禁止填 *）
        'cors' => [
            'allow_origin' => 'http://127.0.0.1:5174',
        ],
    ],

    // jwt：知识库独立令牌（与 EIP 密钥相互独立，不共享）
    'jwt' => [
        'secret' => 'change-me-to-a-random-64-char-string', // 正式部署前必须更换为 64 位随机串并与 .env 同步
        // 有效期为"当天有效"：签发时 exp 固定为当日 23:59:59，无滑动续期
        'expire_mode' => 'today',
    ],

    // upload：上传存储配置。driver=local 存本机；driver=cdn 交由远端存储
    // 与 INUEIP 的 myconfig.upload 保持同构
    'upload' => [
        'driver'      => 'local',
        'root'        => '',          // 留空表示 backend/public/uploads
        'url_prefix'  => '/uploads',
        'max_size'    => 10485760,    // 默认单文件上限 10MB（图片走此值；视频走 knowledge.video_max）
        'allowed_ext' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    ],

    // knowledge：知识库业务配置
    'knowledge' => [
        'upload_prefix'   => '/uploads/knowledge', // 与 EIP announcement.upload_prefix 同约定（DB 只存相对路径）
        'remote_base_url' => '',                   // 预留 CDN 根地址；非空时优先使用
        'chunk_size'      => 5 * 1024 * 1024,      // 单片大小，与 nginx client_max_body_size 联动
        'image_max'       => 10 * 1024 * 1024,
        'video_max'       => 1024 * 1024 * 1024,   // 单视频上限 1GB
        'file_max'        => 50 * 1024 * 1024,     // 单个附件上限 50MB
        'image_ext'       => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        'video_ext'       => ['mp4', 'mov', 'mkv', 'avi', 'webm'],
        'file_ext'        => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt', 'fbx', 'glb'],
        'mime_sniff'      => true,                 // 在扩展名之上补 finfo 真实类型校验（用户确认开启）
        'version_keep'    => 20,                   // 每篇文章保留的历史版本数，超出由 kb:gc 清理
    ],

    // ffmpeg：转码配置（二进制路径不硬编码到 service）
    'ffmpeg' => [
        'bin'    => '/usr/bin/ffmpeg',
        'ffprobe' => '/usr/bin/ffprobe',
        'preset' => 'veryfast',
        'height' => 720,
    ],

    // media：媒体播放。internal_prefix 必须与 nginx internal location 严格一致
    'media' => [
        'internal_prefix' => '/internal-media',
    ],

    // trash：统一回收站（删除时先移入此处，由 kb:gc 按保留期清理）
    'trash' => [
        'root'                 => '',  // 留空表示 upload.root/trash
        'retention_days'       => 30,
        'temp_dir'             => '_tmp',
        'temp_retention_hours' => 24,
    ],

    // oplog：独立操作日志文件（JSON Lines，每月一个目录、每天一个文件）
    // 注意：此分组禁止加入 frontend_public.keys
    'oplog' => [
        'dir'             => '',    // 留空表示 backend/runtime/oplog；可配绝对路径
        'detail_max'      => 2000,  // 单条描述最大长度
        'db_enabled'      => true,  // 同时写入按月分区的 operation_log 表
        'trusted_proxies' => [],    // 额外可信反向代理 IP/CIDR；本机回环始终可信
    ],

    // log_gc：运行日志/操作日志定时清理（kb:log-gc），参考 EIP 180 天保留策略
    // 注意：此分组禁止加入 frontend_public.keys
    'log_gc' => [
        'retention_days' => 180,
        'max_total_mb'   => 512,
        'scan_dirs'      => [
            'runtime/log',
            'runtime/oplog',
        ],
    ],

    // frontend_public：允许下发到前端的键白名单（值为"分组.键"）
    // database / jwt 任何键都不得加入
    'frontend_public' => [
        'keys' => [
            'site.name',
            'site.version',
            'site.copyright',
            'site.icp',
            'deploy.env',
            'deploy.api.base_url',
            'deploy.api.timeout',
            'deploy.web.base_url',
            'deploy.eip.base_url',
            'deploy.resource.mode',
            'deploy.resource.base_url',
        ],
    ],
];
