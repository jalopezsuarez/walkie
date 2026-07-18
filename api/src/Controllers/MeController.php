<?php
declare(strict_types=1);

namespace Walkie\Controllers;

use Walkie\Core\ApiException;
use Walkie\Core\Request;
use Walkie\Core\Response;
use Walkie\Core\Validator;
use Walkie\Models\UserRepo;
use Walkie\Security\Auth;

final class MeController
{
    /** GET /me */
    public static function show(Request $req): void
    {
        $user = Auth::requireUser($req);
        Response::json(['user' => $user]);
    }

    /** PATCH /me  { display_name?, email? } */
    public static function update(Request $req): void
    {
        $user = Auth::requireUser($req);
        $body = $req->json();

        $displayName = null;
        $email = null;

        if (array_key_exists('display_name', $body)) {
            $displayName = Validator::displayName($body['display_name']);
        }
        if (array_key_exists('email', $body)) {
            $email = Validator::email($body['email']);
            if (UserRepo::emailExists($email, $user['id'])) {
                throw ApiException::badRequest('Email already in use', 'email_taken');
            }
        }
        if ($displayName === null && $email === null) {
            throw ApiException::badRequest('Nothing to update', 'no_changes');
        }

        UserRepo::update($user['id'], $displayName, $email);

        Response::json([
            'user' => [
                'id'           => $user['id'],
                'email'        => $email ?? $user['email'],
                'display_name' => $displayName ?? $user['display_name'],
            ],
        ]);
    }
}
