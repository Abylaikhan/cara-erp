<?php

namespace App\Http\Controllers;

use App\Models\SalonWorksheetRow;
use App\Services\SalonWorksheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** «Деньги» → «Рабочий лист салонов»: rows of the salon cash books (the list comes with the money page, MoneyController). */
class SalonWorksheetController extends Controller
{
    public function __construct(private readonly SalonWorksheetService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['salon' => ['required', Rule::in(SalonWorksheetRow::SALONS)]]);
        $this->service->add($data['salon'], $request->user());

        return back();
    }

    public function update(Request $request, int $row): RedirectResponse
    {
        $data = $request->validate([
            'field' => ['required', Rule::in([...SalonWorksheetRow::DATE_FIELDS, ...SalonWorksheetRow::NUMBER_FIELDS, ...SalonWorksheetRow::TEXT_FIELDS])],
            'value' => ['nullable', 'string', 'max:5000'],
        ]);
        $this->service->update($row, $data['field'], $data['value'] ?? null, $request->user());

        return back();
    }

    public function destroy(Request $request, int $row): RedirectResponse
    {
        $this->service->delete($row, $request->user());

        return back();
    }

    public function clear(Request $request): RedirectResponse
    {
        $data = $request->validate(['salon' => ['required', Rule::in(SalonWorksheetRow::SALONS)]]);
        $this->service->clear($data['salon'], $request->user());

        return back();
    }
}
