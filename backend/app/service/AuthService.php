<?php
declare(strict_types=1);

namespace app\service;

use app\model\EipUser;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use think\facade\Cache;

/**
 * 认证服务：登录（含限频锁定）、JWT 签发与校验
 *
 * 与 INUEIP 的差异（有意为之）：
 * 1. 密码校验直查 EIP 的 inue_user（bcrypt），但不回写 last_login_at —— EIP 库为只读连接
 * 2. 令牌有效期为「当天有效」（exp = 当日 23:59:59），无滑动续期
 * 3. JWT 密钥与 EIP 相互独立，不共享
 */
class AuthService
{
    private const MAX_FAILS    = 5;
    private const LOCK_SECONDS = 900; // 连续失败5次锁定15分钟

    public function login(string $username, string $password): array
    {
        $failKey = 'login_fail:' . $username;
        $fails   = (int) Cache::get($failKey, 0);
        if ($fails >= self::MAX_FAILS) {
            throw new \RuntimeException('失败次数过多，账号已锁定15分钟', 423);
        }

        $user = EipUser::where('username', $username)->find();
        if (!$user || !password_verify($password, (string) $user->password_hash)) {
            Cache::set($failKey, $fails + 1, self::LOCK_SECONDS);
            throw new \RuntimeException('账号或密码错误', 422);
        }
        if ((int) $user->status !== 1) {
            throw new \RuntimeException('账号已被禁用，请联系管理员', 423);
        }

        Cache::delete($failKey);
        // 注意：不写 EIP 库（只读连接），last_login_at 仍由 EIP 自身维护

        return [
            'token' => $this->makeToken((int) $user->id),
            'user'  => (new RbacService())->buildProfile((int) $user->id),
        ];
    }

    /** JWT 密钥：以 myconfig.jwt 为准，留空时回落 .env */
    private function jwtSecret(): string
    {
        return (string) (config('myconfig.jwt.secret') ?: env('JWT_SECRET', ''));
    }

    /**
     * 令牌截止时刻：当日 23:59:59（当天有效，无滑动续期）
     */
    private function tokenExpire(): int
    {
        return strtotime(date('Y-m-d') . ' 23:59:59');
    }

    public function makeToken(int $userId): string
    {
        $now = time();
        return JWT::encode([
            'uid' => $userId,
            'iat' => $now,
            'exp' => $this->tokenExpire(),
        ], $this->jwtSecret(), 'HS256');
    }

    public function verifyToken(string $token): int
    {
        $payload = JWT::decode($token, new Key($this->jwtSecret(), 'HS256'));
        return (int) ($payload->uid ?? 0);
    }

    /**
     * 图片资源票据密钥：由 JWT 密钥派生但相互独立。
     * 票据只能访问资源接口，即使泄露也无法调用任何业务 API。
     */
    private function assetSecret(): string
    {
        return hash('sha256', 'kb-asset|' . $this->jwtSecret(), true);
    }

    /** 签发资源票据（与 JWT 同为「当天有效」，随登录写入 HttpOnly cookie） */
    public function makeAssetTicket(int $userId): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode([
            'uid' => $userId,
            'exp' => $this->tokenExpire(),
        ])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $this->assetSecret(), true)), '+/', '-_'), '=');
        return $body . '.' . $sig;
    }

    /** 校验资源票据，失败返回 0 */
    public function verifyAssetTicket(string $ticket): int
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return 0;
        }
        [$body, $sig] = $parts;
        $expect = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $this->assetSecret(), true)), '+/', '-_'), '=');
        if (!hash_equals($expect, $sig)) {
            return 0;
        }
        $payload = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) {
            return 0;
        }
        $uid = (int) ($payload['uid'] ?? 0);
        return $uid > 0 ? $uid : 0;
    }
}
