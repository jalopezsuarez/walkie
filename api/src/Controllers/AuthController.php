<?php
declare(strict_types=1);

namespace Walkie\Controllers;

use Walkie\Core\ApiException;
use Walkie\Core\Config;
use Walkie\Core\Database;
use Walkie\Core\Request;
use Walkie\Core\Response;
use Walkie\Core\Validator;
use Walkie\Models\UserRepo;
use Walkie\Security\Auth;
use Walkie\Security\Crypto;
use Walkie\Security\RateLimiter;
use Walkie\Services\Mailer;

final class AuthController
{
    /** POST /auth/request-code  { email } */
    public static function requestCode(Request $req): void
    {
        $email = Validator::email($req->input('email'));

        RateLimiter::enforce('code_per_ip', $req->ip);
        RateLimiter::enforce('code_per_email', $email);

        $userId = UserRepo::findOrCreate($email);
        $code = Crypto::numericCode(6);
        $ttl = (int) Config::get('ttl.login_code', 300);

        $pdo = Database::pdo();
        // Invalidate previous codes for this email, then insert the new one.
        $pdo->prepare('DELETE FROM login_codes WHERE email = :e')->execute([':e' => $email]);
        $pdo->prepare(
            'INSERT INTO login_codes (email, code_hash, attempts, expires_at, created_at)
             VALUES (:e, :h, 0, (UTC_TIMESTAMP() + INTERVAL :ttl SECOND), UTC_TIMESTAMP())'
        )->execute([':e' => $email, ':h' => Crypto::hash($code), ':ttl' => $ttl]);

        Mailer::sendLoginCode($email, $code);

        $payload = ['ok' => true, 'message' => 'Code sent if the address is valid.'];
        // Development convenience only.
        if ((bool) Config::get('app.debug', false)) {
            $payload['debug_code'] = $code;
        }
        Response::json($payload);
    }

    /** POST /auth/verify  { email, code } */
    public static function verify(Request $req): void
    {
        RateLimiter::enforce('verify_per_ip', $req->ip);

        $email = Validator::email($req->input('email'));
        $code  = Validator::code($req->input('code'));

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, code_hash, attempts, expires_at
               FROM login_codes WHERE email = :e ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':e' => $email]);
        $row = $stmt->fetch();

        if (!$row || strtotime($row['expires_at'] . ' UTC') < time()) {
            throw ApiException::badRequest('Code expired or not found', 'code_invalid');
        }

        $maxTries = (int) Config::get('limits.max_code_tries', 5);
        if ((int) $row['attempts'] >= $maxTries) {
            $pdo->prepare('DELETE FROM login_codes WHERE id = :id')->execute([':id' => $row['id']]);
            throw ApiException::tooMany('Too many attempts. Request a new code.', 60);
        }

        if (!Crypto::equals($row['code_hash'], Crypto::hash($code))) {
            $pdo->prepare('UPDATE login_codes SET attempts = attempts + 1 WHERE id = :id')
                ->execute([':id' => $row['id']]);
            throw ApiException::badRequest('Incorrect code', 'code_invalid');
        }

        // Success: single-use code.
        $pdo->prepare('DELETE FROM login_codes WHERE email = :e')->execute([':e' => $email]);
        $userId = UserRepo::findOrCreate($email);
        $token = Auth::createSession($userId, $req);

        $user = UserRepo::findByEmail($email);
        Response::json([
            'token' => $token,
            'user'  => [
                'id'           => (int) $user['id'],
                'email'        => $user['email'],
                'display_name' => $user['display_name'],
            ],
        ]);
    }

    /** POST /auth/logout */
    public static function logout(Request $req): void
    {
        Auth::requireUser($req);
        Auth::destroySession($req);
        Response::json(['ok' => true]);
    }
}
