<?php
declare(strict_types=1);

use App\Auth;
use App\Services\Presence;

if (is_post()) {
    $u = Auth::user();
    Presence::touch((int)$u['id'],(int)$u['session_epoch']);
    json_out(['ok'=>true]);
}
json_out(['users'=>array_values(Presence::statuses())]);
