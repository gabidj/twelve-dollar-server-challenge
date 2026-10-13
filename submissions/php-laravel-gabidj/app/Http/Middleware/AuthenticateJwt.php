<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;

/** Verifies the HS256 bearer token on every request (nothing is cached) and exposes its user. */
final class AuthenticateJwt
{
    public function handle(Request $request, Closure $next)
    {
        $auth = $request->headers->get('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            throw new ApiException(401, 'missing bearer token');
        }
        $parts = explode('.', substr($auth, 7));
        if (count($parts) !== 3) {
            throw new ApiException(401, 'invalid or expired token');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode((string) self::b64url($h), true);
        $sig = self::b64url($s);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' || $sig === false
            || !hash_equals(hash_hmac('sha256', "$h.$p", config('feed.jwt_secret'), true), $sig)) {
            throw new ApiException(401, 'invalid or expired token');
        }
        $payload = json_decode((string) self::b64url($p), true);
        if (!is_array($payload)) {
            throw new ApiException(401, 'invalid or expired token');
        }
        $exp = $payload['exp'] ?? null;
        if ((!is_int($exp) && !is_float($exp)) || $exp <= time()) {
            throw new ApiException(401, 'invalid or expired token');
        }
        $sub = $payload['sub'] ?? null;
        $username = $payload['username'] ?? null;
        if (!is_string($sub) || !preg_match('/^[1-9][0-9]*$/', $sub) || !is_string($username)) {
            throw new ApiException(401, 'invalid token payload');
        }
        $request->attributes->set('user_id', (int) $sub);
        $request->attributes->set('username', $username);

        return $next($request);
    }

    /** Strict, unpadded base64url: anything outside the URL-safe alphabet is malformed. */
    private static function b64url(string $s): string|false
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $s)) {
            return false;
        }

        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
