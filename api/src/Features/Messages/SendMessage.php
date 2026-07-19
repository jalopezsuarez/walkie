<?php
declare(strict_types=1);

namespace Walkie\Features\Messages;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Config;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\Crypto;
use Walkie\Shared\Fcm;
use Walkie\Shared\RateLimiter;
use Walkie\Shared\Session;

/**
 * POST /links/{id}/messages
 * Body: { type: "text", text } or { type: "audio", audio(base64), mime?, duration_ms? }
 * Stores the message AES-256-GCM encrypted with the conversation key.
 */
final class SendMessage
{
    public static function handle(Request $req, array $params): void
    {
        $user = Session::requireUser($req);
        RateLimiter::enforce('message_per_user', (string) $user['id']);
        $link = Conversation::require($params, $user['id']);

        $type = $req->input('type');
        match ($type) {
            'text'  => self::text($req, $link, $user),
            'audio' => self::audio($req, $link, $user),
            default => throw ApiException::badRequest('type must be "text" or "audio"', 'invalid_type'),
        };
    }

    private static function text(Request $req, array $link, array $user): void
    {
        $userId = $user['id'];
        $text = $req->input('text');
        $maxLen = (int) Config::get('upload.max_text_len', 4000);
        if (!is_string($text) || trim($text) === '') {
            throw ApiException::badRequest('Empty message', 'empty_text');
        }
        if (mb_strlen($text) > $maxLen) {
            throw ApiException::badRequest("Message too long (max {$maxLen})", 'text_too_long');
        }

        $id = self::store($link, $userId, 't', $text, (int) Config::get('ttl.text_msg', 86400));
        Response::json(['id' => $id, 'type' => 'text'], 201);
        self::pushToRecipient($link, $user, '💬 Nuevo mensaje');
    }

    private static function audio(Request $req, array $link, array $user): void
    {
        $userId = $user['id'];
        $b64 = $req->input('audio');
        if (!is_string($b64) || $b64 === '') {
            throw ApiException::badRequest('Missing audio data', 'empty_audio');
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            throw ApiException::badRequest('Audio must be base64', 'invalid_audio');
        }
        if (strlen($bytes) > (int) Config::get('upload.max_audio_bytes', 2097152)) {
            throw ApiException::badRequest('Voice note too large', 'audio_too_large');
        }

        $mime = $req->input('mime');
        $mime = is_string($mime) && preg_match('#^audio/[a-z0-9.+-]{1,30}$#i', $mime) ? $mime : 'audio/webm';

        $duration = $req->input('duration_ms');
        $duration = is_int($duration) || (is_string($duration) && ctype_digit($duration))
            ? (int) $duration : null;

        $id = self::store(
            $link, $userId, 'a', $bytes,
            (int) Config::get('ttl.audio_msg', 3600), $mime, $duration
        );
        Response::json(['id' => $id, 'type' => 'audio'], 201);
        self::pushToRecipient($link, $user, '🎤 Nota de voz');
    }

    /**
     * Best-effort push to the other user's devices. Runs after the response is
     * flushed so it never slows down sending. Carries only the sender's name.
     */
    private static function pushToRecipient(array $link, array $sender, string $body): void
    {
        if (!Fcm::enabled()) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            $stmt = Database::pdo()->prepare('SELECT token FROM devices WHERE user_id = ?');
            $stmt->execute([$link['other_id']]);
            $title = ($sender['display_name'] ?? '') !== '' ? $sender['display_name'] : 'Walkie';
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $token) {
                Fcm::send((string) $token, $title, $body, ['link_id' => (string) $link['id']]);
            }
        } catch (\Throwable $e) {
            // best effort — never affect message delivery
        }
    }

    private static function store(
        array $link, int $senderId, string $type, string $plaintext,
        int $ttl, ?string $mime = null, ?int $durationMs = null
    ): int {
        $cipher = Crypto::encrypt($plaintext, Crypto::deriveKey($link['secret']));

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO messages
                (link_id, sender_id, type, body_cipher, mime, duration_ms, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), (UTC_TIMESTAMP() + INTERVAL ? SECOND))'
        );
        $stmt->bindValue(1, $link['id'], \PDO::PARAM_INT);
        $stmt->bindValue(2, $senderId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $type);
        $stmt->bindValue(4, $cipher, \PDO::PARAM_LOB);
        $stmt->bindValue(5, $mime, $mime === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(6, $durationMs, $durationMs === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(7, $ttl, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $pdo->lastInsertId();
    }
}
