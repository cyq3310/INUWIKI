<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\ArticleService;
use app\service\AuthService;
use app\service\MediaService;
use app\service\RbacService;
use app\service\SpaceService;
use think\facade\Db;

/**
 * 图片等上传资源：由后端鉴权后输出，替代 nginx 静态直出。
 *
 * <img src> 由浏览器直接发起，无法携带 Authorization 头，因此登录时另下发
 * HttpOnly 的受限资源票据 cookie（见 Auth::login）。资源 URL 只含稳定的 path，
 * 不含任何凭证，可安全写进正文 HTML 长期保存。
 */
class Asset extends BaseController
{
    private const COOKIE = 'kb_asset';

    public function image()
    {
        $userId = (new AuthService())->verifyAssetTicket(
            (string) $this->request->cookie(self::COOKIE, '')
        );
        if ($userId <= 0) {
            return auth_expired_response();
        }

        $path = ltrim((string) $this->request->get('path', ''), '/');
        if (!preg_match('#^(image|video|file)/[A-Za-z0-9_./-]+$#', $path) || str_contains($path, '..')) {
            return resp_fail('资源路径不合法', 400, null, 400);
        }

        $abs  = upload_abs_path('knowledge', $path);
        $real = realpath($abs);
        $base = realpath(upload_dir('knowledge'));
        if ($real === false || $base === false
            || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)
            || !is_file($real)) {
            return resp_fail('资源不存在', 404, null, 404);
        }

        // 已归属文章的附件按文章可见范围校验；游离附件（article_id=0，如正文粘贴图）登录即可
        $attachment = Db::name('kb_attachment')->where('path', $path)->find();
        if ($attachment && (int) $attachment['article_id'] > 0) {
            $article = Db::name('kb_article')->find((int) $attachment['article_id']);
            if (!$article || $article['status'] === \app\model\Article::STATUS_DELETED) {
                return resp_fail('资源不存在', 404, null, 404);
            }
            try {
                $profile = (new RbacService())->buildProfile($userId);
            } catch (\Throwable $e) {
                return auth_expired_response('登录状态无效，请重新登录');
            }
            $roleIds = array_map('intval', $profile['roleIds'] ?? []);
            $isSuper = !empty($profile['isSuperAdmin']);
            $visible = (new SpaceService())->visibleSpaceIds($userId, $roleIds, $isSuper);
            $allowed = in_array((int) $article['space_id'], $visible, true)
                && (new ArticleService())->canViewArticle($article, $userId, $roleIds, $isSuper);
            if (!$allowed) {
                return resp_fail('无权访问该资源', 403, null, 403);
            }
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($real) ?: 'application/octet-stream';
        $headers = [
            'Content-Type'        => $mime,
            'Cache-Control'       => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // 生产由 Nginx internal location sendfile；本地 PHP 内置服务器直接输出字节
        if (config('myconfig.deploy.env') === 'production') {
            $headers['X-Accel-Redirect'] = (new MediaService())->internalRedirect($path);
            return response('', 200, $headers);
        }
        return response((string) file_get_contents($real), 200, $headers);
    }
}
