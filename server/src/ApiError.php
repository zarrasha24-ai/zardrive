<?php
declare(strict_types=1);

namespace Zd;

/** An error the user should see, with an HTTP status. */
final class ApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400) { parent::__construct($message); }
}
