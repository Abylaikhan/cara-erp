<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Material requests of «Закупки» (M04, legacy saveMaterialRequestManual/updateMaterialRequestStatus). */
class MaterialRequestService
{
    public const STALE = 'Заявка изменена другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Заявка не найдена — обновите страницу.';

    public const WRONG_STEP = 'Заявка уже в другом статусе — обновите страницу.';

    /**
     * Legacy «+ Заявка вручную»: the comment goes to «Заказ / изделие» as in legacy. «Заявил» is the author — legacy never
     * filled it, so the column always showed «—».
     *
     * @param  array{material_name: string, qty: string, unit: ?string, item_name: ?string}  $data
     */
    public function create(array $data, User $by): MaterialRequest
    {
        return DB::transaction(function () use ($data, $by) {
            $request = new MaterialRequest($data + ['status' => 'requested', 'requested_by' => $by->name]);
            $request->forceFill(['created_by_id' => $by->id, 'created_by_name' => $by->name, 'updated_by_id' => $by->id, 'updated_by_name' => $by->name])->save();
            Audit::record('material_request.created', 'material_request', $request->id, $request->only(['material_name', 'qty', 'unit', 'item_name', 'status']), $by);

            return $request;
        });
    }

    /** The next step only: «Взять в закупку», then «Отметить пришедшим». Stock is not moved. */
    public function advance(int $id, string $status, int $lockVersion, User $by): MaterialRequest
    {
        return DB::transaction(function () use ($id, $status, $lockVersion, $by) {
            $request = MaterialRequest::query()->whereKey($id)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['request' => self::NOT_FOUND]);
            if ($request->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => self::STALE]);
            }
            if ((MaterialRequest::NEXT[$request->status] ?? null) !== $status) {
                throw ValidationException::withMessages(['status' => self::WRONG_STEP]);
            }
            $from = $request->status;
            $request->status = $status;
            $request->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $request->lock_version++;
            $request->save();
            Audit::record('material_request.status_changed', 'material_request', $request->id, ['status' => ['from' => $from, 'to' => $status]], $by);

            return $request;
        });
    }
}
