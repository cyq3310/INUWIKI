<?php
declare(strict_types=1);

namespace app\service;

/**
 * 富文本 XSS 白名单过滤（HTMLPurifier）
 *
 * 与 INUEIP 的差异：额外放行正文内插入的知识库占位节点
 *   - div[data-kb-video]  正文段落内插入的视频
 *   - a[data-kb-file]     正文段落内插入的附件
 * 否则这些节点会被 XSS 过滤掉，导致"插入到指定段落"失效
 */
class PurifierService
{
    public static function purify(string $html): string
    {
        static $purifier = null;
        if ($purifier === null) {
            $cacheDir = app()->getRuntimePath() . 'purifier';
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Cache.SerializerPath', $cacheDir);
            // 必须显式放行 div 与其 data-* 属性，否则占位节点会被整段删除。
            // 同时补齐 wangEditor 默认工具栏实际会产出的节点：
            //   span[style]        文字颜色 / 背景色
            //   pre/code[class]    代码块（class 保留语言标识）
            //   table/th/td[colspan|rowspan|style]  表格与合并单元格
            $config->set(
                'HTML.Allowed',
                'p,br,strong,em,u,s,blockquote,ul,ol,li,h1,h2,h3,h4,' .
                'a[href|target|data-kb-file],img[src|alt|style|width|height],' .
                'div[data-kb-video|data-kb-file],' .
                'span[style],pre[class|style],code[class|style],' .
                'table[style],thead,tbody,tfoot,tr[style],' .
                'th[colspan|rowspan|style],td[colspan|rowspan|style],' .
                'colgroup,col[span|width],hr'
            );
            // color / background-color 是文字颜色与背景色所必需；
            // width / height 供图片拖拽与表格宽度使用。
            // 仍禁止 position / display / url() 等高风险属性，避免 XSS 与布局劫持。
            $config->set('CSS.AllowedProperties', 'color,background-color,width,height');

            // 自定义属性定义（HTMLPurifier 默认不支持 data-*，需显式声明）
            $config->set('HTML.DefinitionID', 'inuwiki-content');
            $config->set('HTML.DefinitionRev', 1);
            if ($def = $config->maybeGetRawHTMLDefinition()) {
                $def->addAttribute('div', 'data-kb-video', 'Text');
                $def->addAttribute('div', 'data-kb-file', 'Text');
                $def->addAttribute('a', 'data-kb-file', 'Text');
            }

            $purifier = new \HTMLPurifier($config);
        }
        return $purifier->purify($html);
    }
}
