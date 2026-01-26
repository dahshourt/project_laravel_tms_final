<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Change_request as ChangeRequest;
use App\Models\Change_request_statuse as ChangeRequestStatus;
use App\Models\Status;
use App\Models\User;
use App\Services\ChangeRequest\ChangeRequestStatusService;
use Illuminate\Support\Facades\Auth;

class AgreedScopeApprovalTransitionTest extends TestCase
{
    private ChangeRequestStatusService $service;
    private ChangeRequest $cr;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new ChangeRequestStatusService();
        
        // Create a test user
        $this->user = User::create([
            'name' => 'Test User',
            'user_name' => 'testuser' . time(),
            'email' => 'test' . time() . '@example.com',
            'password' => bcrypt('password'),
            'default_group' => 8
        ]);
        Auth::login($this->user);
        
        // Create a test change request
        $this->cr = ChangeRequest::create([
            'cr_no' => time(),
            'title' => 'Test CR for Agreed Scope Approval',
            'description' => 'Test description',
            'active' => '1'
        ]);
    }

    /** @test */
    public function it_deactivates_other_approval_statuses_when_transitioning_to_pending_create_agreed_scope()
    {
        // Get the status IDs
        $pendingCreateAgreedScopeId = $this->getStatusId('Pending Create Agreed Scope');
        $pendingAgreedScopeSAId = $this->getStatusId('Pending Agreed Scope Approval-SA');
        $pendingAgreedScopeVendorId = $this->getStatusId('Pending Agreed Scope Approval-Vendor');
        $pendingAgreedScopeBusinessId = $this->getStatusId('Pending Agreed Scope Approval-Business');
        
        // Skip test if statuses don't exist
        if (!$pendingCreateAgreedScopeId) {
            $this->markTestSkipped('Pending Create Agreed Scope status not found in database');
        }

        // Create active approval statuses for the CR
        $approvalStatuses = [];
        
        if ($pendingAgreedScopeSAId) {
            $approvalStatuses[] = ChangeRequestStatus::create([
                'cr_id' => $this->cr->id,
                'old_status_id' => 1,
                'new_status_id' => $pendingAgreedScopeSAId,
                'user_id' => $this->user->id,
                'active' => '1'
            ]);
        }
        
        if ($pendingAgreedScopeVendorId) {
            $approvalStatuses[] = ChangeRequestStatus::create([
                'cr_id' => $this->cr->id,
                'old_status_id' => 1,
                'new_status_id' => $pendingAgreedScopeVendorId,
                'user_id' => $this->user->id,
                'active' => '1'
            ]);
        }
        
        if ($pendingAgreedScopeBusinessId) {
            $approvalStatuses[] = ChangeRequestStatus::create([
                'cr_id' => $this->cr->id,
                'old_status_id' => 1,
                'new_status_id' => $pendingAgreedScopeBusinessId,
                'user_id' => $this->user->id,
                'active' => '1'
            ]);
        }

        // Verify all approval statuses are initially active
        foreach ($approvalStatuses as $status) {
            $this->assertEquals('1', $status->fresh()->active, 
                "Status {$status->new_status_id} should be active initially");
        }

        // Simulate the transition to "Pending Create Agreed Scope"
        $statusData = [
            'new_status_id' => $pendingCreateAgreedScopeId,
            'old_status_id' => 1,
            'new_workflow_id' => $pendingCreateAgreedScopeId
        ];

        // Call the transition handler method directly
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('handleAgreedScopeApprovalTransition');
        $method->setAccessible(true);
        $method->invoke($this->service, $this->cr->id, $statusData);

        // Verify all approval statuses are now deactivated
        foreach ($approvalStatuses as $status) {
            $status->refresh();
            $this->assertEquals('0', $status->active, 
                "Status {$status->new_status_id} should be deactivated after transition");
        }

        // Verify a new active "Pending Create Agreed Scope" status was created
        $newStatus = ChangeRequestStatus::where('cr_id', $this->cr->id)
            ->where('new_status_id', $pendingCreateAgreedScopeId)
            ->where('active', '1')
            ->first();

        $this->assertNotNull($newStatus, 'New active Pending Create Agreed Scope status should be created');
        $this->assertEquals($this->user->id, $newStatus->user_id);
    }

    private function getStatusId(string $statusName): ?int
    {
        $status = Status::where('status_name', $statusName)
            ->where('active', '1')
            ->first();
        
        return $status ? $status->id : null;
    }

    protected function tearDown(): void
    {
        // Clean up created records
        ChangeRequestStatus::where('cr_id', $this->cr->id)->delete();
        $this->cr->delete();
        $this->user->delete();
        
        parent::tearDown();
    }
}
