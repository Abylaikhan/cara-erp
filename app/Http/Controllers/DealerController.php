<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customers\DealerRequest;
use App\Models\SalesChannel;
use App\Services\DealerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** M08 «Покупатели», tab «Дилеры» — legacy addDealerRow/saveDealer/startEditCell/deleteDealer/importDealersCSV (L19590–19840). */
class DealerController extends Controller
{
    public function __construct(private readonly DealerService $service) {}

    public function store(DealerRequest $request): RedirectResponse
    {
        Gate::authorize('create', SalesChannel::class);
        $this->service->create($request->validated('name'), $request->validated('region'), $request->user());

        return back();
    }

    public function update(DealerRequest $request, string $code): RedirectResponse
    {
        $this->service->update($code, $request->changes(), (int) $request->validated('lock_version'), $request->user());

        return back();
    }

    public function destroy(Request $request, string $code): RedirectResponse
    {
        $this->service->deactivate($code, $request->user());

        return back();
    }

    /** The result is shown in the legacy result card (#dealer-import-result), not as form errors. */
    public function import(Request $request): RedirectResponse
    {
        Gate::authorize('create', SalesChannel::class);
        $file = $request->file('file');
        $result = match (true) {
            $file === null || ! $file->isValid() => ['text' => 'Файл не получен — выберите CSV ещё раз.', 'color' => 'red'],
            $file->getSize() > 1024 * 1024 => ['text' => 'Файл больше 1 МБ — разделите его на части.', 'color' => 'red'],
            default => $this->service->import((string) file_get_contents($file->getRealPath()), $request->user()),
        };

        return back()->with('result', $result);
    }
}
