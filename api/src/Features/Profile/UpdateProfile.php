<?php
declare(strict_types=1);

namespace Walkie\Features\Profile;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Kernel\Validator;
use Walkie\Shared\Session;

/**
 * PATCH /me  { display_name?, email? }
 * Updates the user's display name and/or email.
 */
final class UpdateProfile
{
    public static function handle(Request $req): void
    {
        $user = Session::requireUser($req);
        $body = $req->json();
        $pdo = Database::pdo();

        $displayName = null;
        $email = null;

        if (array_key_exists('display_name', $body)) {
            $displayName = Validator::displayName($body['display_name']);
        }
        if (array_key_exists('email', $body)) {
            $email = Validator::email($body['email']);
            $taken = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $taken->execute([$email, $user['id']]);
            if ($taken->fetchColumn()) {
                throw ApiException::badRequest('Email already in use', 'email_taken');
            }
        }
        if ($displayName === null && $email === null) {
            throw ApiException::badRequest('Nothing to update', 'no_changes');
        }

        $sets = ['updated_at = UTC_TIMESTAMP()'];
        $params = [];
        if ($displayName !== null) { $sets[] = 'display_name = ?'; $params[] = $displayName; }
        if ($email !== null)       { $sets[] = 'email = ?';        $params[] = $email; }
        $params[] = $user['id'];
        $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        Response::json([
            'user' => [
                'id'           => $user['id'],
                'email'        => $email ?? $user['email'],
                'display_name' => $displayName ?? $user['display_name'],
            ],
        ]);
    }
}
