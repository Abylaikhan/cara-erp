<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\SalesChannel;
use App\Models\User;
use App\Support\Audit;
use App\Support\LegacyCsv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Dealers of the «Покупатели» page (M08). Legacy keeps them in page memory and loses every change on reload (QA-042);
 * here a dealer is a sales channel of the group «dealer», so its orders, accounts and reports share one identity.
 */
class DealerService
{
    public const STALE = 'Дилер изменён другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Дилер не найден — обновите страницу.';

    public const IN_USE = 'Дилера нельзя удалить: к нему привязаны роль, учётные записи, покупатели или заказы.';

    private const FIELDS = ['name', 'region'];

    /** CSV columns of legacy importDealersCSV (L19818); status is read there but never shown, so it is not stored. */
    private const NAME_KEYS = ['название', 'наименование', 'дилер', 'контрагент'];

    private const EXTRA_KEYS = ['region' => ['регион', 'город'], 'orders' => ['кол-во заказов', 'заказов'], 'turnover' => ['оборот за год', 'оборот', 'сумма']];

    public function create(string $name, ?string $region, User $by): SalesChannel
    {
        return DB::transaction(function () use ($name, $region, $by) {
            $this->lockDirectory();

            return $this->insert($name, $region, $by, now());
        });
    }

    /** @param array{name?: string, region?: string|null} $changes */
    public function update(string $code, array $changes, int $lockVersion, User $by): SalesChannel
    {
        return DB::transaction(function () use ($code, $changes, $lockVersion, $by) {
            $dealer = $this->lockedDealer($code, $by);
            if ($dealer->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => self::STALE]);
            }
            $before = $dealer->only(self::FIELDS);
            $dealer->forceFill(array_intersect_key($changes, array_flip(self::FIELDS)));
            $diff = collect($dealer->getDirty())->map(fn ($new, $key) => ['from' => $before[$key] ?? null, 'to' => $new])->all();
            if ($diff) {
                $dealer->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
                $dealer->lock_version++;
                $dealer->save();
                Audit::record('channel.updated', 'sales_channel', $dealer->code, $diff, $by);
            }

            return $dealer;
        });
    }

    /** Legacy deleteDealer never worked (the id is passed as a string); here deleting deactivates an unused dealer. */
    public function deactivate(string $code, User $by): SalesChannel
    {
        return DB::transaction(function () use ($code, $by) {
            $dealer = $this->lockedDealer($code, $by);
            $inUse = collect(config('erp.roles'))->contains(fn (array $role) => ($role['channel'] ?? null) === $code)
                || User::query()->where('channel', $code)->exists()
                || Customer::query()->where('channel', $code)->exists()
                || Order::query()->where('channel', $code)->exists();
            if ($inUse) {
                throw ValidationException::withMessages(['dealer' => self::IN_USE]);
            }
            $dealer->forceFill(['active' => false, 'deactivated_at' => now(), 'updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $dealer->lock_version++;
            $dealer->save();
            Audit::record('channel.deactivated', 'sales_channel', $dealer->code, ['name' => $dealer->name], $by);

            return $dealer;
        });
    }

    /**
     * Legacy CSV import: same columns and messages. The whole file is checked before anything is written; a dealer whose
     * name is already in the list is not added twice, so a repeated upload changes nothing (DATA-01).
     *
     * @return array{text: string, color: 'green'|'red'}
     */
    public function import(string $text, User $by): array
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return $this->failure('Файл не в кодировке UTF-8. Сохраните CSV в UTF-8 (как шаблон) и повторите.');
        }
        $lines = LegacyCsv::lines($text);
        if (count($lines) < 2) {
            return $this->failure('Файл пуст или содержит только заголовок.');
        }
        $delimiter = LegacyCsv::delimiter($lines[0]);
        $header = array_map('mb_strtolower', LegacyCsv::parseLine($lines[0], $delimiter));
        $idx = ['name' => LegacyCsv::findColumn($header, self::NAME_KEYS)];
        foreach (self::EXTRA_KEYS as $key => $keys) {
            $idx[$key] = LegacyCsv::findColumn($header, $keys);
        }
        if ($idx['name'] === -1) {
            return $this->failure('Не найдена колонка с названием. Скачайте шаблон CSV для примера.');
        }

        $rows = [];
        $skipped = 0;
        $problems = [];
        $numbersIgnored = false;
        foreach (array_slice($lines, 1) as $i => $line) {
            $cells = LegacyCsv::parseLine($line, $delimiter);
            $get = fn (string $key) => $idx[$key] > -1 ? ($cells[$idx[$key]] ?? '') : '';
            $name = $get('name');
            if ($name === '') {
                $skipped++;

                continue;
            }
            $lineNo = $i + 2;
            $region = in_array($get('region'), ['', '—'], true) ? null : $get('region');
            if (mb_strlen($name) > 100 || mb_strlen((string) $region) > 100) {
                $problems[] = "строка {$lineNo}: название или регион длиннее 100 символов";
            }
            $numbersIgnored = $numbersIgnored || preg_match('/[1-9]/', $get('orders').$get('turnover')) === 1;
            $rows[] = ['name' => $name, 'region' => $region];
        }
        if ($problems) {
            return $this->failure('Импорт не выполнен: '.implode('; ', array_slice($problems, 0, 5)).'.');
        }

        [$added, $existing] = DB::transaction(function () use ($rows, $by) {
            $this->lockDirectory();
            $known = SalesChannel::query()->dealers()->pluck('name')->map(fn (string $n) => mb_strtolower($n))->flip()->all();
            $now = now();
            $added = 0;
            $existing = 0;
            foreach ($rows as $row) {
                $key = mb_strtolower($row['name']);
                if (isset($known[$key])) {
                    $existing++;

                    continue;
                }
                $known[$key] = true;
                $this->insert($row['name'], $row['region'], $by, $now);
                $added++;
            }
            Audit::record('channel.imported', 'sales_channel', null, ['added' => $added, 'existing' => $existing], $by);

            return [$added, $existing];
        });

        $text = LegacyCsv::importedText($added);
        if ($skipped > 0) {
            $text .= ", пропущено строк без названия: {$skipped}";
        }
        if ($existing > 0) {
            $text .= ", уже есть в списке: {$existing}";
        }
        if ($numbersIgnored) {
            $text .= '. Кол-во заказов и оборот считаются из заказов и из файла не берутся';
        }

        return ['text' => $text, 'color' => 'green'];
    }

    private function insert(string $name, ?string $region, User $by, Carbon $at): SalesChannel
    {
        $dealer = new SalesChannel([
            'code' => $this->nextCode(),
            'name' => $name,
            'icon' => '📍',
            'channel_group' => SalesChannel::DEALER,
            'region' => $region,
            'sort_order' => (int) SalesChannel::query()->max('sort_order') + 1,
            'active' => true,
        ]);
        $dealer->forceFill([
            'created_by_id' => $by->id, 'created_by_name' => $by->name,
            'updated_by_id' => $by->id, 'updated_by_name' => $by->name,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $dealer->timestamps = false; // one moment for a whole import keeps the file order in the list
        $dealer->save();
        Audit::record('channel.created', 'sales_channel', $dealer->code, $dealer->only(['name', 'region', 'channel_group']), $by);

        return $dealer;
    }

    /** New dealer channels are numbered: dealer_1, dealer_2… (the eight legacy codes stay as they are). */
    private function nextCode(): string
    {
        $max = SalesChannel::query()->where('code', '~', '^dealer_[0-9]+$')->pluck('code')
            ->map(fn (string $c) => (int) substr($c, strlen('dealer_')))->max() ?? 0;

        return 'dealer_'.($max + 1);
    }

    /** Serialises additions: codes and sort order are computed from the directory. */
    private function lockDirectory(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('sales_channels')]);
    }

    private function lockedDealer(string $code, User $by): SalesChannel
    {
        $dealer = SalesChannel::query()->dealers()->whereKey($code)->lockForUpdate()->first();
        if ($dealer === null || ! Gate::forUser($by)->allows('update', $dealer)) {
            throw ValidationException::withMessages(['dealer' => self::NOT_FOUND]);
        }

        return $dealer;
    }

    /** @return array{text: string, color: 'red'} */
    private function failure(string $text): array
    {
        return ['text' => $text, 'color' => 'red'];
    }
}
