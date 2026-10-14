<?php

namespace App\Http\Controllers;

use App\Http\Requests\Crm\CrmTaskRequest;
use App\Models\CrmTask;
use App\Services\CrmTaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** M10 CRM tab «Задачи» — legacy saveTask/toggleTask/deleteTask (L29639–29826). */
class CrmTaskController extends Controller
{
    public function __construct(private readonly CrmTaskService $service) {}

    public function store(CrmTaskRequest $request): RedirectResponse
    {
        Gate::authorize('create', CrmTask::class);
        $this->service->create($request->fields(), $request->user());

        return back();
    }

    public function update(CrmTaskRequest $request, int $task): RedirectResponse
    {
        $this->service->update($task, $request->fields(), (int) $request->validated('lock_version'), $request->user());

        return back();
    }

    public function toggle(Request $request, int $task): RedirectResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);
        $this->service->toggle($task, (int) $data['lock_version'], $request->user());

        return back();
    }

    public function destroy(Request $request, int $task): RedirectResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);
        $this->service->deactivate($task, (int) $data['lock_version'], $request->user());

        return back();
    }
}
