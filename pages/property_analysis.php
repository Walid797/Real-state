<?php
declare(strict_types=1);

use App\Auth;
use App\PropertyRepo;
use App\Services\PropertyAnalysis;

$id = (int) ($params['id'] ?? 0);
if (!$id && is_post()) {
    $body = json_decode((string) file_get_contents('php://input', false, null, 0, 4000), true);
    $id = (int) ($body['property_id'] ?? $_POST['property_id'] ?? 0);
}
$p = PropertyRepo::find($id);
if (!$p) {
    json_out(['error' => 'Property not found'], 404);
}
if (is_post()) {
    $refresh = !empty($params['refresh']);
    if ($refresh) {
        Auth::require('edit');
    }
    PropertyAnalysis::enqueue($id, $refresh);
    session_write_close();
    @set_time_limit(150);
    ignore_user_abort(true);
    PropertyAnalysis::run(110, $id);
}
$report = PropertyAnalysis::publicReport($id);
if (!empty($params['status'])) {
    ob_start();
    include APP_ROOT . '/pages/partials/intelligence.php';
    $html = ob_get_clean();
    json_out([
        'status' => $report['status'] ?? 'pending', 'configured' => App\Providers\OpenRouter::enabled(),
        'updated_at' => $report['updated_at'] ?? null, 'next_attempt_at' => $report['next_attempt_at'] ?? null, 'html' => $html,
    ]);
}
json_out(['report' => $report], is_post() ? 202 : 200);
