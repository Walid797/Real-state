<?php
declare(strict_types=1);

use App\Db;
use App\Services\Alerts;

$id = (int) $params['id'];
$alert = Db::one('SELECT * FROM alerts WHERE id = ?', [$id]);
if (!$alert || ((int) $alert['user_id'] !== App\Auth::userId() && !App\Auth::can('admin'))) {
    redirect('/alerts');
}

switch (input('do')) {
    case 'toggle':
        Db::run('UPDATE alerts SET enabled = 1 - enabled WHERE id = ?', [$id]);
        flash($alert['enabled'] ? '“' . $alert['name'] . '” is paused.' : '“' . $alert['name'] . '” is on again.');
        break;

    case 'run':
        @set_time_limit(120);
        $r = Alerts::runOne($alert, 'manual');
        flash($alert['name'] . ': ' . $r['note']);
        break;

    case 'delete':
        Db::run('DELETE FROM alerts WHERE id = ?', [$id]);
        flash('Alert deleted.');
        break;
}

redirect('/alerts');
