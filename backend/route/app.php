<?php

use app\middleware\AuthCheck;
use app\middleware\OperationLog;
use app\middleware\PermissionCheck;
use think\facade\Route;

// 认证（公开接口）
Route::post('kbapi/auth/login', 'Auth/login');
// 资源票据有效性探测（只返回布尔值，供前端资源加载失败时判断是否回登录页）
Route::get('kbapi/auth/asset-ticket', 'Auth/assetTicket');

// 平台公开配置（免登录，仅下发 myconfig 白名单项）
Route::get('kbapi/config', 'Config/publicConfig');

// 图片资源：由 cookie 中的资源票据鉴权（<img> 不能带 Authorization 头），
// 刻意放在需登录的 group 之外，独立校验，避免放宽业务接口的认证方式。
Route::get('kbapi/assets', 'Asset/image');

// 需登录的接口
Route::group('kbapi', function () {
    Route::post('auth/logout', 'Auth/logout');
    Route::get('auth/profile', 'Auth/profile');

    // 门户：可见空间与其分类树
    Route::get('spaces', 'Space/index');
    Route::get('spaces/:id/categories', 'Space/categories');
    Route::get('spaces/:id/doc-types', 'admin.DocType/portalList');

    // 门户：知识列表 / 详情 / 统计（统计为口径 A，只含公开空间）
    Route::get('articles', 'Article/index');
    Route::get('articles/stats', 'Article/stats');
    Route::get('articles/:id/versions', 'Article/versions');
    Route::get('articles/:id/versions/:vid', 'Article/versionDetail');
    Route::get('articles/:id', 'Article/detail');

    // 媒体播放：鉴权后 X-Accel-Redirect 交给 Nginx 直发
    Route::get('articles/:id/media/:attachment_id', 'Media/play');

    // 后台管理
    Route::group('admin', function () {
        // 角色 / 人员目录（WIKI 本地快照，登录后全员可用）
        Route::get('subjects', 'admin.Subject/index');

        // 空间列表：有管理或创建权限均可进入；新建空间单独由 kb:space:create 控制
        Route::get('spaces', 'admin.Space/index')->middleware(PermissionCheck::class, 'kb:space:manage,kb:space:create');
        Route::post('spaces', 'admin.Space/save')->middleware(PermissionCheck::class, 'kb:space:create');
        Route::put('spaces/:id', 'admin.Space/update')->middleware(PermissionCheck::class, 'kb:space:manage');
        Route::delete('spaces/:id', 'admin.Space/delete')->middleware(PermissionCheck::class, 'kb:space:manage');
        Route::get('spaces/:id/members', 'admin.Space/members')->middleware(PermissionCheck::class, 'kb:space:manage');
        Route::post('spaces/:id/members', 'admin.Space/setMember')->middleware(PermissionCheck::class, 'kb:space:manage');
        Route::delete('spaces/:id/members', 'admin.Space/removeMember')->middleware(PermissionCheck::class, 'kb:space:manage');

        Route::get('categories', 'admin.Category/index')->middleware(PermissionCheck::class, 'kb:category:manage');
        Route::post('categories', 'admin.Category/save')->middleware(PermissionCheck::class, 'kb:category:manage');
        Route::put('categories/:id', 'admin.Category/update')->middleware(PermissionCheck::class, 'kb:category:manage');
        Route::delete('categories/:id', 'admin.Category/delete')->middleware(PermissionCheck::class, 'kb:category:manage');

        // 配置管理三项各自独立鉴权，与前端页签一一对应
        Route::get('doc-types', 'admin.DocType/index')->middleware(PermissionCheck::class, 'kb:doc_type:manage');
        Route::post('doc-types', 'admin.DocType/save')->middleware(PermissionCheck::class, 'kb:doc_type:manage');
        Route::put('doc-types/:id', 'admin.DocType/update')->middleware(PermissionCheck::class, 'kb:doc_type:manage');
        Route::delete('doc-types/:id', 'admin.DocType/delete')->middleware(PermissionCheck::class, 'kb:doc_type:manage');

        // 权限配置：按角色 / 人员授予 kb:* 权限码（全局，不随空间切换）
        Route::get('permissions', 'admin.Permission/index')->middleware(PermissionCheck::class, 'kb:permission:manage');
        Route::get('permissions/global-required', 'admin.Permission/globalRequired')->middleware(PermissionCheck::class, 'kb:permission:manage');
        Route::post('permissions', 'admin.Permission/save')->middleware(PermissionCheck::class, 'kb:permission:manage');
        Route::post('permissions/remove', 'admin.Permission/remove')->middleware(PermissionCheck::class, 'kb:permission:manage');

        // 操作日志：只读查询，对所有已登录用户开放（AuthCheck 已保证登录态）
        Route::get('operation-logs', 'admin.OperationLog/index');

        // 知识库运行统计：只读聚合值（磁盘容量 + 转码队列），供后台列表页脚展示
        Route::get('kb/stats', 'admin.Kb/stats');

        // 文章读写改为 ArticlePolicy 对象级判定（本人默认可操作、他人需对应码），
        // 不能用路由中间件一刀切拦截，否则无码用户连自己的文章都改不了。
        Route::get('articles', 'admin.Article/index');
        Route::post('articles', 'admin.Article/save')->middleware(PermissionCheck::class, 'kb:article:create');
        Route::put('articles/:id', 'admin.Article/update');
        Route::delete('articles/:id', 'admin.Article/delete');
        Route::post('articles/:id/status', 'admin.Article/setStatus');
        Route::get('articles/:id/grants', 'admin.Article/grants');
        Route::post('articles/:id/grants', 'admin.Article/setGrants');
        // 附件绑定：save/update 成功后由前端调用，只要求能编辑该文章（控制器内判定）
        Route::post('articles/:id/attachments', 'admin.Article/attach');

        // 回收站：恢复按删除权限、彻底清除按 kb:article:purge，均在控制器内按文章判定
        Route::get('articles/:id', 'admin.Article/detail');
        Route::post('articles/:id/restore', 'admin.Article/restore');
        Route::post('articles/:id/purge', 'admin.Article/purge');
        // 历史版本：只存文本快照，不复制附件
        Route::get('articles/:id/versions', 'admin.Article/versions');
        Route::get('articles/:id/versions/:vid', 'admin.Article/versionDetail');
        Route::post('articles/:id/versions/:vid/restore', 'admin.Article/restoreVersion');

        // 分片上传
        Route::post('kb/uploads/init', 'admin.Upload/init')->middleware(PermissionCheck::class, 'kb:media:upload');
        Route::get('kb/uploads/check', 'admin.Upload/check')->middleware(PermissionCheck::class, 'kb:media:upload');
        Route::post('kb/uploads/chunk', 'admin.Upload/chunk')->middleware(PermissionCheck::class, 'kb:media:upload');
        Route::post('kb/uploads/complete', 'admin.Upload/complete')->middleware(PermissionCheck::class, 'kb:media:upload');
        Route::get('kb/attachments', 'admin.Upload/attachment')->middleware(PermissionCheck::class, 'kb:media:upload');
        Route::post('kb/images', 'admin.Upload/image')->middleware(PermissionCheck::class, 'kb:media:upload');
    })->middleware(OperationLog::class);
})->middleware(AuthCheck::class);
