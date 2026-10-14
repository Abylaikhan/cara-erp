<?php

namespace App\Services\Esf;

use App\Models\IncomingInvoice;
use App\Models\IncomingInvoiceItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Import of ESF XML (legacy handleEsfXmlFiles → renderEsfXmlImportPreview → confirmEsfXmlImport, L12107–12220).
 * The files are parsed on the server; the parsed invoices wait in the cache for the user's choice, so the confirmation
 * imports exactly what was previewed. Each invoice is stored with its lines in one transaction; a registration number
 * is imported once.
 */
class EsfImportService
{
    private const TTL_MINUTES = 60;

    public function __construct(private readonly EsfXmlParser $parser) {}

    /**
     * @param  array<string, string>  $files  file name => XML
     * @return array{token: string, total: int, ok: int, rows: list<array<string, mixed>>}
     */
    public function preview(array $files, User $by): array
    {
        $parsed = [];
        foreach ($files as $name => $xml) {
            array_push($parsed, ...$this->parser->parse($xml, $name));
        }
        $existing = IncomingInvoice::query()->get(['id', 'reg_num', 'date', 'num', 'total_with_nds', 'supplier_name']);
        $regNums = $existing->pluck('reg_num')->filter()->flip();
        $rows = [];
        foreach ($parsed as $i => $p) {
            if (! $p['ok']) {
                $rows[] = ['index' => $i, 'ok' => false, 'fileName' => $p['fileName'],
                    'error' => $p['error'] ?? 'не удалось найти обязательные поля (дата/поставщик/строки товаров) — формат тегов в файле отличается от ожидаемого'];

                continue;
            }
            $dupByReg = ($p['regNum'] ?? '') !== '' && isset($regNums[$p['regNum']]);
            $dupByFields = ! $dupByReg && $this->sameByFields($existing, $p);
            $rows[] = [
                'index' => $i, 'ok' => true, 'fileName' => $p['fileName'],
                'date' => $p['date'], 'num' => $p['num'], 'supplier_name' => $p['supplier_name'], 'supplier_bin' => $p['supplier_bin'],
                'lines' => count($p['items']),
                'total_amount' => (float) $p['total_amount'], 'total_nds' => (float) $p['total_nds'], 'total_with_nds' => (float) $p['total_with_nds'],
                'duplicate' => $dupByReg ? 'совпал рег.номер' : ($dupByFields ? 'совпали дата + номер + поставщик + сумма' : null),
            ];
        }
        $token = Str::random(32);
        Cache::put($this->key($by, $token), $parsed, now()->addMinutes(self::TTL_MINUTES));

        return ['token' => $token, 'total' => count($parsed), 'ok' => count(array_filter($parsed, fn ($p) => $p['ok'])), 'rows' => $rows];
    }

    /**
     * @param  list<int>  $selected
     * @return array{created: int, failed: list<string>}
     */
    public function import(string $token, array $selected, User $by): array
    {
        $parsed = Cache::pull($this->key($by, $token));
        if ($parsed === null) {
            throw ValidationException::withMessages(['token' => 'Предпросмотр устарел — выберите файлы ещё раз.']);
        }
        $created = 0;
        $failed = [];
        foreach (array_unique($selected) as $i) {
            $p = $parsed[$i] ?? null;
            if ($p === null || ! $p['ok']) {
                continue;
            }
            try {
                DB::transaction(fn () => $this->store($p, $by));
                $created++;
            } catch (UniqueConstraintViolationException) {
                $failed[] = "{$p['fileName']}: ЭСФ с рег.номером {$p['regNum']} уже есть в базе";
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /** Legacy confirmEsfXmlImport for one invoice: the supplier by BIN or name (created or given its BIN), the invoice, its lines. */
    private function store(array $p, User $by): IncomingInvoice
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('suppliers')]);
        $supplier = Supplier::findForName(Supplier::query()->active()->orderBy('name')->orderBy('id')->get(), $p['supplier_name'], $p['supplier_bin']);
        if ($supplier === null) {
            $supplier = new Supplier(['name' => $p['supplier_name'], 'bin' => $p['supplier_bin'] ?: null, 'status' => 'active']);
            $supplier->forceFill(['created_by_id' => $by->id, 'created_by_name' => $by->name, 'updated_by_id' => $by->id, 'updated_by_name' => $by->name])->save();
            Audit::record('supplier.created', 'supplier', $supplier->id, ['name' => $supplier->name, 'bin' => $supplier->bin, 'source' => 'esf'], $by);
        } elseif (($p['supplier_bin'] ?? '') !== '' && blank($supplier->bin)) {
            $supplier->forceFill(['bin' => $p['supplier_bin'], 'updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $supplier->lock_version++;
            $supplier->save();
            Audit::record('supplier.updated', 'supplier', $supplier->id, ['bin' => ['from' => null, 'to' => $p['supplier_bin']], 'source' => 'esf'], $by);
        }
        $round = fn (string $v, int $scale = 2) => str_starts_with($v, '-') ? bcsub($v, '0.'.str_repeat('0', $scale).'5', $scale) : bcadd($v, '0.'.str_repeat('0', $scale).'5', $scale);
        $invoice = new IncomingInvoice([
            'num' => $p['num'] ?: null, 'reg_num' => $p['regNum'] ?: null, 'date' => $p['date'], 'status' => $p['status'],
            'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'supplier_bin' => $supplier->bin ?: ($p['supplier_bin'] ?: null),
            'total_amount' => $round($p['total_amount']), 'total_nds' => $round($p['total_nds']), 'total_with_nds' => $round($p['total_with_nds']),
            'source_file' => mb_substr($p['fileName'], 0, 255),
        ]);
        $invoice->forceFill(['created_by_id' => $by->id, 'created_by_name' => $by->name])->save();
        foreach ($p['items'] as $n => $it) {
            IncomingInvoiceItem::query()->create([
                'invoice_id' => $invoice->id, 'line_no' => $n + 1, 'description' => $it['description'],
                'qty' => $round($it['qty'], 3), 'unit_price' => $round($it['unit_price']), 'amount' => $round($it['amount']),
                'nds_rate' => $round($it['nds_rate']), 'nds_amount' => $round($it['nds_amount']), 'total' => $round($it['total']),
            ]);
        }
        Audit::record('esf.imported', 'incoming_invoice', $invoice->id, ['reg_num' => $invoice->reg_num, 'num' => $invoice->num, 'lines' => count($p['items']), 'file' => $p['fileName']], $by);

        return $invoice;
    }

    /** Legacy findDupByFields: same date and number, sum within 1 tenge, the same supplier by normalized name. */
    private function sameByFields($existing, array $p): bool
    {
        $b = Supplier::normalizedName($p['supplier_name']);

        return $existing->contains(function (IncomingInvoice $inv) use ($p, $b) {
            if ($inv->date->format('Y-m-d') !== $p['date'] || trim((string) $inv->num) !== trim((string) $p['num'])) {
                return false;
            }
            if (bccomp(ltrim(bcsub((string) $inv->total_with_nds, $p['total_with_nds'], 10), '-'), '1', 10) > 0) {
                return false;
            }
            $a = Supplier::normalizedName($inv->supplier_name);

            return $a !== '' && $b !== '' && ($a === $b || str_contains($a, $b) || str_contains($b, $a));
        });
    }

    private function key(User $by, string $token): string
    {
        return "esf-import:{$by->id}:{$token}";
    }
}
