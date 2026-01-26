<?php

namespace App\Services\ChangeRequest;

use App\Events\ChangeRequestStatusUpdated;
use App\Events\CrDeliveredEvent;
use App\Http\Controllers\Mail\MailController;
use App\Http\Repository\ChangeRequest\ChangeRequestStatusRepository;
use App\Models\Change_request as ChangeRequest;
use App\Models\Change_request_statuse as ChangeRequestStatus;
use App\Models\Group;
use App\Models\GroupStatuses;
use App\Models\NewWorkFlow;
use App\Models\NewWorkFlowStatuses;
use App\Models\Status;
use App\Models\TechnicalCr;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ChangeRequestStatusService
{
    private const TECHNICAL_REVIEW_STATUS = 0;

    private const WORKFLOW_NORMAL = 1;

    private const ACTIVE_STATUS = '1';

    private const INACTIVE_STATUS = '0';

    private const COMPLETED_STATUS = '2';

    public static array $ACTIVE_STATUS_ARRAY = [self::ACTIVE_STATUS, 1];

    public static array $INACTIVE_STATUS_ARRAY = [self::INACTIVE_STATUS, 0];

    public static array $COMPLETED_STATUS_ARRAY = [self::COMPLETED_STATUS, 2];

    // flag to determine if the workflow is active or not to send email to the dev team.
    private $active_flag = '0';

    // Status IDs for dependency checking
    private static ?int $PENDING_CAB_STATUS_ID = null;

    private static ?int $DELIVERED_STATUS_ID = null;

    private static ?int $PENDING_DESIGN_STATUS_ID = null;
    private static ?int $REJECTED_STATUS_ID = null;
    
    // Status IDs for agreed scope approval workflow
    private static ?int $PENDING_CREATE_AGREED_SCOPE_STATUS_ID = null;
    private static ?int $PENDING_AGREED_SCOPE_SA_STATUS_ID = null;
    private static ?int $PENDING_AGREED_SCOPE_VENDOR_STATUS_ID = null;
    private static ?int $PENDING_AGREED_SCOPE_BUSINESS_STATUS_ID = null;
    private static ?int $REQUEST_DRAFT_CR_DOC_STATUS_ID = null;
    // private const PENDING_CAB_STATUS_ID = 38;
    // private const DELIVERED_STATUS_ID = 27;

    private $statusRepository;

    private $mailController;

    private ?CrDependencyService $dependencyService = null;

    public function __construct()
    {
        self::$PENDING_CAB_STATUS_ID = \App\Services\StatusConfigService::getStatusId('pending_cab');
        self::$DELIVERED_STATUS_ID = \App\Services\StatusConfigService::getStatusId('Delivered');
        self::$PENDING_DESIGN_STATUS_ID = \App\Services\StatusConfigService::getStatusId('pending_design');
        self::$REJECTED_STATUS_ID = \App\Services\StatusConfigService::getStatusId('Reject');
        
        // Initialize agreed scope approval status IDs
        self::$PENDING_CREATE_AGREED_SCOPE_STATUS_ID = $this->getStatusIdByName('Pending Create Agreed Scope');
        self::$PENDING_AGREED_SCOPE_SA_STATUS_ID = $this->getStatusIdByName('Pending Agreed Scope Approval-SA');
        self::$PENDING_AGREED_SCOPE_VENDOR_STATUS_ID = $this->getStatusIdByName('Pending Agreed Scope Approval-Vendor');
        self::$PENDING_AGREED_SCOPE_BUSINESS_STATUS_ID = $this->getStatusIdByName('Pending Agreed Scope Approval-Business');
        self::$REQUEST_DRAFT_CR_DOC_STATUS_ID = $this->getStatusIdByName('Request Draft CR Doc');
        $this->statusRepository = new ChangeRequestStatusRepository();
        $this->mailController = new MailController();
    }
    /**
     * Check if both workflows have reached the merge point status
     * 
     * @param int $crId
     * @param string $mergeStatusName The merge point status name (e.g., "Pending Update Agreed Requirements")
     * @return bool True if both workflows have reached this status
     */
    private function haveBothWorkflowsReachedMergePoint(int $crId, string $mergeStatusName): bool
    {
        // Find the merge status ID
        $mergeStatus = Status::where('name', $mergeStatusName)->first();

        if (!$mergeStatus) {
            Log::error('Merge status not found', [
                'status_name' => $mergeStatusName
            ]);
            return false;
        }

        // Get all status records for this CR with the merge status
        $mergeStatusRecords = ChangeRequestStatus::where('cr_id', $crId)
            ->where('new_status_id', $mergeStatus->id)
            ->get();

        if ($mergeStatusRecords->isEmpty()) {
            Log::info('No merge status records found yet', [
                'cr_id' => $crId,
                'merge_status' => $mergeStatusName
            ]);
            return false;
        }

        // Count how many unique old_status_ids have reached the merge point
        // We need at least 2: one from Workflow A and one from Workflow B
        $uniqueSourceStatuses = $mergeStatusRecords->pluck('old_status_id')->unique();

        Log::info('Checking merge point status', [
            'cr_id' => $crId,
            'merge_status' => $mergeStatusName,
            'total_records' => $mergeStatusRecords->count(),
            'unique_sources' => $uniqueSourceStatuses->count(),
            'source_status_ids' => $uniqueSourceStatuses->toArray()
        ]);

        // Both workflows have reached if we have records from 2+ different source statuses
        $bothReached = $uniqueSourceStatuses->count() >= 2;

        if (!$bothReached) {
            Log::warning('Merge point not ready - both workflows have not reached it', [
                'cr_id' => $crId,
                'reached_count' => $uniqueSourceStatuses->count(),
                'required_count' => 2
            ]);
        }

        return $bothReached;
    }
    /**
     * Check if BOTH workflows have reached merge point
     * Works by counting how many different paths led to the merge status
     */
    /**
     * Check if both workflows reached merge point
     * Dynamically determines workflow paths
     */
    private function areBothWorkflowsCompleteById(int $crId, int $mergeStatusId): bool
    {
        Log::info('Checking both workflows completion', [
            'cr_id' => $crId,
            'merge_status_id' => $mergeStatusId
        ]);

        // Get all records where new_status_id = merge point (250)
        $mergeRecords = ChangeRequestStatus::where('cr_id', $crId)
            ->where('new_status_id', $mergeStatusId)
            ->get();

        if ($mergeRecords->isEmpty()) {
            Log::info('No workflows have reached merge point yet', [
                'cr_id' => $crId
            ]);
            return false;
        }

        // Count unique old_status_id values (different workflow paths)
        $uniquePaths = $mergeRecords->pluck('old_status_id')->unique();
        $pathCount = $uniquePaths->count();

        Log::info('Workflow paths analysis', [
            'cr_id' => $crId,
            'total_merge_records' => $mergeRecords->count(),
            'unique_workflow_paths' => $pathCount,
            'path_source_ids' => $uniquePaths->toArray(),
            'both_complete' => $pathCount >= 2
        ]);

        // Need at least 2 different paths (Workflow A + Workflow B)
        return $pathCount >= 2;
    }
    private function activatePendingMergeStatus(int $crId, array $statusData): void
    {
        $mergePointStatusId = 250;

        // Only if we just reached the merge point
        if ($statusData['new_status_id'] == $mergePointStatusId) {

            if ($this->areBothWorkflowsCompleteById($crId, $mergePointStatusId)) {

                Log::info('Both workflows complete - activating pending statuses', [
                    'cr_id' => $crId
                ]);

                // Find records with active=0 from merge point
                $pendingStatuses = ChangeRequestStatus::where('cr_id', $crId)
                    ->where('old_status_id', $mergePointStatusId)
                    ->notActive()
                    ->get();

                foreach ($pendingStatuses as $status) {
                    $status->update(['active' => self::ACTIVE_STATUS]);

                    Log::info('Updated pending status to active=1', [
                        'cr_id' => $crId,
                        'status_id' => $status->id
                    ]);
                }
            }
        }
    }

    /**
     * Check if both workflows have reached merge point by checking specific workflow statuses
     * More accurate version that checks specific workflow completion
     */
    // private function areBothWorkflowsComplete(int $crId): bool
// {
//     $mergeStatusName = 'Pending Update Agreed Requirements';
//     $mergeStatus = Status::where('status_name', $mergeStatusName)->first();

    //     if (!$mergeStatus) {
//         return false;
//     }

    //     // Define what we're looking for:
//     // Workflow A source: "Request Draft CR Doc" or its next statuses
//     // Workflow B source: One of the three approval statuses or their next statuses

    //     $workflowAStatuses = [
//         'Request Draft CR Doc',
//         'Pending Update Draft CR Doc',
//         // Add other Workflow A intermediate statuses here
//     ];

    //     $workflowBStatuses = [
//         'Pending Agreed Scope Approval-SA',
//         'Pending Agreed Scope Approval-Vendor',
//         'Pending Agreed Scope Approval-Business',
//         // Add other Workflow B intermediate statuses here
//     ];

    //     // Check if Workflow A has reached the merge point
//     $workflowAStatusIds = Status::whereIn('status_name', $workflowAStatuses)->pluck('id');
//     $workflowAReached = ChangeRequestStatus::where('cr_id', $crId)
//         ->whereIn('old_status_id', $workflowAStatusIds)
//         ->where('new_status_id', $mergeStatus->id)
//         ->exists();

    //     // Check if Workflow B has reached the merge point
//     $workflowBStatusIds = Status::whereIn('status_name', $workflowBStatuses)->pluck('id');
//     $workflowBReached = ChangeRequestStatus::where('cr_id', $crId)
//         ->whereIn('old_status_id', $workflowBStatusIds)
//         ->where('new_status_id', $mergeStatus->id)
//         ->exists();

    //     Log::info('Checking both workflows completion', [
//         'cr_id' => $crId,
//         'workflow_a_reached' => $workflowAReached,
//         'workflow_b_reached' => $workflowBReached,
//         'both_complete' => $workflowAReached && $workflowBReached
//     ]);

    //     return $workflowAReached && $workflowBReached;
// }


    private function requiresMergePointCheck(int $fromStatusId, int $toStatusId): bool
    {
        // Check if there's a workflow with same_time = 1
        $workflowStatus = \App\Models\NewWorkflowStatus::where('from_status_id', $fromStatusId)
            ->where('to_status_id', $toStatusId)
            ->first();

        if (!$workflowStatus || !$workflowStatus->workflow) {
            return false;
        }

        // If same_time = 1, this transition requires merge point check
        return $workflowStatus->workflow->same_time == 1;
    }
    // /public function updateChangeRequestStatus(int $changeRequestId, $request): bool
// {
//     try {
//         DB::beginTransaction();

    //         $statusData = $this->extractStatusData($request);

    //         Log::info('Status transition attempt', [
//             'cr_id' => $changeRequestId,
//             'old_status_id' => $statusData['old_status_id'],
//             'new_status_id' => $statusData['new_status_id']
//         ]);

    //         $workflow = $this->getWorkflow($statusData);
//         $changeRequest = $this->getChangeRequest($changeRequestId);
//         $userId = $this->getUserId($changeRequest, $request);

    //         // Get status objects
//         $fromStatus = Status::find($statusData['old_status_id']);
//         $toStatus = Status::find($statusData['new_status_id']);

    //         // Validate destination status exists


    //         // ════════════════════════════════════════════════════════════
//         // MERGE POINT CHECK
//         // ════════════════════════════════════════════════════════════

    //        $mergePointStatusId = 250;
//         $isMergePointTransition = ($statusData['old_status_id'] == $mergePointStatusId);
//         $bothWorkflowsComplete = true;
//         // Check if transitioning FROM the merge point
//   if ($isMergePointTransition) {
//             $bothWorkflowsComplete = $this->areBothWorkflowsCompleteById(
//                 $changeRequestId, 
//                 $mergePointStatusId
//             );

    //             Log::info('Merge point check', [
//                 'cr_id' => $changeRequestId,
//                 'both_complete' => $bothWorkflowsComplete,
//                 'will_insert_with_active' => $bothWorkflowsComplete ? '1' : '0'
//             ]);
//         }


    //         // ════════════════════════════════════════════════════════════
//         // END MERGE POINT CHECK
//         // ════════════════════════════════════════════════════════════

    //         $this->processStatusUpdate($changeRequest, $statusData, $workflow, $userId, $request);
//         $this->activatePendingMergeStatus($changeRequest->id, $statusData);

    //         DB::commit();

    //         Log::info('========== STATUS UPDATE COMMITTED ==========', [
//             'cr_id' => $changeRequestId,
//             'was_merge_point' => $isMergePoint,
//             'both_complete' => $bothComplete
//         ]);

    //         // ════════════════════════════════════════════════════════════
//         // AFTER INSERT is committed, optionally show message
//         // ════════════════════════════════════════════════════════════

    //         if ($isMergePoint && !$bothComplete) {

    //             Log::warning('Record inserted with active=0 - showing user message', [
//                 'cr_id' => $changeRequestId
//             ]);

    //             // Status already inserted with active=0
//             // Show informational message to user
//             throw new \Exception(
//                 'Status updated successfully. However, this status is pending and will become active when both Workflow A and Workflow B complete and reach "Pending Update Agreed Requirements".'
//             );
//         }

    //         // Fire event if no message
//         $this->checkAndFireDeliveredEvent($changeRequest, $statusData);

    //         return true;

    //     } catch (Exception $e) {

    //         // Check if this is our informational message
//         $isInfoMessage = strpos($e->getMessage(), 'Status updated successfully') !== false;

    //         if ($isInfoMessage) {
//             // Record already committed, just showing message
//             Log::info('User message displayed (record already saved with active=0)', [
//                 'cr_id' => $changeRequestId
//             ]);
//             throw $e;
//         }

    //         // Real error - rollback
//         DB::rollback();

    //         Log::error('Error updating change request status', [
//             'change_request_id' => $changeRequestId,
//             'error' => $e->getMessage()
//         ]);

    //         throw $e;
//     }
// }

    public function updateChangeRequestStatus(int $changeRequestId, $request): bool
    {
        Log::info('updateChangeRequestStatus called', [
            'change_request_id' => $changeRequestId,
            'request_data' => $request->all()
        ]);
        
        try {
            DB::beginTransaction();

            $statusData = $this->extractStatusData($request);
            $workflow = $this->getWorkflow($statusData);
            $changeRequest = $this->getChangeRequest($changeRequestId);
            $userId = $this->getUserId($changeRequest, $request);

            // Process update - determineActiveStatus handles merge point logic
            $this->processStatusUpdate($changeRequest, $statusData, $workflow, $userId, $request);
            
            // Handle agreed scope approval transition logic
            Log::info('About to call handleAgreedScopeApprovalTransition', [
                'cr_id' => $changeRequest->id,
                'status_data' => $statusData,
                'new_status_id' => $statusData['new_status_id'] ?? 'null'
            ]);
            $this->handleAgreedScopeApprovalTransition($changeRequest->id, $statusData);

            // Activate pending statuses if needed
            $this->activatePendingMergeStatus($changeRequest->id, $statusData);

            DB::commit();
            // Fire CrDeliveredEvent if CR reached Delivered status
            $this->checkAndFireDeliveredEvent($changeRequest, $statusData);

            event(new ChangeRequestStatusUpdated($changeRequest, $statusData, $request, $this->active_flag));

            return true;

        } catch (Exception $e) {
            DB::rollback();
            Log::error('Error updating change request status', [
                'change_request_id' => $changeRequestId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    /**
     * Check if both workflows reached merge point using status NAMES
     */
    private function areBothWorkflowsComplete(int $crId): bool
    {
        // IMPORTANT: Replace 'name' with your actual column name throughout

        $mergeStatusName = 'Pending Update Agreed Requirements';

        // Find merge status
        $mergeStatus = Status::where('status_name', $mergeStatusName)->first();  // ← Change 'name' if needed

        if (!$mergeStatus) {
            Log::error('Merge status not found', [
                'status_name' => $mergeStatusName
            ]);
            return false;
        }

        // Workflow A status names
        $workflowANames = [
            'Request Draft CR Doc',
            'Pending Update Draft CR Doc',
        ];

        // Get Workflow A status IDs
        $workflowAStatusIds = Status::whereIn('status_name', $workflowANames)  // ← Change 'name' if needed
            ->pluck('id')
            ->toArray();

        Log::info('Workflow A status IDs', [
            'names' => $workflowANames,
            'ids' => $workflowAStatusIds
        ]);

        // Check if Workflow A reached merge point
        $workflowAReached = ChangeRequestStatus::where('cr_id', $crId)
            ->whereIn('old_status_id', $workflowAStatusIds)
            ->where('new_status_id', $mergeStatus->id)
            ->exists();

        Log::info('Workflow A reached', ['reached' => $workflowAReached]);

        // Workflow B status names
        $workflowBNames = [
            'Pending Agreed Scope Approval-SA',
            'Pending Agreed Scope Approval-Vendor',
            'Pending Agreed Scope Approval-Business',
        ];

        // Get Workflow B status IDs
        $workflowBStatusIds = Status::whereIn('status_name', $workflowBNames)  // ← Change 'name' if needed
            ->pluck('id')
            ->toArray();

        Log::info('Workflow B status IDs', [
            'names' => $workflowBNames,
            'ids' => $workflowBStatusIds
        ]);

        // Check if Workflow B reached merge point
        $workflowBReached = ChangeRequestStatus::where('cr_id', $crId)
            ->whereIn('old_status_id', $workflowBStatusIds)
            ->where('new_status_id', $mergeStatus->id)
            ->exists();

        Log::info('Workflow B reached', ['reached' => $workflowBReached]);

        $bothComplete = $workflowAReached && $workflowBReached;

        Log::info('Final result', [
            'workflow_a' => $workflowAReached,
            'workflow_b' => $workflowBReached,
            'both_complete' => $bothComplete
        ]);

        return $bothComplete;
    }
    // public function updateChangeRequestStatus(int $changeRequestId, $request): bool
    // {
    //     try {
    //         DB::beginTransaction();

    //         $statusData = $this->extractStatusData($request);
    //         $workflow = $this->getWorkflow($statusData);
    //         $changeRequest = $this->getChangeRequest($changeRequestId);
    //         $userId = $this->getUserId($changeRequest, $request);

    //         Log::info('ChangeRequestStatusService: updateChangeRequestStatus', [
    //             'changeRequestId' => $changeRequestId,
    //             'statusData' => $statusData,
    //             'workflow' => $workflow,
    //             'changeRequest' => $changeRequest,
    //             'userId' => $userId,
    //         ]);
    //     $fromStatus = Status::find($statusData['old_status_id']);
    //     $toStatus = Status::find($statusData['new_status_id']);

    //     // Check if transitioning FROM merge point TO next status
    //     if ($fromStatus && $fromStatus->name === 'Pending Update Agreed Requirements') {
    //         // Check if trying to proceed to "Pending Receive Vendor CR Doc"
    //         if ($toStatus && $toStatus->name === 'Pending Receive Vendor CR Doc') {

    //             // Check if both workflows have reached the merge point
    //             if (!$this->areBothWorkflowsComplete($changeRequestId)) {

    //                 Log::warning('Transition blocked - both workflows have not reached merge point', [
    //                     'cr_id' => $changeRequestId,
    //                     'from_status' => $fromStatus->name,
    //                     'to_status' => $toStatus->name
    //                 ]);

    //                 DB::rollBack();

    //                 throw new \Exception(
    //                     'Cannot proceed to "Pending Receive Vendor CR Doc". ' .
    //                     'Both Workflow A and Workflow B must reach "Pending Update Agreed Requirements" first. ' .
    //                     'Please ensure both workflows are completed before proceeding.'
    //                 );
    //             }

    //             Log::info('Merge point check passed - both workflows complete', [
    //                 'cr_id' => $changeRequestId,
    //                 'proceeding_to' => $toStatus->name
    //             ]);
    //         }
    //     }

    //         if (!$workflow) {
    //             $newStatusId = $statusData['new_status_id'] ?? 'not set';
    //             throw new Exception("Workflow not found for status: {$newStatusId}");
    //         }

    //         // Check if status has changed
    //         $statusChanged = $this->validateStatusChange($changeRequest, $statusData, $workflow);

    //         // If status hasn't changed, just return true without throwing an error
    //         if (!$statusChanged) {
    //             DB::commit();

    //             return true;
    //         }

    //         // Check for dependency hold when transitioning from Pending CAB to pending design
    //         if ($this->isTransitionFromPendingCab($changeRequest, $statusData)) {
    //             $depService = $this->getDependencyService();
    //             if ($depService->shouldHoldCr($changeRequestId)) {
    //                 // Apply dependency hold instead of transitioning
    //                 $depService->applyDependencyHold($changeRequestId);
    //                 Log::info('CR held due to unresolved dependencies', [
    //                     'cr_id' => $changeRequestId,
    //                     'cr_no' => $changeRequest->cr_no,
    //                 ]);
    //                 DB::commit();

    //                 return true; // Block the transition
    //             }
    //         }

    //         $this->processStatusUpdate($changeRequest, $statusData, $workflow, $userId, $request);

    //         // Fire CrDeliveredEvent if CR reached Delivered status
    //         //$this->checkAndFireDeliveredEvent($changeRequest, $statusData);

    //         DB::commit();
    //         // Fire CrDeliveredEvent if CR reached Delivered status
    //         $this->checkAndFireDeliveredEvent($changeRequest, $statusData);

    //         return true;

    //     } catch (Exception $e) {
    //         DB::rollback();
    //         Log::error('Error updating change request status', [
    //             'change_request_id' => $changeRequestId,
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString(),
    //         ]);
    //         throw $e;
    //     }
    // }

    /**
     * Check if a status is the independent Workflow A status
     */
    private function isIndependentWorkflowA(int $statusId): bool
    {
        $status = Status::find($statusId);

        if (!$status) {
            return false;
        }

        // Only "Request Draft CR Doc" is independent (Workflow A)
        return $status->name === 'Request Draft CR Doc';
    }

    /**
     * Check if two statuses are both Workflow A (should not affect each other)
     * Returns true ONLY if:
     * - One is Workflow A ("Request Draft CR Doc")
     * - The other is NOT Workflow A (Workflow B approval)
     */
    private function shouldPreserveForIndependentWorkflow(int $currentStatusId, int $otherStatusId): bool
    {
        $currentIsWorkflowA = $this->isIndependentWorkflowA($currentStatusId);
        $otherIsWorkflowA = $this->isIndependentWorkflowA($otherStatusId);

        // If one is Workflow A and the other is not, they should not affect each other
        // This means: preserve the other status
        if ($currentIsWorkflowA && !$otherIsWorkflowA) {
            return true; // Current is A, other is B - preserve B
        }

        if (!$currentIsWorkflowA && $otherIsWorkflowA) {
            return true; // Current is B, other is A - preserve A
        }

        // Both are Workflow A OR both are Workflow B - they can affect each other normally
        return false;
    }

    /**
     * Get the active status value based on same_time field in new_workflows
     * 
     * @param int $fromStatusId The old/from status ID
     * @param int $toStatusId The new/to status ID
     * @return string '1' for active or '2' for completed
     */
    private function getActiveStatusBySameTime(int $fromStatusId, int $toStatusId): string
    {
        // Check if there's a workflow definition with same_time field
        $workflow = \App\Models\NewWorkFlow::whereHas('workflowstatus', function ($q) use ($fromStatusId, $toStatusId) {
            $q->where('from_status_id', $fromStatusId)
                ->where('to_status_id', $toStatusId);
        })->first();

        if (!$workflow) {
            // If no workflow found, default to active status
            return self::ACTIVE_STATUS;  // '1'
        }

        // Check same_time field
        if (isset($workflow->same_time) && $workflow->same_time == 1) {
            // same_time = 1: Set as completed
            Log::info('Setting status as completed based on same_time=1', [
                'from_status_id' => $fromStatusId,
                'to_status_id' => $toStatusId,
                'workflow_id' => $workflow->id
            ]);
            return self::COMPLETED_STATUS;  // '2'
        }

        // same_time = 0 or NULL: Set as active
        return self::ACTIVE_STATUS;  // '1'
    }

    private function validateStatusChange($changeRequest, $statusData, $workflow)
    {
        $currentStatus = $changeRequest->status;
        $newStatus = $statusData['new_status_id'] ?? null;

        // Debug log to see what values we're working with
        \Log::debug('Status change validation', [
            'currentStatus' => $currentStatus,
            'newStatus' => $newStatus,
            'statusData' => $statusData,
        ]);

        // Return false if status hasn't changed (not an error condition)
        if ($currentStatus == $newStatus) {  // Using loose comparison in case of string vs int
            return false;
        }

        // Add other validation rules here if needed
        // Throw exceptions for actual validation failures

        return true;
    }

    /**
     * Extract status data from request
     */
    private function extractStatusData($request): array
    {
        $newStatusId = $request['new_status_id'] ?? $request->new_status_id ?? null;
        $oldStatusId = $request['old_status_id'] ?? $request->old_status_id ?? null;
        $newWorkflowId = $request['new_workflow_id'] ?? null;

        if (!$newStatusId || !$oldStatusId) {
            throw new InvalidArgumentException('Missing required status IDs');
        }

        return [
            'new_status_id' => $newStatusId,
            'old_status_id' => $oldStatusId,
            'new_workflow_id' => $newWorkflowId,
        ];
    }

    /**
     * Get workflow based on status data
     */
    private function getWorkflow(array $statusData): ?NewWorkFlow
    {
        $workflowId = $statusData['new_workflow_id'] ?: $statusData['new_status_id'];

        return NewWorkFlow::find($workflowId);
    }

    /**
     * Get change request by ID
     */
    private function getChangeRequest(int $id): ChangeRequest
    {
        $changeRequest = ChangeRequest::find($id);

        if (!$changeRequest) {
            throw new Exception("Change request not found: {$id}");
        }

        return $changeRequest;
    }

    /**
     * Determine user ID for the status update
     */
    private function getUserId(ChangeRequest $changeRequest, $request): int
    {
        if (Auth::check()) {
            return Auth::id();
        }
        // Try to get user from division manager email
        if ($changeRequest->division_manager) {
            $user = User::where('email', $changeRequest->division_manager)->first();
            if ($user) {
                return $user->id;
            }
        }

        // Fallback to assigned user
        $assignedTo = $request['assign_to'] ?? null;
        if (!$assignedTo) {
            throw new Exception('Unable to determine user for status update');
        }

        return $assignedTo;
    }

    /**
     * Process the main status update logic
     */
    private function processStatusUpdate(
        ChangeRequest $changeRequest,
        array $statusData,
        NewWorkFlow $workflow,
        int $userId,
        $request
    ): void {
        $technicalTeamCounts = $this->getTechnicalTeamCounts($changeRequest->id, $statusData['old_status_id']);

        $this->updateCurrentStatus($changeRequest->id, $statusData, $workflow, $technicalTeamCounts);

        $this->createNewStatuses($changeRequest, $statusData, $workflow, $userId, $request);

        // $this->handleNotifications($statusData, $changeRequest->id, $request);
        // event(new ChangeRequestStatusUpdated($changeRequest, $statusData, $request, $this->active_flag));

    }

    /**
     * Get technical team approval counts
     */
    private function getTechnicalTeamCounts(int $changeRequestId, int $oldStatusId): array
    {
        $technicalCr = TechnicalCr::where('cr_id', $changeRequestId)
            // ->where('status', self::INACTIVE_STATUS)
            ->whereRaw('CAST(status AS CHAR) = ?', ['1'])
            ->first();

        if (!$technicalCr) {
            return ['total' => 0, 'approved' => 0];
        }

        $total = $technicalCr->technical_cr_team()
            ->where('current_status_id', $oldStatusId)
            ->count();

        $approved = $technicalCr->technical_cr_team()
            ->where('current_status_id', $oldStatusId)
            // ->where('status', self::ACTIVE_STATUS)
            // ->whereIN('status',self::$ACTIVE_STATUS_ARRAY)
            ->whereRaw('CAST(status AS CHAR) = ?', ['1'])
            ->count();

        return ['total' => $total, 'approved' => $approved];
    }

    /**
     * Update the current status record
     */
    private function updateCurrentStatus(
        int $changeRequestId,
        array $statusData,
        NewWorkFlow $workflow,
        array $technicalTeamCounts
    ): void {
        if (request()->reference_status) {
            $currentStatus = ChangeRequestStatus::find(request()->reference_status);
        } else {
            $currentStatus = ChangeRequestStatus::where('cr_id', $changeRequestId)
                ->where('new_status_id', $statusData['old_status_id'])
                //->where('active', self::ACTIVE_STATUS)
                //->whereIN('active',self::$ACTIVE_STATUS_ARRAY)
                ->active()
                ->first();

            //to check all the active statuses for this CR
            $allActiveStatuses = ChangeRequestStatus::where('cr_id', $changeRequestId)
                ->active()
                ->get(['id', 'new_status_id', 'old_status_id', 'active']);
            Log::debug('updateCurrentStatus: All active statuses for this CR', [
                'cr_id' => $changeRequestId,
                'active_statuses' => $allActiveStatuses->toArray()
            ]);
        }

        if (!$currentStatus) {
            Log::warning('Current status not found for update', [
                'cr_id' => $changeRequestId,
                'old_status_id' => $statusData['old_status_id'],
            ]);

            return;
        }

        // the current record
        Log::debug('updateCurrentStatus: Found current status', [
            'cr_id' => $changeRequestId,
            'status_record_id' => $currentStatus->id,
            'current_active_value' => $currentStatus->active,
            'new_status_id' => $currentStatus->new_status_id
        ]);

        $workflowActive = $workflow->workflow_type == self::WORKFLOW_NORMAL
            ? self::INACTIVE_STATUS
            : self::COMPLETED_STATUS;
        
        // Log for debugging null created_at issue
        if (is_null($currentStatus->created_at)) {
            Log::warning('Current status has null created_at', [
                'cr_id' => $changeRequestId,
                'status_record_id' => $currentStatus->id,
                'new_status_id' => $currentStatus->new_status_id,
                'active' => $currentStatus->active
            ]);
        }
        
        $slaDifference = $this->calculateSlaDifference($currentStatus->created_at);

        $shouldUpdate = $this->shouldUpdateCurrentStatus($statusData['old_status_id'], $technicalTeamCounts);
        $newStatus = Status::find($statusData['new_status_id']);

        if ($newStatus && $newStatus->status_name === 'Request Draft CR Doc') {

            Log::info('Transitioning TO Request Draft CR Doc - setting need_ui_ux=1', [
                'cr_id' => $changeRequestId,
                'status_id' => $newStatus->id,
                'status_name' => $newStatus->status_name
            ]);

            // Get the change request
            $changeRequest = ChangeRequest::find($changeRequestId);

            if ($changeRequest) {

                // Update need_ui_ux to 1
                //$changeRequest->update(['need_ui_ux' => 1]);

                Log::info('need_ui_ux successfully set to 1', [
                    'cr_id' => $changeRequestId,
                    'old_value' => $changeRequest->getOriginal('need_ui_ux'),
                    'new_value' => 1
                ]);

            } else {
                Log::error('Change request not found for need_ui_ux update', [
                    'cr_id' => $changeRequestId
                ]);
            }
        }
        // Only update if conditions are met
        if ($shouldUpdate) {
            $updateResult = $currentStatus->update([
                'sla_dif' => $slaDifference,
                'active' => self::COMPLETED_STATUS
            ]);

            // to check update result
            Log::debug('updateCurrentStatus: Update executed', [
                'cr_id' => $changeRequestId,
                'status_record_id' => $currentStatus->id,
                'update_result' => $updateResult,
                'new_active_value' => self::COMPLETED_STATUS,
                'verify_after_update' => $currentStatus->fresh()->active ?? 'failed'
            ]);

            $this->handleDependentStatuses($changeRequestId, $currentStatus, $workflowActive);
        } else {
            Log::warning('updateCurrentStatus: Skipped update due to shouldUpdateCurrentStatus=false', [
                'cr_id' => $changeRequestId,
                'status_record_id' => $currentStatus->id
            ]);
        }
    }

    /**
     * Check if current status should be updated
     */
    private function shouldUpdateCurrentStatus(int $oldStatusId, array $technicalTeamCounts): bool
    {
        if ($oldStatusId != self::TECHNICAL_REVIEW_STATUS) {
            return true;
        }

        return $technicalTeamCounts['total'] > 0 &&
            $technicalTeamCounts['total'] == $technicalTeamCounts['approved'];
    }

    /**
     * Calculate SLA difference in days
     */
    private function calculateSlaDifference(?string $createdAt): int
    {
        if (!$createdAt) {
            return 0; // Return 0 if created_at is null
        }
        
        return Carbon::parse($createdAt)->diffInDays(Carbon::now());
    }

    /**
     * Handle dependent statuses based on workflow type
     */
    /**
     * Handle dependent statuses
     * MODIFIED: Preserves independence between Workflow A and Workflow B
     */
    private function handleDependentStatuses(
        int $changeRequestId,
        ChangeRequestStatus $currentStatus,
        string $workflowActive
    ): void {
        // Get all statuses with the same old_status_id that are still active
        $dependentStatuses = ChangeRequestStatus::where('cr_id', $changeRequestId)
            ->where('old_status_id', $currentStatus->old_status_id)
            ->active()
            ->get();

        Log::debug('handleDependentStatuses: Processing dependent statuses', [
            'cr_id' => $changeRequestId,
            'current_status_id' => $currentStatus->new_status_id,
            'old_status_id' => $currentStatus->old_status_id,
            'dependent_count' => $dependentStatuses->count(),
            'workflow_active' => $workflowActive
        ]);

        // Check if current status is independent Workflow A
        $currentIsWorkflowA = $this->isIndependentWorkflowA($currentStatus->new_status_id);

        if ($currentIsWorkflowA) {
            // ================================================================
            // WORKFLOW A MODE: Do NOT deactivate Workflow B statuses
            // ================================================================

            Log::info('Workflow A status detected - preserving Workflow B statuses', [
                'cr_id' => $changeRequestId,
                'current_status_id' => $currentStatus->new_status_id,
                'current_status_name' => 'Request Draft CR Doc'
            ]);

            $dependentStatuses->each(function ($status) use ($currentStatus, $changeRequestId) {
                // Skip if it's the same record
                if ($status->id === $currentStatus->id) {
                    return;
                }

                // Check if this is a Workflow B status (should be preserved)
                if ($this->shouldPreserveForIndependentWorkflow($currentStatus->new_status_id, $status->new_status_id)) {
                    Log::info('Preserving Workflow B status (independent from Workflow A)', [
                        'cr_id' => $changeRequestId,
                        'preserved_status_id' => $status->new_status_id,
                        'reason' => 'Independent workflow - A does not affect B'
                    ]);
                    // DO NOT deactivate - preserve it
                } else {
                    // This would be another Workflow A status (though there's only one)
                    // Deactivate normally
                    $status->update(['active' => self::INACTIVE_STATUS]);
                    Log::info('Deactivated same workflow status', [
                        'cr_id' => $changeRequestId,
                        'status_id' => $status->new_status_id
                    ]);
                }
            });

        } else {
            // ================================================================
            // WORKFLOW B OR NORMAL MODE
            // ================================================================

            Log::debug('Workflow B or normal workflow - standard deactivation', [
                'cr_id' => $changeRequestId,
                'current_status_id' => $currentStatus->new_status_id,
                'workflow_active' => $workflowActive
            ]);

            if (!$workflowActive) {
                // Abnormal workflow - deactivate all dependent statuses
                $dependentStatuses->each(function ($status) use ($currentStatus, $changeRequestId) {
                    // Skip if it's the same record
                    if ($status->id === $currentStatus->id) {
                        return;
                    }

                    // Preserve Workflow A if current is Workflow B
                    if ($this->shouldPreserveForIndependentWorkflow($currentStatus->new_status_id, $status->new_status_id)) {
                        Log::info('Preserving Workflow A status (independent from Workflow B)', [
                            'cr_id' => $changeRequestId,
                            'preserved_status_id' => $status->new_status_id,
                            'reason' => 'Independent workflow - B does not affect A'
                        ]);
                        // DO NOT deactivate Workflow A
                    } else {
                        // Normal deactivation for same workflow statuses
                        $status->update(['active' => self::INACTIVE_STATUS]);
                        Log::debug('Deactivated dependent status', [
                            'cr_id' => $changeRequestId,
                            'status_id' => $status->new_status_id
                        ]);
                    }
                });
            }
        }
    }

    /**
     * Create new status records based on workflow
     */
    /**
   * Create new status records based on workflow
  /**
   * Create new status records based on workflow
   */



    /**
     * Create new status records for a change request
     * Handles parallel workflows and merge point logic
     * 
     * @param ChangeRequest $changeRequest
     * @param array $statusData
     * @param NewWorkFlow $workflow
     * @param int $userId
     * @param mixed $request
     * @return void
     */
    private function createNewStatuses(
        ChangeRequest $changeRequest,
        array $statusData,
        NewWorkFlow $workflow,
        int $userId,
        $request
    ): void {

        if (request()->reference_status) {
            $currentStatus = ChangeRequestStatus::find(request()->reference_status);
        } else {
            $currentStatus = ChangeRequestStatus::where('cr_id', $changeRequest->id)
                ->where('new_status_id', $statusData['old_status_id'])
                ->first();
        }

        // ═══════════════════════════════════════════════════════════════════════
        // FLEXIBLE PARALLEL WORKFLOWS from "Pending Create Agreed Scope"
        // ═══════════════════════════════════════════════════════════════════════

        $oldStatus = Status::find($statusData['old_status_id']);

        // Get new status from workflow
        $newStatus = null;
        if ($workflow && $workflow->workflowstatus->isNotEmpty()) {
            $newStatus = $workflow->workflowstatus->first()->to_status;
        }

        $shouldCreateParallelWorkflows = false;
        $statusesToCreate = [];



        if ($oldStatus && $oldStatus->status_name == 'Pending Create Agreed Scope') {

            Log::info('Transitioning FROM Pending Create Agreed Scope', [
                'cr_id' => $changeRequest->id,
                'old_status_id' => $oldStatus->id,
                'old_status_name' => $oldStatus->status_name,
                'new_status_id' => $newStatus ? $newStatus->id : 'null',
                'new_status_name' => $newStatus ? $newStatus->status_name : 'null'
            ]);

            // ════════════════════════════════════════════════════════════
            // ✨ SPECIAL CASE: "Need Update" selected
            // Call our dedicated Need Update action instead of normal workflow
            // ════════════════════════════════════════════════════════════
            
            if ($newStatus && $newStatus->status_name == 'Pending Create Agreed Scope' && 
                isset($statusData['need_update']) && $statusData['need_update'] === true) {
                
                Log::info('Need Update selected - calling dedicated Need Update action', [
                    'cr_id' => $changeRequest->id,
                    'new_status_id' => $newStatus->id
                ]);
                
                // Call our Need Update action
                $this->handleNeedUpdateAction($changeRequest->id);
                
                // Return early to skip normal workflow processing
                return;
            }

            // ════════════════════════════════════════════════════════════
            // ✨ CASE 1: "Request Draft CR Doc" selected
            // Create ALL 4 statuses (Workflow A + Workflow B)
            // ════════════════════════════════════════════════════════════

            if ($newStatus && $newStatus->status_name == 'Request Draft CR Doc') {

                $shouldCreateParallelWorkflows = true;

                // Create ALL 4 statuses
                $statusesToCreate = [
                    ['status_name' => 'Request Draft CR Doc', 'current_group_id' => 8],
                    ['status_name' => 'Pending Agreed Scope Approval-SA', 'current_group_id' => 9],
                    ['status_name' => 'Pending Agreed Scope Approval-Vendor', 'current_group_id' => 21],
                    ['status_name' => 'Pending Agreed Scope Approval-Business', 'current_group_id' => null],
                ];

                Log::info('Request Draft CR Doc selected - creating 4 statuses (Workflow A + B)', [
                    'cr_id' => $changeRequest->id,
                    'total_statuses' => count($statusesToCreate)
                ]);
            }

            // ════════════════════════════════════════════════════════════
            // ✨ CASE 2: "Pending Agreed Scope Approval-SA" selected
            // Create ONLY 3 Workflow B statuses (no Workflow A)
            // ════════════════════════════════════════════════════════════
            elseif ($newStatus && $newStatus->status_name === 'Pending Agreed Scope Approval-SA') {

                $shouldCreateParallelWorkflows = true;

                // Create ONLY Workflow B statuses
                $statusesToCreate = [
                    ['status_name' => 'Pending Agreed Scope Approval-SA', 'current_group_id' => 9],
                    ['status_name' => 'Pending Agreed Scope Approval-Vendor', 'current_group_id' => 21],
                    ['status_name' => 'Pending Agreed Scope Approval-Business', 'current_group_id' => null],
                ];

                Log::info('Pending Agreed Scope Approval-SA selected - creating 3 statuses (Workflow B only)', [
                    'cr_id' => $changeRequest->id,
                    'total_statuses' => count($statusesToCreate)
                ]);
            }

            // ════════════════════════════════════════════════════════════
            // ✨ CASE 3: Any other status selected
            // Use normal workflow (single status)
            // ════════════════════════════════════════════════════════════
            else {
                Log::info('Other status selected - using normal workflow', [
                    'cr_id' => $changeRequest->id,
                    'selected_status' => $newStatus ? $newStatus->status_name : 'unknown'
                ]);
            }
        }

        // ═══════════════════════════════════════════════════════════════════════
        // CREATE PARALLEL WORKFLOW STATUSES
        // ═══════════════════════════════════════════════════════════════════════

        if ($shouldCreateParallelWorkflows && !empty($statusesToCreate)) {

            $previous_group_id = $currentStatus->current_group_id ?? 8;

            foreach ($statusesToCreate as $index => $statusConfig) {
                $statusName = $statusConfig['status_name'];
                $groupId = $statusConfig['current_group_id'];

                $status = Status::where('status_name', $statusName)->first();

                if (!$status) {
                    Log::error('Status not found for parallel workflow', [
                        'status_name' => $statusName,
                        'cr_id' => $changeRequest->id
                    ]);
                    continue;
                }

                // Determine workflow type
                $workflowType = ($statusName === 'Request Draft CR Doc') ? 'Workflow A' : 'Workflow B';

                $activeStatus = self::ACTIVE_STATUS;

                $payload = $this->buildStatusData(
                    $changeRequest->id,
                    $statusData['old_status_id'],
                    $status->id,
                    null,
                    $currentStatus->reference_group_id ?? 8,
                    $previous_group_id,
                    $groupId,
                    $userId,
                    $activeStatus
                );

                $this->statusRepository->create($payload);

                Log::info('Created parallel workflow status', [
                    'cr_id' => $changeRequest->id,
                    'workflow_type' => $workflowType,
                    'status_name' => $statusName,
                    'status_id' => $status->id,
                    'index' => $index + 1,
                    'total' => count($statusesToCreate)
                ]);
            }

            $this->active_flag = self::ACTIVE_STATUS;

            Log::info('Parallel workflows initialized', [
                'cr_id' => $changeRequest->id,
                'total_created' => count($statusesToCreate)
            ]);

            return;  // Exit early - parallel workflow complete
        }

        // ═══════════════════════════════════════════════════════════════════════
        // NORMAL WORKFLOW PROCESSING
        // For all other cases (creates single status)
        // ═══════════════════════════════════════════════════════════════════════

        foreach ($workflow->workflowstatus as $workflowStatus) {

            if ($this->shouldSkipWorkflowStatus($changeRequest, $workflowStatus, $statusData)) {
                continue;
            }

            $active = $this->determineActiveStatus(
                $changeRequest->id,
                $workflowStatus,
                $workflow,
                $statusData['old_status_id'],
                $statusData['new_status_id'],
                $changeRequest
            );

            $newStatusRow = Status::find($workflowStatus->to_status_id);
            $previous_group_id = session('current_group') ?: (auth()->check() ? auth()->user()->default_group : null);
            $viewTechFlag = $newStatusRow?->view_technical_team_flag ?? false;

            if ($viewTechFlag) {
                $previous_technical_teams = [];
                if ($changeRequest && $changeRequest->technical_Cr_first) {
                    $previous_technical_teams = $changeRequest->technical_Cr_first->technical_cr_team
                        ? $changeRequest->technical_Cr_first->technical_cr_team->pluck('group_id')->toArray()
                        : [];
                }

                $teams = $request->technical_teams ?? $request['technical_teams'] ?? $previous_technical_teams;

                if (!empty($teams) && is_iterable($teams)) {
                    foreach ($teams as $teamGroupId) {
                        $payload = $this->buildStatusData(
                            $changeRequest->id,
                            $statusData['old_status_id'],
                            (int) $workflowStatus->to_status_id,
                            (int) $teamGroupId,
                            (int) $teamGroupId,
                            (int) $previous_group_id,
                            (int) $teamGroupId,
                            $userId,
                            $active
                        );

                        $this->statusRepository->create($payload);
                    }
                }
            } else {
                $payload = $this->buildStatusData(
                    $changeRequest->id,
                    $statusData['old_status_id'],
                    (int) $workflowStatus->to_status_id,
                    null,
                    $currentStatus->reference_group_id,
                    $previous_group_id,
                    optional($newStatusRow->group_statuses)
                        ->where('type', '2')
                        ->pluck('group_id')
                        ->first(),
                    $userId,
                    $active
                );

                $this->statusRepository->create($payload);

                Log::info('Created single status (normal workflow)', [
                    'cr_id' => $changeRequest->id,
                    'status_name' => $newStatusRow ? $newStatusRow->status_name : 'unknown'
                ]);
            }
        }
    }
    /**
     * Check if workflow status should be skipped
     */
    private function shouldSkipWorkflowStatus(
        ChangeRequest $changeRequest,
        $workflowStatus,
        array $statusData
    ): bool {
        // Skip design status if design duration is 0
        return $changeRequest->design_duration == '0'
            && $workflowStatus->to_status_id == 40
            && $statusData['old_status_id'] == 74;
    }

    /**
     * Determine if new status should be active
     */

    private function determineActiveStatus(
        int $changeRequestId,
        $workflowStatus,
        NewWorkFlow $workflow,
        int $oldStatusId,
        int $newStatusId,
        ChangeRequest $changeRequest
    ): string {

        // Priority 1: Workflow A
        $fromStatus = Status::find($oldStatusId);
        if ($fromStatus && $fromStatus->status_name === 'Request Draft CR Doc') {
            return self::ACTIVE_STATUS;
        }

        // ════════════════════════════════════════════════════════════
        // Priority 2: MERGE POINT CHECK
        // ✨ MODIFIED: Only apply if CR used parallel workflows
        // ════════════════════════════════════════════════════════════

        $mergePointStatusId = 250;

        if ($workflowStatus->to_status_id == $mergePointStatusId) {

            // ════════════════════════════════════════════════════════
            // ✨ NEW: Check if this CR used parallel workflows
            // ════════════════════════════════════════════════════════

            $usedParallelWorkflows = $this->didUseParallelWorkflows($changeRequestId);

            Log::info('Merge point transition - checking if parallel workflows used', [
                'cr_id' => $changeRequestId,
                'used_parallel_workflows' => $usedParallelWorkflows
            ]);

            if ($usedParallelWorkflows) {
                // This CR used parallel workflows - apply merge point logic

                Log::info('Parallel workflows detected - checking completion', [
                    'cr_id' => $changeRequestId
                ]);

                $bothWorkflowsComplete = $this->areBothWorkflowsCompleteById(
                    $changeRequestId,
                    $mergePointStatusId
                );

                if ($bothWorkflowsComplete) {
                    Log::info('Both workflows complete - active=1', [
                        'cr_id' => $changeRequestId
                    ]);

                    $this->active_flag = self::ACTIVE_STATUS;
                    return self::ACTIVE_STATUS;  // '1'
                } else {
                    Log::info('Only one workflow complete - active=0', [
                        'cr_id' => $changeRequestId
                    ]);

                    $this->active_flag = self::INACTIVE_STATUS;
                    return self::INACTIVE_STATUS;  // '0'
                }

            } else {
                // This CR did NOT use parallel workflows - normal logic

                Log::info('No parallel workflows - using normal logic', [
                    'cr_id' => $changeRequestId
                ]);

                // Fall through to normal logic below
            }
        }

        // ════════════════════════════════════════════════════════════
        // Priority 3: Original logic for all other workflows
        // ════════════════════════════════════════════════════════════

        $active = self::INACTIVE_STATUS;

        $cr_status = ChangeRequestStatus::where('cr_id', $changeRequestId)
            ->where('new_status_id', $oldStatusId)
            ->completedOrActive()
            ->latest()
            ->first();

        if (!$cr_status) {
            $this->active_flag = self::INACTIVE_STATUS;
            return self::INACTIVE_STATUS;
        }

        $parkedIds = array_values(config('change_request.promo_parked_status_ids', []));

        $all_depend_statuses = ChangeRequestStatus::where('cr_id', $changeRequestId)
            ->where('old_status_id', $cr_status->old_status_id)
            ->completedOrActive()
            ->whereNull('group_id')
            ->whereHas('change_request_data', function ($query) {
                $query->where('workflow_type_id', '!=', 9);
            })
            ->get();

        $depend_statuses = ChangeRequestStatus::where('cr_id', $changeRequestId)
            ->where('old_status_id', $cr_status->old_status_id)
            ->completed()
            ->whereNull('group_id')
            ->whereHas('change_request_data', function ($query) {
                $query->where('workflow_type_id', '!=', 9);
            })
            ->get();

        $depend_active_statuses = ChangeRequestStatus::where('cr_id', $changeRequestId)
            ->where('old_status_id', $cr_status->old_status_id)
            ->active()
            ->whereNull('group_id')
            ->whereHas('change_request_data', function ($query) {
                $query->where('workflow_type_id', '!=', 9);
            })
            ->get();

        if ($changeRequest->workflow_type_id == 9) {
            $NextStatusWorkflow = NewWorkFlow::find($newStatusId);

            if ($NextStatusWorkflow && isset($NextStatusWorkflow->workflowstatus[0])) {
                $nextToStatusId = $NextStatusWorkflow->workflowstatus[0]->to_status_id;

                if (in_array($nextToStatusId, $parkedIds, true)) {
                    $depend_active_count = ChangeRequestStatus::where('cr_id', $changeRequestId)
                        ->active()
                        ->count();

                    $active = $depend_active_count > 0 ? self::INACTIVE_STATUS : self::ACTIVE_STATUS;
                } else {
                    $active = self::ACTIVE_STATUS;
                }
            } else {
                $active = self::ACTIVE_STATUS;
            }
        } else {
            $active = $depend_active_statuses->count() > 0 ? self::INACTIVE_STATUS : self::ACTIVE_STATUS;
        }

        $this->active_flag = $active;

        return $active;
    }


    /**
     * Check if this change request used the parallel workflow feature
     * 
     * @param int $crId
     * @return bool
     */
    private function didUseParallelWorkflows(int $crId): bool
    {
        $sourceStatus = Status::where('status_name', 'Pending Create Agreed Scope')->first();

        if (!$sourceStatus) {
            return false;
        }

        // Workflow A status
        $workflowAStatusId = Status::where('status_name', 'Request Draft CR Doc')->value('id');

        // Workflow B statuses
        $workflowBStatusIds = Status::whereIn('status_name', [
            'Pending Agreed Scope Approval-SA',
            'Pending Agreed Scope Approval-Vendor',
            'Pending Agreed Scope Approval-Business'
        ])->pluck('id')->toArray();

        if (!$workflowAStatusId || empty($workflowBStatusIds)) {
            return false;
        }

        // ✨ KEY: Check if BOTH workflows exist
        $hasWorkflowA = ChangeRequestStatus::where('cr_id', $crId)
            ->where('old_status_id', $sourceStatus->id)
            ->where('new_status_id', $workflowAStatusId)
            ->exists();

        $hasWorkflowB = ChangeRequestStatus::where('cr_id', $crId)
            ->where('old_status_id', $sourceStatus->id)
            ->whereIn('new_status_id', $workflowBStatusIds)
            ->exists();

        // Both must exist!
        $hasBothWorkflows = $hasWorkflowA && $hasWorkflowB;

        Log::debug('Parallel workflow check', [
            'cr_id' => $crId,
            'workflow_a' => $hasWorkflowA,
            'workflow_b' => $hasWorkflowB,
            'result' => $hasBothWorkflows
        ]);

        return $hasBothWorkflows;
    }
    /**
     * Check workflow dependencies
     */
    private function checkWorkflowDependencies(int $changeRequestId, $workflowStatus): bool
    {
        if (!$workflowStatus->dependency_ids) {
            return true;
        }

        $dependencyIds = array_diff(
            $workflowStatus->dependency_ids,
            [$workflowStatus->new_workflow_id]
        );

        foreach ($dependencyIds as $workflowId) {
            if (!$this->isDependencyMet($changeRequestId, $workflowId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a specific dependency is met
     */
    private function isDependencyMet(int $changeRequestId, int $workflowId): bool
    {
        $dependentWorkflow = NewWorkFlow::find($workflowId);

        if (!$dependentWorkflow) {
            return false;
        }

        return ChangeRequestStatus::where('cr_id', $changeRequestId)
            ->where('new_status_id', $dependentWorkflow->from_status_id)
            ->where('old_status_id', $dependentWorkflow->previous_status_id)
            // ->where('active', self::COMPLETED_STATUS)
            // ->whereIN('active',self::$COMPLETED_STATUS_ARRAY)
            ->completed()
            ->exists();
    }

    /**
     * Check dependent workflows for normal workflow type
     */
    private function checkDependentWorkflows(int $changeRequestId, NewWorkFlow $workflow): string
    {
        $dependentStatuses = ChangeRequestStatus::where('cr_id', $changeRequestId)
            // ->where('active', self::ACTIVE_STATUS)
            // ->whereIN('active',self::$ACTIVE_STATUS_ARRAY)
            ->active()
            ->get();

        if ($dependentStatuses->count() > 1) {
            return self::INACTIVE_STATUS;
        }

        $checkDependentWorkflow = NewWorkFlow::whereHas('workflowstatus', function ($query) use ($workflow) {
            $query->where('to_status_id', $workflow->workflowstatus[0]->to_status_id);
        })->pluck('from_status_id');

        $dependentCount = ChangeRequestStatus::where('cr_id', $changeRequestId)
            ->whereIn('new_status_id', $checkDependentWorkflow)
            // ->where('active', self::ACTIVE_STATUS)
            // ->whereIN('active',self::$ACTIVE_STATUS_ARRAY)
            ->active()
            ->count();

        return $dependentCount > 0 ? self::INACTIVE_STATUS : self::ACTIVE_STATUS;
    }

    /**
     * Build status data array
     */
    private function buildStatusData(
        int $changeRequestId,
        int $oldStatusId,
        int $newStatusId,
        ?int $group_id,
        ?int $reference_group_id,
        ?int $previous_group_id,
        ?int $current_group_id,
        int $userId,
        string $active
    ): array {
        $status = Status::find($newStatusId);
        $sla = $status ? (int) $status->sla : 0;

        return [
            'cr_id' => $changeRequestId,
            'old_status_id' => $oldStatusId,
            'new_status_id' => $newStatusId,
            'group_id' => $group_id,
            'reference_group_id' => $reference_group_id,
            'previous_group_id' => $previous_group_id,
            'current_group_id' => $current_group_id,
            'user_id' => $userId,
            'sla' => $sla,
            'active' => $active, // '0' | '1' | '2'
        ];
    }

    /**
     * Handle email notifications
     */
    private function handleNotifications(array $statusData, int $changeRequestId, $request): void
    {
        // dd($request->all());
        // Notify CR Manager when status changes from 99 to 101
        if (
            $statusData['old_status_id'] == 99 &&
            $this->hasStatusTransition($changeRequestId, 101)
        ) {

            try {
                $this->mailController->notifyCrManager($changeRequestId);
            } catch (Exception $e) {
                Log::error('Failed to send CR Manager notification', [
                    'change_request_id' => $changeRequestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        /*
        // Notify Dev Team When status changes to Technical Estimation or Pending Implementation or Technical Implementation
        $assigned_user_id = null;
        if(isset($request->assignment_user_id)){
             $assigned_user_id = $request->assignment_user_id;
        }
        $devTeamStatuses = [config('change_request.status_ids.technical_estimation'),config('change_request.status_ids.pending_implementation'),config('change_request.status_ids.technical_implementation')];
        $newStatusId = NewWorkFlowStatuses::where('new_workflow_id', $statusData['new_status_id'])->get()->pluck('to_status_id')->toArray();
        //dd($newStatusId);
        if (array_intersect($devTeamStatuses, $newStatusId) && $this->active_flag == '1') {
            try {
                 $this->mailController->notifyDevTeam($changeRequestId , $statusData['old_status_id'] , $newStatusId, $assigned_user_id);
             } catch (\Exception $e) {
                 Log::error('Failed to send Dev Team notification', [
                     'change_request_id' => $changeRequestId,
                     'error' => $e->getMessage()
                 ]);
             }
        }
        */

        // Notify group when status changes.
        // dd($request->all(), $statusData);
        $newStatusId = NewWorkFlowStatuses::where(
            'new_workflow_id',
            $request->new_status_id
        )->get()->pluck('to_status_id')->toArray();
        // dd($newStatusId);
        $userToNotify = [];
        if (in_array(\App\Services\StatusConfigService::getStatusId('pending_cd_analysis'), $newStatusId)) {
            if (!empty($request->cr_member)) {
                $userToNotify = [$request->cr_member];
            }
        }

        $cr = ChangeRequest::find($changeRequestId);
        $targetStatus = Status::with('group_statuses')->whereIn('id', $newStatusId)->first();
        // $group_id = $targetStatus->group_statuses->first()->group_id ?? null;
        $viewGroup = GroupStatuses::where('status_id', $targetStatus->id)->where(
            'type',
            '2'
        )->pluck('group_id')->toArray();
        $group_id = $cr->application->group_applications->first()->group_id ?? null;
        // will check if group_id is in viewGroup then we will send the notification to this group is only
        // dd($group_id,$viewGroup);
        $groupToNotify = [];
        if (in_array($group_id, $viewGroup)) {
            $recieveNotification = Group::where('id', $group_id)->where('recieve_notification', '1')->first();
            if ($recieveNotification) {
                $groupToNotify = [$group_id];
            } else {
                $groupToNotify = [];
            }
        } else {
            $groupToNotify = Group::whereIn('id', $viewGroup)
                ->where('recieve_notification', '1')
                ->pluck('id')
                ->toArray();
        }
        // dd($groupToNotify);

        if ($this->active_flag == '1' && !empty($groupToNotify)) {
            foreach ($groupToNotify as $groupId) {
                try {
                    $this->mailController->notifyGroup(
                        $changeRequestId,
                        $statusData['old_status_id'],
                        $newStatusId,
                        $groupId,
                        $userToNotify
                    );
                } catch (Exception $e) {
                    Log::error('Failed to send Group notification', [
                        'change_request_id' => $changeRequestId,
                        'group_id' => $groupId,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }
        }
    }

    /**
     * Check if status transition exists
     */
    private function hasStatusTransition(int $changeRequestId, int $toStatusId): bool
    {
        return ChangeRequestStatus::where('cr_id', $changeRequestId)
            ->where('new_status_id', $toStatusId)
            ->exists();
    }

    // Get the dependency service (lazy loaded)
    private function getDependencyService(): CrDependencyService
    {
        if (!$this->dependencyService) {
            $this->dependencyService = new CrDependencyService();
        }

        return $this->dependencyService;
    }

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

        Log::info('Handling agreed scope approval transition', [
            'cr_id' => $crId,
            'new_status_id' => $newStatusId
        ]);

        // Define the approval status IDs that should be deactivated
        $approvalStatusIds = [
            self::$PENDING_AGREED_SCOPE_SA_STATUS_ID,
            self::$PENDING_AGREED_SCOPE_VENDOR_STATUS_ID,
            self::$PENDING_AGREED_SCOPE_BUSINESS_STATUS_ID,
            self::$REQUEST_DRAFT_CR_DOC_STATUS_ID,
        ];

        // Filter out null values and the current status
        $approvalStatusIds = array_filter($approvalStatusIds, function($id) {
            return $id !== null;
        });

        if (empty($approvalStatusIds)) {
            Log::warning('No approval status IDs found for transition handling', [
                'cr_id' => $crId
            ]);
            return;
        }

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
            // First check if an active record already exists
            $existingActiveRecord = ChangeRequestStatus::where('cr_id', $crId)
                ->where('new_status_id', self::$PENDING_CREATE_AGREED_SCOPE_STATUS_ID)
                ->active()
                ->first();

            if ($existingActiveRecord) {
                Log::info('Active Pending Create Agreed Scope record already exists', [
                    'cr_id' => $crId,
                    'existing_record_id' => $existingActiveRecord->id
                ]);
                return; // Don't create a duplicate
            }

            // Get the current user ID
            $userId = Auth::id();
            if (!$userId) {
                // Fallback to system user or first user
                $userId = User::first()?->id ?? 1;
            }

            // Find the current status record to get reference data
            $currentStatus = ChangeRequestStatus::where('cr_id', $crId)
                ->where('new_status_id', $statusData['old_status_id'])
                ->first();

            if ($currentStatus) {
                // Create new active status record
                $newStatusRecord = new ChangeRequestStatus();
                $newStatusRecord->cr_id = $crId;
                $newStatusRecord->old_status_id = $statusData['old_status_id'];
                $newStatusRecord->new_status_id = self::$PENDING_CREATE_AGREED_SCOPE_STATUS_ID;
                $newStatusRecord->user_id = $userId;
                $newStatusRecord->group_id = $currentStatus->group_id;
                $newStatusRecord->active = self::ACTIVE_STATUS;
                $newStatusRecord->reference_group_id = $currentStatus->reference_group_id;
                $newStatusRecord->previous_group_id = $currentStatus->previous_group_id;
                $newStatusRecord->current_group_id = $currentStatus->current_group_id;
                $newStatusRecord->save();

                Log::info('Created new active status record', [
                    'cr_id' => $crId,
                    'new_status_id' => self::$PENDING_CREATE_AGREED_SCOPE_STATUS_ID,
                    'record_id' => $newStatusRecord->id
                ]);
            } else {
                Log::warning('Could not find current status record for reference data', [
                    'cr_id' => $crId,
                    'old_status_id' => $statusData['old_status_id']
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error handling agreed scope approval transition', [
                'cr_id' => $crId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Handle "Need Update" action for Change Request
     * This implements the business logic for when user selects "Need Update" from UI
     * 
     * @param int $crId The Change Request ID
     * @return bool Success status
     * @throws Exception
     */
    public function handleNeedUpdateAction(int $crId): bool
    {
        Log::info('Processing Need Update action', [
            'cr_id' => $crId
        ]);

        try {
            DB::beginTransaction();

            // Step 1: Identify the current Change Request
            $changeRequest = ChangeRequest::find($crId);
            if (!$changeRequest) {
                throw new Exception("Change Request not found: {$crId}");
            }

            // Step 2: Define the parallel approval status names
            $parallelStatusNames = [
                'Pending Agreed Scope Approval-SA',
                'Pending Agreed Scope Approval-Vendor', 
                'Pending Agreed Scope Approval-Business',
                'Request Draft CR Doc'
            ];

            // Step 3: Get the status IDs dynamically
            $parallelStatusIds = [];
            foreach ($parallelStatusNames as $statusName) {
                $statusId = $this->getStatusIdByName($statusName);
                if ($statusId) {
                    $parallelStatusIds[] = $statusId;
                }
            }

            if (empty($parallelStatusIds)) {
                Log::warning('No parallel status IDs found', [
                    'cr_id' => $crId,
                    'status_names' => $parallelStatusNames
                ]);
                DB::rollBack();
                return false;
            }

            // Step 4: Update all active records in change_request_statuses 
            // where new_status_id represents any of the parallel statuses
            $deactivatedCount = ChangeRequestStatus::where('cr_id', $crId)
                ->whereIn('new_status_id', $parallelStatusIds)
                ->active()
                ->update(['active' => self::INACTIVE_STATUS]);

            Log::info('Deactivated parallel approval statuses', [
                'cr_id' => $crId,
                'deactivated_count' => $deactivatedCount,
                'parallel_status_ids' => $parallelStatusIds
            ]);

            // If no parallel statuses were found to deactivate, don't proceed with duplication
            // This prevents creating unnecessary duplicate records
            if ($deactivatedCount === 0) {
                Log::info('No parallel statuses found to deactivate, skipping duplication', [
                    'cr_id' => $crId
                ]);
                DB::commit();
                return false; // Return false to indicate no action was needed
            }

            // Step 5: Retrieve the latest status record for the same CR
            $latestStatusRecord = ChangeRequestStatus::where('cr_id', $crId)
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if (!$latestStatusRecord) {
                throw new Exception("No status records found for CR: {$crId}");
            }

            Log::info('Found latest status record', [
                'cr_id' => $crId,
                'latest_record_id' => $latestStatusRecord->id,
                'latest_new_status_id' => $latestStatusRecord->new_status_id
            ]);

            // Step 6: Duplicate this latest record without changing any data, including new_status_id
            // Use direct DB insertion to completely avoid triggering model events and workflow logic
            $duplicatedRecord = $latestStatusRecord->replicate();
            $duplicatedRecord->active = self::ACTIVE_STATUS; // Only update active = 1
            $duplicatedRecord->created_at = now(); // Set new creation timestamp
            $duplicatedRecord->updated_at = null; // Keep consistent with model behavior
            
            // Ensure created_at is not null
            if (!$duplicatedRecord->created_at) {
                $duplicatedRecord->created_at = now();
            }
            
            // Use direct DB insert to avoid any model events or automatic workflow creation
            $insertData = $duplicatedRecord->toArray();
            unset($insertData['id']); // Remove ID to let database generate new one
            
            $newRecordId = DB::table('change_request_statuses')->insertGetId($insertData);

            Log::info('Created duplicated record with active=1', [
                'cr_id' => $crId,
                'original_record_id' => $latestStatusRecord->id,
                'duplicated_record_id' => $newRecordId,
                'new_status_id' => $duplicatedRecord->new_status_id,
                'active' => $duplicatedRecord->active
            ]);

            DB::commit();

            // Step 7: Clean up any parallel statuses that might have been created by workflow triggers
            // This ensures that even if something creates parallel statuses after our action,
            // we clean them up to maintain the correct state
            $cleanupCount = ChangeRequestStatus::where('cr_id', $crId)
                ->whereIn('new_status_id', $parallelStatusIds)
                ->where('active', '1')
                ->where('id', '>', $newRecordId) // Only clean up records created after our action
                ->update(['active' => self::INACTIVE_STATUS]);

            if ($cleanupCount > 0) {
                Log::info('Cleaned up parallel statuses created by workflow triggers', [
                    'cr_id' => $crId,
                    'cleanup_count' => $cleanupCount,
                    'parallel_status_ids' => $parallelStatusIds
                ]);
            }

            // Step 8: Also clean up any parallel statuses that were created in the same transaction
            // This handles the case where workflow creates parallel statuses before our action
            $sameTransactionCleanup = ChangeRequestStatus::where('cr_id', $crId)
                ->whereIn('new_status_id', $parallelStatusIds)
                ->where('active', '1')
                ->where('created_at', '>=', now()->subMinutes(5)) // Records created in the last 5 minutes
                ->where('id', '!=', $newRecordId) // Don't deactivate our own record
                ->update(['active' => self::COMPLETED_STATUS]); // Use COMPLETED_STATUS (2) instead of INACTIVE_STATUS (0)

            if ($sameTransactionCleanup > 0) {
                Log::info('Cleaned up parallel statuses created in same transaction', [
                    'cr_id' => $crId,
                    'cleanup_count' => $sameTransactionCleanup,
                    'parallel_status_ids' => $parallelStatusIds
                ]);
            }

            Log::info('Need Update action completed successfully', [
                'cr_id' => $crId,
                'deactivated_count' => $deactivatedCount,
                'duplicated_record_id' => $newRecordId
            ]);

            return true;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error processing Need Update action', [
                'cr_id' => $crId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    // Check if this is a transition from Pending CAB status to pending design status workflow 160
    private function isTransitionFromPendingCab(ChangeRequest $changeRequest, array $statusData): bool
    {
        if (self::$PENDING_CAB_STATUS_ID === null) {
            return false;
        }

        $workflow = NewWorkFlow::where('from_status_id', self::$PENDING_CAB_STATUS_ID)
            ->where('type_id', $changeRequest->workflow_type_id)
            ->where('workflow_type', '0') // Normal workflow (not reject)
            ->whereRaw('CAST(active AS CHAR) = ?', ['1'])
            ->first();

        if (!$workflow) {
            return false;
        }

        /*return isset($statusData['old_status_id']) &&
               (int)$statusData['old_status_id'] === self::$PENDING_CAB_STATUS_ID;*/
        return isset($statusData['new_status_id']) &&
            (int) $statusData['new_status_id'] === $workflow->id;
    }

    // Check if CR has reached Delivered status and fire event
    private function checkAndFireDeliveredEvent(ChangeRequest $changeRequest, array $statusData): void
    {
        $newWorkflowId = $statusData['new_status_id'] ?? null;
        if (!$newWorkflowId) {
            return; // no workflow do nothing
        }
        Log::info('Checking for delivered event', [
            'change_request_id' => $changeRequest->id,
            'new_workflow_id' => $newWorkflowId,
        ]);

        $workflow = NewWorkFlow::with('workflowstatus')->find($newWorkflowId);
        if (!$workflow) {
            return; // no workflow do nothing
        }

        foreach ($workflow->workflowstatus as $wfStatus) {
            if (in_array((int) $wfStatus->to_status_id, [self::$DELIVERED_STATUS_ID, self::$REJECTED_STATUS_ID], true)) {
                // Refresh the CR to ensure we have the latest data
                $changeRequest->refresh();

                Log::info('Firing CrDeliveredEvent', [
                    'cr_id' => $changeRequest->id,
                    'cr_no' => $changeRequest->cr_no,
                ]);
                // the status delivered fire the event
                event(new CrDeliveredEvent($changeRequest));

                return;
            }
        }
    }
}
