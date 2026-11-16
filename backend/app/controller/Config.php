<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;

class Config extends BaseController
{
    /**
     * 下发前端的公开配置（仅白名单内的键）
     * database / jwt 任何键都不得加入 myconfig.frontend_public.keys
     */
    public function publicConfig()
    {
        $keys = (array) config('myconfig.frontend_public.keys', []);
        $data = [];

        foreach ($keys as $key) {
            // 支持任意层级，如 deploy.api.base_url
            $parts = explode('.', (string) $key);
            if ($parts === [] || $parts[0] === '') {
                continue;
            }
            $value = config('myconfig.' . $key);

            $node = &$data;
            $last = count($parts) - 1;
            foreach ($parts as $i => $part) {
                if ($i === $last) {
                    $node[$part] = $value;
                } else {
                    if (!isset($node[$part]) || !is_array($node[$part])) {
                        $node[$part] = [];
                    }
                    $node = &$node[$part];
                }
            }
            unset($node);
        }

        return resp_ok($data);
    }
}
