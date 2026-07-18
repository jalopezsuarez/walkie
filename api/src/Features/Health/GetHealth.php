<?php
declare(strict_types=1);

namespace Walkie\Features\Health;

use Walkie\Kernel\Request;
use Walkie\Kernel\Response;

/**
 * GET /health — liveness probe.
 */
final class GetHealth
{
    public static function handle(Request $req): void
    {
        Response::json(['status' => 'ok', 'service' => 'walkie']);
    }
}
