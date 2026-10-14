<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Orders of M09. Part 1 is view-only; the one action is legacy markOrderDone with its rule checked by the server. */
class OrderService
{
    public const STALE = 'Заказ изменён другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Заказ не найден — обновите страницу.';

    public const NOT_REALIZED = 'Завершить вручную можно только заказ дилера, реализованный не меньше чем на 99 % суммы.';

    /**
     * Legacy markOrderDone: a dealer order that is neither done nor cancelled and whose realizations reach 99 % of its total
     * may be marked done. Legacy only checked this to show the button; here the server checks it.
     */
    public function complete(int $id, int $lockVersion, User $by): Order
    {
        return DB::transaction(function () use ($id, $lockVersion, $by) {
            $order = Order::query()->whereKey($id)->lockForUpdate()->first();
            if ($order === null || ! Gate::forUser($by)->allows('update', $order)) {
                throw ValidationException::withMessages(['order' => self::NOT_FOUND]);
            }
            if ($order->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => self::STALE]);
            }
            if (! self::canComplete($order, self::total($order), self::realized($order))) {
                throw ValidationException::withMessages(['order' => self::NOT_REALIZED]);
            }
            $from = $order->status;
            $order->status = 'done';
            $order->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $order->lock_version++;
            $order->save();
            Audit::record('order.completed', 'order', $order->id, ['status' => ['from' => $from, 'to' => 'done']], $by);

            return $order;
        });
    }

    public static function canComplete(Order $order, string $total, string $realized): bool
    {
        return str_starts_with($order->channel, 'dealer_') && ! in_array($order->status, ['done', 'cancelled'], true)
            && bccomp($total, '0', 2) > 0 && bccomp($realized, bcmul($total, Order::REALIZED_SHARE, 4), 4) >= 0;
    }

    public static function total(Order $order): string
    {
        return (string) (DB::table('order_items')->where('order_id', $order->id)->selectRaw('coalesce(sum(qty * price), 0) AS total')->value('total') ?? '0');
    }

    public static function realized(Order $order): string
    {
        return (string) (DB::table('realizations')->where('order_id', $order->id)->selectRaw('coalesce(sum(revenue), 0) AS total')->value('total') ?? '0');
    }
}
