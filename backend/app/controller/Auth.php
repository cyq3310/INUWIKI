<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AuthService;

class Auth extends BaseController
{
    /** 登录（公开接口，含限频锁定） */
    public function login()
    {
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');
        if ($username === '' || $password === '') {
            return resp_fail('请输入账号和密码');
        }

        try {
            $result = (new AuthService())->login($username, $password);
        } catch (\RuntimeException $e) {
            return resp_fail($e->getMessage(), $e->getCode() ?: 400);
        }

        // <img src> 无法带 Authorization 头，另下发 HttpOnly 受限票据 cookie。
        // 票据独立于 JWT，URL 中不含任何凭证，可安全长期写进正文 HTML。
        $userId = (int) ($result['user']['id'] ?? 0);
        $ticket = $userId > 0 ? (new AuthService())->makeAssetTicket($userId) : '';
        return resp_ok($result, '登录成功')->header([
            'Set-Cookie' => $this->assetCookie($ticket, max(1, strtotime(date('Y-m-d') . ' 23:59:59') - time())),
        ]);
    }

    /** 登出：同时清除资源票据 cookie */
    public function logout()
    {
        return resp_ok([], '已退出登录')->header([
            'Set-Cookie' => $this->assetCookie('', 0),
        ]);
    }

    /** 资源票据 cookie：HttpOnly，生产环境附加 Secure */
    private function assetCookie(string $value, int $maxAge): string
    {
        $parts = [
            'kb_asset=' . $value,
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
            'Max-Age=' . $maxAge,
        ];
        if (config('myconfig.deploy.env') === 'production') {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    /** 当前用户档案 */
    public function profile()
    {
        return resp_ok($this->request->userProfile);
    }

    /** 资源票据有效性探测：供前端在 <img>/<video> 加载失败时判断是否该回登录页 */
    public function assetTicket()
    {
        $userId = (new AuthService())->verifyAssetTicket(
            (string) $this->request->cookie('kb_asset', '')
        );
        return resp_ok(['valid' => $userId > 0]);
    }
}
