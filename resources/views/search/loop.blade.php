@if($cr)

    @php
        if ($cr->isOnHold()) {
            $cr_status_name = 'On Hold';
        } elseif ($cr->isDependencyHold()) {
            $blockingCrs = $cr->getBlockingCrNumbers();
            $crList = !empty($blockingCrs) ? ' (CR#' . implode(', CR#', $blockingCrs) . ')' : '';
            $cr_status_name = 'Design Estimation - Pending Dependency' . $crList;
        } else {
            $cr_status = $cr->getCurrentStatus()?->status;
            $cr_status_name = $cr_status?->status_name;
            if ($current_user_is_just_a_viewer) {
                $high_level_status_name = $cr_status?->high_level?->name;
                $cr_status_name = $high_level_status_name ?? $cr_status_name;
            }
        }
    @endphp

    <tr class="cr-row" data-toggle-details="1" data-cr-id="{{ $cr->id }}">
        <th scope="row" class="align-middle">
            <button type="button" class="btn btn-clean btn-icon btn-sm mr-2 js-toggle-cr-details" data-cr-id="{{ $cr->id }}"
                aria-expanded="false" title="Toggle row details">
                <i class="la la-angle-down"></i>
            </button>
            @if(in_array($cr->id, $crs_in_queues->toArray()))
                @if(!(($cr->workflow_type_id == 5) && (in_array($cr->Req_status()->latest('id')->first()?->new_status_id, [66, 67, 68, 69]))))
                    @can('Edit ChangeRequest')
                        @if($cr->getSetStatus()->count() > 0)
                            <a href='{{ url("$route") }}/{{ $cr->id }}/edit'>{{ $cr->cr_no }} </a>
                        @else
                            <a href='{{ url("$route") }}/{{ $cr->id }}'>{{ $cr->cr_no }} </a>
                        @endif
                    @endcan
                @endif
            @else
                <a href='{{ url("$route") }}/{{ $cr->id }}'>{{ $cr->cr_no }} </a>
            @endif
        </th>
        @if($cr->workflow_type_id == 5)
            <td>{{ $cr->title }} </td>
            <td>
                <span class="description-preview text-primary"
                    data-description="{{ e(json_encode($cr->description, JSON_UNESCAPED_UNICODE)) }}" role="button">
                    {{ \Illuminate\Support\Str::limit($cr->description, 50) }}
                </span>
            </td>
            <td>{{ $cr_status_name }}</td>
            <td>{{ $cr->application->name }}</td>
            <td>{{ @$cr->Release->name }}</td>
            <td>{{ @$cr->Release->go_live_planned_date }}</td>
            <td>{{ @$cr->Release->planned_start_iot_date }}</td>
            <td>{{ @$cr->Release->planned_end_iot_date }}</td>
            <td>{{ @$cr->Release->planned_start_e2e_date }}</td>
            <td>{{ @$cr->Release->planned_end_e2e_date }}</td>
            <td>{{ @$cr->Release->planned_start_uat_date }}</td>
            <td>{{ @$cr->Release->planned_end_uat_date }}</td>
            <td>{{ @$cr->Release->planned_start_smoke_test_date }}</td>
            <td>{{ @$cr->Release->planned_end_smoke_test_date }}</td>
        @else
            <td>{{ $cr->title }}</td>
            <td>
                <span class="description-preview text-primary"
                    data-description="{{ e(json_encode($cr->description, JSON_UNESCAPED_UNICODE)) }}" role="button">
                    {{ \Illuminate\Support\Str::limit($cr->description, 50) }}
                </span>
            </td>
            <td>{{ $cr_status_name }}</td>
            <td>{{ $cr->application->name }}</td>
            <td>{{ $cr->design_duration }}</td>
            <td>{{ $cr->start_design_time }}</td>
            <td>{{ $cr->end_design_time }}</td>
            <td>{{ $cr->develop_duration }}</td>
            <td>{{ $cr->start_develop_time }}</td>
            <td>{{ $cr->end_develop_time }}</td>
            <td>{{ $cr->test_duration }}</td>
            <td>{{ $cr->start_test_time }}</td>
            <td>{{ $cr->end_test_time }}</td>
            <td>{{ $cr->created_at }}</td>
            <td>{{ $cr->updated_at }}</td>
        @endif

        <td>
            <div class="d-inline-flex">
                @can('Show ChangeRequest')
                    <a href='{{ url("$route") }}/{{ $cr->id }}' class="btn btn-sm btn-clean btn-icon mr-2" title="Show details">
                        <span class="svg-icon svg-icon-md">
                            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24px"
                                height="24px" viewBox="0 0 24 24" version="1.1">
                                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                                    <rect x="0" y="0" width="24" height="24"></rect>
                                    <path
                                        d="M12,2 C6.477,2 2,6.477 2,12 C2,17.523 6.477,22 12,22 C17.523,22 22,17.523 22,12 C22,6.477 17.523,2 12,2 Z M12,19.5 C7.805,19.5 4.5,16.195 4.5,12 C4.5,7.805 7.805,4.5 12,4.5 C16.195,4.5 19.5,7.805 19.5,12 C19.5,16.195 16.195,19.5 12,19.5 Z M11,16 L13,16 L13,13 L11,13 L11,16 Z M11,11 L13,11 L13,8 L11,8 L11,11 Z"
                                        fill="#000000"></path>
                                </g>
                            </svg>
                        </span>
                    </a>
                @endcan
                @if(in_array($cr->id, $crs_in_queues->toArray()))
                    @if(!(($cr->workflow_type_id == 5) && (in_array($cr->Req_status()->latest('id')->first()?->new_status_id, [66, 67, 68, 69]))))
                        @can('Edit ChangeRequest')
                            @if($cr->getSetStatus()->count() > 0)
                                <a href='{{ url("$route") }}/{{ $cr->id }}/edit' class="btn btn-sm btn-clean btn-icon mr-2"
                                    title="Edit details">
                                    <span class="svg-icon svg-icon-md">
                                        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24px"
                                            height="24px" viewBox="0 0 24 24" version="1.1">
                                            <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                                                <rect x="0" y="0" width="24" height="24"></rect>
                                                <path
                                                    d="M8,17.9148182 L8,5.96685884 C8,5.56391781 8.16211443,5.17792052 8.44982609,4.89581508 L10.965708,2.42895648 C11.5426798,1.86322723 12.4640974,1.85620921 13.0496196,2.41308426 L15.5337377,4.77566479 C15.8314604,5.0588212 16,5.45170806 16,5.86258077 L16,17.9148182 C16,18.7432453 15.3284271,19.4148182 14.5,19.4148182 L9.5,19.4148182 C8.67157288,19.4148182 8,18.7432453 8,17.9148182 Z"
                                                    fill="#000000" fill-rule="nonzero"
                                                    transform="translate(12.000000, 10.707409) rotate(-135.000000) translate(-12.000000, -10.707409) ">
                                                </path>
                                                <rect fill="#000000" opacity="0.3" x="5" y="20" width="15" height="2" rx="1"></rect>
                                            </g>
                                        </svg>
                                    </span>
                                </a>
                            @endif
                        @endcan
                    @endif
                @endif
            </div>
        </td>
    </tr>
    @php
        $detailsColspan = 12;
        $statuses = $cr->getallCurrentStatus();
    @endphp
    <tr class="cr-details-row" data-cr-id="{{ $cr->id }}" style="display:none;">
        <td colspan="{{ $detailsColspan }}" class="p-0">
            <div style="background: #f8f9fb; padding: 1.25rem 1rem; border-top: 2px solid #e4e6ef;">

                <table class="table table-hover mb-0"
                    style="background: white; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                    <thead style="background: linear-gradient(to right, #f5f8fa, #e9ecef);">
                        <tr>
                            <th class="font-weight-bold text-uppercase"
                                style="font-size: 0.75rem; color: #5e6278; letter-spacing: 0.5px; padding: 1rem 1.25rem; border: none;">
                                <i class="la la-users mr-1"></i> Group
                            </th>
                            <th class="font-weight-bold text-uppercase"
                                style="font-size: 0.75rem; color: #5e6278; letter-spacing: 0.5px; padding: 1rem 1.25rem; border: none;">
                                <i class="la la-check-circle mr-1"></i> Status
                            </th>
                            <th class="font-weight-bold text-uppercase text-center"
                                style="font-size: 0.75rem; color: #5e6278; letter-spacing: 0.5px; padding: 1rem 1.25rem; border: none;">
                                <i class="la la-cog mr-1"></i> Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($statuses as $status)
                            @php
                                if ($cr->isOnHold()) {
                                    $cr_status_name = 'On Hold';
                                } elseif ($cr->isDependencyHold()) {
                                    $blockingCrs = $cr->getBlockingCrNumbers();
                                    $crList = !empty($blockingCrs) ? ' (CR#' . implode(', CR#', $blockingCrs) . ')' : '';
                                    $cr_status_name = 'Design Estimation - Pending Dependency' . $crList;
                                } else {
                                    $cr_status = $status->status;
                                    $cr_status_name = $cr_status?->status_name;
                                    if ($current_user_is_just_a_viewer) {
                                        $high_level_status_name = $cr_status?->high_level?->name;
                                        $cr_status_name = $high_level_status_name ?? $cr_status_name;
                                    }
                                }
                            @endphp
                            <tr style="border-bottom: 1px solid #f3f4f6; transition: all 0.2s;">
                                <td class="align-middle" style="padding: 1rem 1.25rem;">
                                    <span class="font-weight-bold text-dark" style="font-size: 0.9rem;">
                                        {{ $status->currentGroup->name ?? $status->status?->viewByGroupStatuses->first()?->group?->name ?? 'N/A' }}
                                    </span>
                                </td>
                                <td class="align-middle" style="padding: 1rem 1.25rem;">
                                    <span class="badge badge-primary"
                                        style="padding: 0.5rem 1rem; font-size: 0.8rem; font-weight: 500; border-radius: 4px;">
                                        ( {{ $cr_status_name ?? 'N/A' }} )
                                        @if($status->reference_group_id)
                                            tech team ({{ $status->referenceGroup->name ?? 'N/A' }})
                                        @elseif($status->reference_group_id)
                                            by ({{ $status->previousGroup->name ?? 'N/A' }})
                                        @endif
                                    </span>
                                </td>
                                <td class="align-middle text-center" style="padding: 1rem 1.25rem;">
                                    <div class="btn-group btn-group-sm" role="group">
                                        @can('Show ChangeRequest')
                                            <a href='{{ url("$route") }}/{{ $cr->id }}' class="btn btn-light-primary btn-sm"
                                                title="View" style="padding: 0.4rem 0.9rem; border-radius: 4px 0 0 4px;">
                                                <i class="la la-eye"></i> View
                                            </a>
                                        @endcan

                                        @if(in_array($cr->id, $crs_in_queues->toArray()))
                                            @if(!(($cr->workflow_type_id == 5) && (in_array($cr->Req_status()->latest('id')->first()?->new_status_id, [66, 67, 68, 69]))))
                                                @can('Edit ChangeRequest')
                                                    @if(in_array($status->new_status_id, $user_group->group_statuses->where('type', 2)->pluck('status_id')->toArray()))
                                                        @if(!$status->group_id || is_null($status->current_group_id) || $status->current_group_id == $user_group->id)
                                                            @if($cr->getSetStatus()->count() > 0)
                                                                <a href='{{ url("$route") }}/{{ $cr->id }}/edit?reference_status={{ $status->id }}'
                                                                    class="btn btn-light-success btn-sm" title="Edit"
                                                                    style="padding: 0.4rem 0.9rem; border-radius: 0 4px 4px 0;">
                                                                    <i class="la la-edit"></i> Edit
                                                                </a>
                                                            @endif
                                                        @endif

                                                    @endif
                                                @endcan
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center py-4" style="background: #fafbfc;">
                                    <i class="la la-info-circle text-muted" style="font-size: 2rem;"></i>
                                    <p class="text-muted mb-0 mt-2">No status records found</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </td>
    </tr>

    <style>
        /* Row hover effect */
        .cr-details-row tbody tr:hover {
            background-color: #f9fafb !important;
        }

        /* Button styles */
        .btn-light-primary {
            background-color: rgba(54, 153, 255, 0.1);
            color: #3699ff;
            border: 1px solid rgba(54, 153, 255, 0.2);
            transition: all 0.2s;
        }

        .btn-light-primary:hover {
            background-color: #3699ff;
            color: white;
            border-color: #3699ff;
        }

        .btn-light-success {
            background-color: rgba(30, 201, 111, 0.1);
            color: #1ec96f;
            border: 1px solid rgba(30, 201, 111, 0.2);
            transition: all 0.2s;
        }

        .btn-light-success:hover {
            background-color: #1ec96f;
            color: white;
            border-color: #1ec96f;
        }
    </style>

@endif