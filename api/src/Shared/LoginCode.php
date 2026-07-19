<?php
declare(strict_types=1);

namespace Walkie\Shared;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Config;
use Walkie\Kernel\Database;

/**
 * Verifies an emailed login code and resolves the user, for the OAuth 2.0
 * token endpoint's email-code grant.
 */
final class LoginCode
{
    /**
     * Verify a single-use login code and return the user id, creating the
     * account on first login. Throws ApiException on any failure.
     */
    public static function consume(string $email, string $code): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, code_hash, attempts, expires_at
               FROM login_codes WHERE email = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || strtotime($row['expires_at'] . ' UTC') < time()) {
            throw ApiException::badRequest('Code expired or not found', 'code_invalid');
        }

        $maxTries = (int) Config::get('limits.max_code_tries', 5);
        if ((int) $row['attempts'] >= $maxTries) {
            $pdo->prepare('DELETE FROM login_codes WHERE id = ?')->execute([$row['id']]);
            throw ApiException::tooMany('Too many attempts. Request a new code.', 60);
        }

        if (!Crypto::equals($row['code_hash'], Crypto::hash($code))) {
            $pdo->prepare('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?')
                ->execute([$row['id']]);
            throw ApiException::badRequest('Incorrect code', 'code_invalid');
        }

        // Success — the code is single use.
        $pdo->prepare('DELETE FROM login_codes WHERE email = ?')->execute([$email]);
        return UserAccount::findOrCreate($email);
    }
}
