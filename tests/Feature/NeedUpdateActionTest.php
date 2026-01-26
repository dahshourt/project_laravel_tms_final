<?php

namespace Tests\Feature;

use App\Models\Change_request;
use App\Models\Change_request_statuse;
use App\Models\Status;
use App\Models\User;
use App\Services\ChangeRequest\ChangeRequestStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NeedUpdateActionTest extends TestCase
{
    use RefreshDatabase;

    private ChangeRequestStatusService $statusService;
    private User $user;
    private Change_request $changeRequest;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->statusService = app(ChangeRequestStatusService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        
        // Create a change request
        $this->changeRequest = Change_request::factory()->create([
            'cr_no' => 'CR000001',
            'title' => 'Test Change Request'
        ]);
    }

    /**
     * Test the Need Update action with parallel approval statuses
     */
    public function test_need_update_action_with_parallel_statuses()
    {
        // Create the required statuses
        $statuses = [
            'Pending Agreed Scope Approval-SA' => Status::factory()->create(['status_name' => 'Pending Agreed Scope Approval-SA', 'active' => '1']),
            'Pending Agreed Scope Approval-Vendor' => Status::factory()->create(['status_name' => 'Pending Agreed Scope Approval-Vendor', 'active' => '1']),
            'Pending Agreed Scope Approval-Business' => Status::factory()->create(['status_name' => 'Pending Agreed Scope Approval-Business', 'active' => '1']),
            'Request Draft CR Doc' => Status::factory()->create(['status_name' => 'Request Draft CR Doc', 'active' => '1']),
            'Some Other Status' => Status::factory()->create(['status_name' => 'Some Other Status', 'active' => '1']),
        ];

        // Create active status records for parallel approvals
        foreach (['Pending Agreed Scope Approval-SA', 'Pending Agreed Scope Approval-Vendor', 'Request Draft CR Doc'] as $statusName) {
            Change_request_statuse::create([
                'cr_id' => $this->changeRequest->id,
                'old_status_id' => $statuses['Some Other Status']->id,
                'new_status_id' => $statuses[$statusName]->id,
                'user_id' => $this->user->id,
                'active' => '1',
                'created_at' => now()->subMinutes(rand(1, 60)),
            ]);
        }

        // Create the latest status record (should be duplicated)
        $latestStatus = Change_request_statuse::create([
            'cr_id' => $this->changeRequest->id,
            'old_status_id' => $statuses['Some Other Status']->id,
            'new_status_id' => $statuses['Some Other Status']->id,
            'user_id' => $this->user->id,
            'active' => '0', // inactive
            'created_at' => now()->subMinutes(5),
        ]);

        // Verify initial state
        $this->assertEquals(4, Change_request_statuse::where('cr_id', $this->changeRequest->id)->count());
        $this->assertEquals(3, Change_request_statuse::where('cr_id', $this->changeRequest->id)->where('active', '1')->count());

        // Execute the Need Update action
        $result = $this->statusService->handleNeedUpdateAction($this->changeRequest->id);

        // Assert the action was successful
        $this->assertTrue($result);

        // Verify the parallel statuses were deactivated
        $parallelStatusIds = [
            $statuses['Pending Agreed Scope Approval-SA']->id,
            $statuses['Pending Agreed Scope Approval-Vendor']->id,
            $statuses['Pending Agreed Scope Approval-Business']->id,
            $statuses['Request Draft CR Doc']->id,
        ];

        foreach ($parallelStatusIds as $statusId) {
            $this->assertEquals(0, Change_request_statuse::where('cr_id', $this->changeRequest->id)
                ->where('new_status_id', $statusId)
                ->where('active', '1')
                ->count(), "Status ID {$statusId} should be deactivated");
        }

        // Verify a new record was created (duplicated from latest)
        $this->assertEquals(5, Change_request_statuse::where('cr_id', $this->changeRequest->id)->count());
        
        $duplicatedRecord = Change_request_statuse::where('cr_id', $this->changeRequest->id)
            ->where('active', '1')
            ->where('new_status_id', $latestStatus->new_status_id)
            ->first();

        $this->assertNotNull($duplicatedRecord);
        $this->assertEquals($latestStatus->old_status_id, $duplicatedRecord->old_status_id);
        $this->assertEquals($latestStatus->new_status_id, $duplicatedRecord->new_status_id);
        $this->assertEquals($latestStatus->user_id, $duplicatedRecord->user_id);
        $this->assertEquals('1', $duplicatedRecord->active);
        $this->assertGreaterThan($latestStatus->created_at, $duplicatedRecord->created_at);
    }

    /**
     * Test the Need Update action when no parallel statuses exist
     */
    public function test_need_update_action_with_no_parallel_statuses()
    {
        // Create only non-parallel status records
        $status = Status::factory()->create(['status_name' => 'Some Other Status', 'active' => '1']);
        
        Change_request_statuse::create([
            'cr_id' => $this->changeRequest->id,
            'old_status_id' => $status->id,
            'new_status_id' => $status->id,
            'user_id' => $this->user->id,
            'active' => '1',
        ]);

        // Execute the Need Update action
        $result = $this->statusService->handleNeedUpdateAction($this->changeRequest->id);

        // Should return false since no parallel statuses were found
        $this->assertFalse($result);

        // Verify no records were deactivated
        $this->assertEquals(1, Change_request_statuse::where('cr_id', $this->changeRequest->id)
            ->where('active', '1')
            ->count());
    }

    /**
     * Test the Need Update action with non-existent CR
     */
    public function test_need_update_action_with_non_existent_cr()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Change Request not found: 99999');

        $this->statusService->handleNeedUpdateAction(99999);
    }

    /**
     * Test the API endpoint for Need Update action
     */
    public function test_need_update_api_endpoint()
    {
        // Create required statuses
        $status = Status::factory()->create(['status_name' => 'Some Other Status', 'active' => '1']);
        
        Change_request_statuse::create([
            'cr_id' => $this->changeRequest->id,
            'old_status_id' => $status->id,
            'new_status_id' => $status->id,
            'user_id' => $this->user->id,
            'active' => '1',
        ]);

        // Call the API endpoint
        $response = $this->postJson('/change-requests/need-update', [
            'cr_id' => $this->changeRequest->id
        ]);

        // Should return success response (even if no parallel statuses found)
        $response->assertStatus(200)
            ->assertJson([
                'success' => false,
                'message' => 'No parallel approval statuses found to deactivate.',
                'cr_id' => $this->changeRequest->id
            ]);
    }

    /**
     * Test the API endpoint with invalid CR ID
     */
    public function test_need_update_api_endpoint_with_invalid_cr()
    {
        $response = $this->postJson('/change-requests/need-update', [
            'cr_id' => 99999
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cr_id']);
    }
}
