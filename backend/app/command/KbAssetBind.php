<?php
declare(strict_types=1);

namespace app\command;

use app\service\ArticleService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 历史正文资源归属回填。
 *
 * 上传资源改走 /kbapi/assets 鉴权后，受限文章的可见性依赖 kb_attachment.article_id。
 * 改造前保存的正文图片从未绑定，需执行一次回填；之后新建/编辑文章由
 * ArticleService 自动绑定，无需再跑。幂等，可重复执行。
 *
 * 用法：php think kb:asset-bind
 */
class KbAssetBind extends Command
{
    protected function configure(): void
    {
        $this->setName('kb:asset-bind')
            ->setDescription('回填历史文章正文引用的图片/附件归属，使资源鉴权按文章可见性生效');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service  = new ArticleService();
        $articles = 0;
        $bound    = 0;

        Db::name('kb_article')
            ->field('id, content')
            ->where('status', '<>', \app\model\Article::STATUS_DELETED)
            ->chunk(200, function ($rows) use ($service, &$articles, &$bound): void {
                foreach ($rows as $row) {
                    $articles++;
                    $bound += $service->bindContentAssets(
                        (int) $row['id'],
                        (string) ($row['content'] ?? '')
                    );
                }
            });

        $output->writeln(sprintf('扫描 %d 篇文章，回填 %d 个附件归属', $articles, $bound));
        return 0;
    }
}
