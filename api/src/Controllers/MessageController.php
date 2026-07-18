<?php
declare(strict_types=1);

namespace Walkie\Controllers;

use Walkie\Core\ApiException;
use Walkie\Core\Config;
use Walkie\Core\Request;
use Walkie\Core\Response;
use Walkie\Core\Validator;
use Walkie\Models\LinkRepo;
use Walkie\Models\MessageRepo;
use Walkie\Security\Auth;
use Walkie\Security\RateLimiter;

final class MessageController
{
    /**
     * GET /links/{id}/messages?after=N
     * Returns new messages and marks the ones addressed to the caller as read.
     */
    public static function index(Request $req, array $params): void
    {
        $user = Auth::requireUser($req);
        $link = self::requireLink($req, $params, $user['id']);

        $after = 0;
        if (isset($req->query['after']) && ctype_digit((string) $req->query['after'])) {
            $after = (int) $req->query['after'];
        }

        $messages = MessageRepo::fetch($link['id'], $link['secret'], $after, $user['id']);
        MessageRepo::markRead($link['id'], $user['id']);

        Response::json([
            'contact'  => ['user_id' => $link['other_id'], 'display_name' => $link['other_name']],
            'messages' => $messages,
        ]);
    }

    /**
     * POST /links/{id}/messages  { type: "text"|"audio", text?, audio(base64)?, mime?, duration_ms? }
     */
    public static function store(Request $req, array $params): void
    {
        $user = Auth::requireUser($req);
        RateLimiter::enforce('message_per_user', (string) $user['id']);
        $link = self::requireLink($req, $params, $user['id']);

        $type = $req->input('type');
        if ($type === 'text') {
            self::storeText($req, $link, $user['id']);
        } elseif ($type === 'audio') {
            self::storeAudio($req, $link, $user['id']);
        } else {
            throw ApiException::badRequest('type must be "text" or "audio"', 'invalid_type');
        }
    }

    private static function storeText(Request $req, array $link, int $userId): void
    {
        $text = $req->input('text');
        $maxLen = (int) Config::get('upload.max_text_len', 4000);
        if (!is_string($text) || trim($text) === '') {
            throw ApiException::badRequest('Empty message', 'empty_text');
        }
        if (mb_strlen($text) > $maxLen) {
            throw ApiException::badRequest("Message too long (max {$maxLen})", 'text_too_long');
        }

        $ttl = (int) Config::get('ttl.text_msg', 86400);
        $id = MessageRepo::create($link['id'], $userId, 't', $text, $link['secret'], $ttl);
        Response::json(['id' => $id, 'type' => 'text'], 201);
    }

    private static function storeAudio(Request $req, array $link, int $userId): void
    {
        $b64 = $req->input('audio');
        if (!is_string($b64) || $b64 === '') {
            throw ApiException::badRequest('Missing audio data', 'empty_audio');
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            throw ApiException::badRequest('Audio must be base64', 'invalid_audio');
        }
        $maxBytes = (int) Config::get('upload.max_audio_bytes', 2097152);
        if (strlen($bytes) > $maxBytes) {
            throw ApiException::badRequest('Voice note too large', 'audio_too_large');
        }

        $mime = $req->input('mime');
        $mime = is_string($mime) && preg_match('#^audio/[a-z0-9.+-]{1,30}$#i', $mime) ? $mime : 'audio/webm';

        $duration = $req->input('duration_ms');
        $duration = is_int($duration) || (is_string($duration) && ctype_digit($duration))
            ? (int) $duration : null;

        $ttl = (int) Config::get('ttl.audio_msg', 3600);
        $id = MessageRepo::create($link['id'], $userId, 'a', $bytes, $link['secret'], $ttl, $mime, $duration);
        Response::json(['id' => $id, 'type' => 'audio'], 201);
    }

    /** DELETE /links/{id}/messages/{msgId} */
    public static function destroy(Request $req, array $params): void
    {
        $user = Auth::requireUser($req);
        $link = self::requireLink($req, $params, $user['id']);
        $msgId = Validator::positiveInt($params['msgId'] ?? null, 'message id');

        if (!MessageRepo::deleteOwn($msgId, $link['id'], $user['id'])) {
            throw ApiException::notFound('Message not found or not yours');
        }
        Response::noContent();
    }

    /** @return array{id:int, secret:string, other_id:int, other_name:string} */
    private static function requireLink(Request $req, array $params, int $userId): array
    {
        $linkId = Validator::positiveInt($params['id'] ?? null, 'link id');
        $link = LinkRepo::getForUser($linkId, $userId);
        if (!$link) {
            throw ApiException::notFound('Conversation not found');
        }
        return $link;
    }
}
