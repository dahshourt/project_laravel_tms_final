<?php

echo "Checking recent logs for debugging...\n";

$logFile = storage_path('logs/laravel.log');
if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    $recentLogs = substr($logs, -5000); // Get last 5000 characters
    
    // Filter for our debugging logs
    $lines = explode("\n", $recentLogs);
    $relevantLogs = [];
    
    foreach ($lines as $line) {
        if (strpos($line, 'updateChangeRequestStatus') !== false || 
            strpos($line, 'handleAgreedScopeApprovalTransition') !== false ||
            strpos($line, 'Handling agreed scope') !== false) {
            $relevantLogs[] = $line;
        }
    }
    
    if (!empty($relevantLogs)) {
        echo "Recent relevant log entries:\n";
        echo "============================\n";
        foreach (array_slice($relevantLogs, -20) as $log) {
            echo $log . "\n";
        }
    } else {
        echo "No relevant log entries found.\n";
        echo "This means either:\n";
        echo "1. The updateChangeRequestStatus method is not being called\n";
        echo "2. The logs haven't been written yet\n";
        echo "3. A different code path is being used\n";
    }
} else {
    echo "Log file not found.\n";
}
