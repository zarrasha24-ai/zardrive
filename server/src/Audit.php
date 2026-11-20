<?php
declare(strict_types=1);

namespace Zd;

final class Audit
{
    public static function log(?int $userId, string $action, string $detail = ''): void
    {
        Db::insert('audit', ['user_id' => $userId, 'action' => $action, 'detail' => $detail, 'created_at' => now()]);
    }
}
