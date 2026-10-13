<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesChannel;
use App\Models\User;
use App\Support\Audit;
use App\Support\ChannelScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Customer operations (M08): transactions, row locks, lock_version against lost updates, audit trail. */
class CustomerService
{
    public const STALE = 'Покупатель изменён другим пользователем — обновите страницу и повторите.';

    private const FIELDS = ['name', 'type', 'inn', 'phone'];

    /**
     * Legacy savePerson writes the channel of the author (getChannelFilter() || 'office').
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $by): Customer
    {
        $channel = ChannelScope::filterFor($by) ?? SalesChannel::OFFICE;
        if ($channel === ChannelScope::SALONS) {
            // Legacy would store the marker itself; a customer belongs to one salon.
            throw ValidationException::withMessages(['channel' => 'Покупателя салона заводит сотрудник этого салона.']);
        }

        return DB::transaction(function () use ($data, $channel, $by) {
            $customer = new Customer(array_intersect_key($data, array_flip(self::FIELDS)));
            $customer->forceFill([
                'channel' => $channel,
                'created_by_id' => $by->id, 'created_by_name' => $by->name,
                'updated_by_id' => $by->id, 'updated_by_name' => $by->name,
            ])->save();
            Audit::record('customer.created', 'customer', $customer->id, $customer->only([...self::FIELDS, 'channel']), $by);

            return $customer;
        });
    }

    /**
     * Updates exactly the chosen customer by its id (legacy patched by the new name: QA-041).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data, int $lockVersion, User $by): Customer
    {
        return DB::transaction(function () use ($id, $data, $lockVersion, $by) {
            $customer = Customer::query()->whereKey($id)->lockForUpdate()->first();
            if ($customer === null || ! Gate::forUser($by)->allows('update', $customer)) {
                // Another channel's customer is reported exactly like a missing one (AC-11).
                throw ValidationException::withMessages(['customer' => 'Покупатель не найден — обновите страницу.']);
            }
            if ($customer->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => self::STALE]);
            }
            $before = $customer->only(self::FIELDS);
            $customer->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            $changes = collect($customer->getDirty())->map(fn ($new, $key) => ['from' => $before[$key] ?? null, 'to' => $new])->all();
            if ($changes) {
                $customer->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
                $customer->lock_version++;
                $customer->save();
                Audit::record('customer.updated', 'customer', $customer->id, $changes, $by);
            }

            return $customer;
        });
    }
}
