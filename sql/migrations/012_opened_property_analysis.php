<?php
declare(strict_types=1);

return static function (App\Migrator $m): void {
    // Earlier bulk tasks have no opened_at, so workers leave them dormant until that home is opened.
    $m->addColumn('property_analyses', 'opened_at', 'DATETIME NULL');
    $m->addIndex('property_analyses', 'ix_analysis_opened', 'INDEX ix_analysis_opened (opened_at, status, next_attempt_at)');
};
