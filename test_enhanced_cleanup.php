<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Testing Enhanced Need Update Cleanup ===\n\n";

try {
    use App\Models\Change_request_statuse;

    // Check current state
    echo "Current state for CR 31351:\n";
    $beforeActiveCount = Change_request_statuse::where('cr_id', 31351)->where('active', '1')->count();
    echo "Active records: {$beforeActiveCount}\n\n";

    // Show current active statuses
    echo "Current active statuses:\n";
    $activeStatuses = Change_request_statuse::where('cr_id', 31351)
        ->where('active', '1')
        ->orderBy('created_at', 'desc')
        ->get();
    
    foreach ($activeStatuses as $status) {
        $statusName = $status->status ? $status->status->status_name : 'Unknown';
        echo "  - {$statusName} (ID: {$status->new_status_id}, Record ID: {$status->id}, Active: {$status->active})\n";
    }

    // Execute Need Update action
    echo "\nExecuting Need Update action...\n";
    $statusService = app(\App\Services\ChangeRequest\ChangeRequestStatusService::class);
    $result = $statusService->handleNeedUpdateAction(31351);
    
    echo "Result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n\n";

    // Check final state
    echo "Final state:\n";
    $afterActiveCount = Change_request_statuse::where('cr_id', 31351)->where('active', '1')->count();
    echo "Final active records: {$afterActiveCount}\n\n";

    // Show final active statuses
    echo "Final active statuses:\n";
    $finalActiveStatuses = Change_request_statuse::where('cr_id', 31351)
        ->where('active', '1')
        ->orderBy('created_at', 'desc')
        ->get();
    
    foreach ($finalActiveStatuses as $status) {
        $statusName = $status->status ? $status->status->status_name : 'Unknown';
        echo "  - {$statusName} (ID: {$status->new_status_id}, Record ID: {$status->id}, Active: {$status->active})\n";
    }
    
    if ($afterActiveCount === 1) {
        echo "\n✅ SUCCESS: Need Update action worked correctly!\n";
    } else {
        echo "\n❌ ISSUE: Expected 1 active status, found {$afterActiveCount}\n";
    }

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
