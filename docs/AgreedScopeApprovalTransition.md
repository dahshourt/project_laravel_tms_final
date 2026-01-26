# Agreed Scope Approval Transition Implementation

## Overview

This implementation handles the workflow logic for when any of the "Pending Agreed Scope Approval" statuses OR "Request Draft CR Doc" transition to "Pending Create Agreed Scope". When this transition occurs:

1. All other active "Pending Agreed Scope Approval" statuses for the same CR are deactivated (set to `active = 0`)
2. Any active "Request Draft CR Doc" status for the same CR is also deactivated (set to `active = 0`)
3. A new active record is created for "Pending Create Agreed Scope" with `active = 1`

## Implementation Details

### Files Modified

1. **`app/Services/ChangeRequest/ChangeRequestStatusService.php`**

### Key Changes

#### 1. Added Status ID Constants
```php
// Status IDs for agreed scope approval workflow
private static ?int $PENDING_CREATE_AGREED_SCOPE_STATUS_ID = null;
private static ?int $PENDING_AGREED_SCOPE_SA_STATUS_ID = null;
private static ?int $PENDING_AGREED_SCOPE_VENDOR_STATUS_ID = null;
private static ?int $PENDING_AGREED_SCOPE_BUSINESS_STATUS_ID = null;
private static ?int $REQUEST_DRAFT_CR_DOC_STATUS_ID = null;
```

#### 2. Initialize Status IDs in Constructor
```php
// Initialize agreed scope approval status IDs
self::$PENDING_CREATE_AGREED_SCOPE_STATUS_ID = $this->getStatusIdByName('Pending Create Agreed Scope');
self::$PENDING_AGREED_SCOPE_SA_STATUS_ID = $this->getStatusIdByName('Pending Agreed Scope Approval-SA');
self::$PENDING_AGREED_SCOPE_VENDOR_STATUS_ID = $this->getStatusIdByName('Pending Agreed Scope Approval-Vendor');
self::$PENDING_AGREED_SCOPE_BUSINESS_STATUS_ID = $this->getStatusIdByName('Pending Agreed Scope Approval-Business');
self::$REQUEST_DRAFT_CR_DOC_STATUS_ID = $this->getStatusIdByName('Request Draft CR Doc');
```

#### 3. Added Helper Method
```php
/**
 * Get status ID by status name
 */
private function getStatusIdByName(string $statusName): ?int
{
    $status = Status::where('status_name', $statusName)
        ->where('active', '1')
        ->first();
    
    return $status ? $status->id : null;
}
```

#### 4. Added Main Transition Handler
```php
/**
 * Handle agreed scope approval transition logic
 * When any approval status transitions to "Pending Create Agreed Scope",
 * deactivate other approval statuses and create new active record
 */
private function handleAgreedScopeApprovalTransition(int $crId, array $statusData): void
{
    $newStatusId = $statusData['new_status_id'] ?? null;
    
    // Check if transitioning TO "Pending Create Agreed Scope"
    if ($newStatusId !== self::$PENDING_CREATE_AGREED_SCOPE_STATUS_ID) {
        return;
    }

    // Define the approval status IDs that should be deactivated
    $approvalStatusIds = [
        self::$PENDING_AGREED_SCOPE_SA_STATUS_ID,
        self::$PENDING_AGREED_SCOPE_VENDOR_STATUS_ID,
        self::$PENDING_AGREED_SCOPE_BUSINESS_STATUS_ID,
        self::$REQUEST_DRAFT_CR_DOC_STATUS_ID,
    ];

    // Filter out null values
    $approvalStatusIds = array_filter($approvalStatusIds, function($id) {
        return $id !== null;
    });

    try {
        // Find and deactivate all active approval statuses for this CR
        $deactivatedCount = ChangeRequestStatus::where('cr_id', $crId)
            ->whereIn('new_status_id', $approvalStatusIds)
            ->active()
            ->update(['active' => self::INACTIVE_STATUS]);

        Log::info('Deactivated approval statuses and Request Draft CR Doc', [
            'cr_id' => $crId,
            'deactivated_count' => $deactivatedCount,
            'status_ids' => $approvalStatusIds
        ]);

        // Create a new active record for "Pending Create Agreed Scope"
        // ... (implementation details)
    } catch (\Exception $e) {
        Log::error('Error handling agreed scope approval transition', [
            'cr_id' => $crId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
```

#### 5. Integration Point
The transition handler is called in the main `updateChangeRequestStatus` method:

```php
// Process update - determineActiveStatus handles merge point logic
$this->processStatusUpdate($changeRequest, $statusData, $workflow, $userId, $request);

// Handle agreed scope approval transition logic
$this->handleAgreedScopeApprovalTransition($changeRequest->id, $statusData);

// Activate pending statuses if needed
$this->activatePendingMergeStatus($changeRequest->id, $statusData);
```

## How It Works

### Scenario Example

1. **Initial State**: A CR has multiple active approval statuses:
   - "Pending Agreed Scope Approval-SA" (active = 1)
   - "Pending Agreed Scope Approval-Vendor" (active = 1)
   - "Pending Agreed Scope Approval-Business" (active = 1)

2. **Transition Trigger**: User transitions from "Pending Agreed Scope Approval-SA" to "Pending Create Agreed Scope"

3. **System Actions**:
   - Detects transition to "Pending Create Agreed Scope"
   - Deactivates all other approval statuses:
     - "Pending Agreed Scope Approval-Vendor" → active = 0
     - "Pending Agreed Scope Approval-Business" → active = 0
   - Creates new active record:
     - "Pending Create Agreed Scope" → active = 1

4. **Final State**:
   - "Pending Agreed Scope Approval-SA" → active = 0 (completed)
   - "Pending Agreed Scope Approval-Vendor" → active = 0 (deactivated)
   - "Pending Agreed Scope Approval-Business" → active = 0 (deactivated)
   - "Pending Create Agreed Scope" → active = 1 (new active status)

## Benefits

1. **Automatic Cleanup**: No manual intervention needed to manage conflicting approval statuses
2. **Data Integrity**: Ensures only one approval workflow path is active at a time
3. **Audit Trail**: Maintains complete history of all status changes
4. **Error Handling**: Comprehensive logging for debugging and monitoring
5. **Performance**: Efficient database operations with minimal queries

## Testing

The implementation includes comprehensive logging at each step:

- Information logging for normal operations
- Warning logging for missing data
- Error logging for exceptions with full stack traces

## Edge Cases Handled

1. **Missing Status IDs**: Gracefully handles cases where status IDs are not found
2. **Database Errors**: Catches and logs exceptions without breaking the main workflow
3. **Missing User Data**: Provides fallback user ID when current user is not available
4. **Null Reference Data**: Handles cases where current status reference data is missing

## Future Enhancements

1. **Configuration**: Move status names to configuration files for easier maintenance
2. **Events**: Fire specific events for agreed scope approval transitions
3. **Notifications**: Add email notifications when approval statuses are deactivated
4. **Permissions**: Add role-based permissions for who can perform these transitions
