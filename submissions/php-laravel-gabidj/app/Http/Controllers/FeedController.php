<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final class FeedController
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    // The whitespace JavaScript's String.prototype.trim() strips, which the reference implementations use.
    private const TRIM_RE = '/^[\t\n\x0B\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+'
        . '|[\t\n\x0B\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u';

    private const POST_SELECT = 'SELECT p.id, p.body, p.created_at, u.username AS author,
            (SELECT count(*) FROM likes l WHERE l.post_id = p.id) AS like_count
       FROM posts p JOIN users u ON u.id = p.user_id';

    public function health(): JsonResponse
    {
        try {
            DB::selectOne('SELECT 1');
        } catch (Throwable $e) {
            return self::json(['status' => 'degraded', 'db' => 'unreachable', 'error' => $e->getMessage()], 503);
        }

        return self::json(['status' => 'ok', 'db' => 'ok', 'uptime_s' => time() - $GLOBALS['feed_started_at']]);
    }

    public function feed(): JsonResponse
    {
        return self::json(['posts' => DB::select(self::POST_SELECT . ' ORDER BY p.created_at DESC, p.id DESC LIMIT 20')]);
    }

    public function show(string $id): JsonResponse
    {
        $post = DB::selectOne(self::POST_SELECT . ' WHERE p.id = ?', [self::postId($id)]);
        if ($post === null) {
            throw new ApiException(404, 'post not found');
        }

        return self::json(['post' => $post]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'malformed JSON body');
        }
        $body = is_array($data) && is_string($data['body'] ?? null) ? preg_replace(self::TRIM_RE, '', $data['body']) : '';
        if ($body === '') {
            throw new ApiException(400, 'body is required');
        }
        if (mb_strlen($body, 'UTF-8') > 500) {
            throw new ApiException(400, 'body must be at most 500 characters');
        }
        // select() fetches the whole result set, so the autocommit has happened before we reply.
        [$row] = DB::select('INSERT INTO posts (user_id, body) VALUES (?, ?) RETURNING id, created_at',
            [$request->attributes->get('user_id'), $body]);

        return self::json(['post' => [
            'id' => $row->id,
            'body' => $body,
            'created_at' => $row->created_at,
            'author' => $request->attributes->get('username'),
            'like_count' => 0,
        ]], 201);
    }

    public function like(Request $request, string $id): JsonResponse
    {
        $postId = self::postId($id);
        // One statement: insert unless the post is missing or the like exists.
        $inserted = DB::affectingStatement(
            'INSERT INTO likes (user_id, post_id) SELECT ?, ? WHERE EXISTS (SELECT 1 FROM posts WHERE id = ?)
             ON CONFLICT (user_id, post_id) DO NOTHING',
            [$request->attributes->get('user_id'), $postId, $postId],
        );
        if ($inserted === 1) {
            return self::json(['liked' => true, 'already_liked' => false, 'post_id' => $postId], 201);
        }
        if (DB::selectOne('SELECT 1 FROM posts WHERE id = ?', [$postId]) === null) {
            throw new ApiException(404, 'post not found');
        }

        return self::json(['liked' => true, 'already_liked' => true, 'post_id' => $postId]);
    }

    private static function postId(string $raw): int
    {
        if (!ctype_digit($raw) || ($id = (int) $raw) <= 0) {
            throw new ApiException(400, 'invalid post id');
        }

        return $id;
    }

    private static function json(array $data, int $status = 200): JsonResponse
    {
        // Throwing makes an encoding failure (e.g. a non-UTF-8 row) a 500 instead of a 200 with broken JSON.
        return new JsonResponse($data, $status, [], self::JSON_FLAGS);
    }
}
