<?php
declare(strict_types=1);

/* Download one database backup. Admin only (see Kernel). */

$path = App\Backup::path((string) ($params['file'] ?? ''));
if ($path === null) {
    App\Kernel::plain(404, 'Not found', 'That backup doesn’t exist any more.');
}
App\Services\Activity::log('user', null, 'Downloaded backup ' . basename($path));
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
