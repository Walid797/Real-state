<?php
declare(strict_types=1);

/* /reset?token=… — same form as an invitation, for a password reset link. */
$kind = 'reset';
require __DIR__ . '/invite.php';
