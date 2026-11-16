<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\ArticleService;
use app\service\MediaService;
use app\service\SpaceService;
use think\facade\Db;

/**
 * 媒体播放：只做鉴权，字节流交给 Nginx（X-Accel-Redirect + sendfile）
 * 这是 60–100 并发下 PHP-FPM 不被占用、且支持 Range 拖拽的关键
 */
class Media extends BaseController
{
    public function play()
    {
        $articleId    = (int) $this->request->param('id', 0);
        $attachmentId = (int) $this->request->param('attachment_id', 0);

        $attachment = Db::name('kb_attachment')->where('id', $attachmentId)->find();
        if (!$attachment) {
            return resp_fail('资源不存在', 404);
        }

        $article = Db::name('kb_article')
            ->where('id', $articleId)
            ->where('status', '<>', \app\model\Article::STATUS_DELETED)
            ->find();
        if (!$article) {
            return resp_fail('知识不存在', 404);
        }

        // 附件必须隶属于该文章
        if ((int) $attachment['article_id'] !== $articleId) {
            return resp_fail('资源不存在', 404);
        }

        // 可见性校验：空间可见 + 文章可见范围
        $visible = (new SpaceService())->visibleSpaceIds(kb_user_id(), kb_role_ids(), kb_is_super());
        if (!in_array((int) $article['space_id'], $visible, true)) {
            return resp_fail('资源不存在', 404);
        }
        if (!(new ArticleService())->canViewArticle($article, kb_user_id(), kb_role_ids(), kb_is_super())) {
            return resp_fail('资源不存在', 404);
        }

        // 视频必须转码完成
        if ($attachment['type'] === 'video' && $attachment['status'] !== 'ready') {
            return resp_fail('视频尚未就绪', 409);
        }

        $media = new MediaService();
        $abs   = $media->absolutePath((string) $attachment['path']);
        if (!$abs || !is_file($abs)) {
            return resp_fail('资源文件不存在', 404);
        }

        $mime = $attachment['mime'] ?: 'application/octet-stream';
        $name = (string) ($attachment['original_name'] ?: ('attachment-' . $attachmentId));

        // 视频保持 inline 供 <video> 播放；附件强制下载并保留原始文件名（含中文）
        $disposition = $attachment['type'] === 'video'
            ? 'inline'
            : "attachment; filename*=UTF-8''" . rawurlencode($name);

        $headers = [
            'X-Accel-Buffering'      => 'no',
            'Content-Type'           => $mime,
            'Content-Disposition'    => $disposition,
            'X-Content-Type-Options' => 'nosniff',
        ];

        // 生产由 Nginx internal location sendfile；本地 PHP 内置服务器没有 Nginx，需直接输出字节
        if (config('myconfig.deploy.env') === 'production') {
            $headers['X-Accel-Redirect'] = $media->internalRedirect((string) $attachment['path']);
            return response('')->header($headers);
        }

        $headers['Content-Length'] = (string) filesize($abs);
        return response((string) file_get_contents($abs))->header($headers);
    }
}
