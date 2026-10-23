<?php

namespace App\Services\Warehouse;

use App\Models\IncomingInvoice;
use App\Models\IncomingInvoiceItem;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\StockDocument;
use App\Models\StockDocumentLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Склад» → «Документы» → «📥 Приходная накладная» (legacy openWhDoc/saveWhDoc, L28683–28890; № 45, D-04 — ADR-0049).
 * «Сохранить черновик» keeps the document without moving the stock; «✅ Провести» posts it: each line adds its quantity to the
 * material, in one transaction, and writes a movement with the stock after it. A posted document does not change; how a
 * movement is cancelled waits for the answer to № 47.
 */
class StockDocumentService
{
    public const PREFIX = ['receipt' => 'ПН'];

    public const STALE = 'Документ изменён другим пользователем — обновите страницу и повторите.';

    /** Legacy whDocSeq: «ПН-001», the next number after the largest of that kind. */
    public static function nextNumber(string $type): string
    {
        $prefix = self::PREFIX[$type];
        $max = StockDocument::query()->where('doc_type', $type)->where('num', '~', '^'.$prefix.'-[0-9]+$')
            ->pluck('num')->map(fn (string $n) => (int) substr($n, strlen($prefix) + 1))->max() ?? 0;

        return $prefix.'-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array{date: string, num: ?string, supplier: ?string, esf_ref: ?string, note: ?string, lines: list<array{material_id: int, qty: string, price: string}>}  $data
     */
    public function saveReceipt(array $data, bool $post, User $by, ?int $id = null, ?int $lockVersion = null): StockDocument
    {
        return DB::transaction(function () use ($data, $post, $by, $id, $lockVersion) {
            // legacy saveWhDoc keeps the lines with a material and a quantity
            $lines = array_values(array_filter($data['lines'], fn ($l) => $l['material_id'] && bccomp((string) $l['qty'], '0', 3) > 0));
            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => 'Добавьте хотя бы одну позицию']);
            }
            $materials = Material::query()->whereIn('id', array_column($lines, 'material_id'))->where('active', true)->get()->keyBy('id');
            if ($materials->count() !== count(array_unique(array_column($lines, 'material_id')))) {
                throw ValidationException::withMessages(['lines' => 'Материал не найден — обновите страницу.']);
            }
            $doc = $id === null ? new StockDocument(['doc_type' => 'receipt', 'created_by_id' => $by->id, 'created_by_name' => $by->name]) : $this->draft($id, $lockVersion);
            $num = $data['num'] ?: ($doc->exists ? $doc->num : self::nextNumber('receipt'));
            if (StockDocument::query()->where('doc_type', 'receipt')->where('num', $num)->when($doc->exists, fn ($q) => $q->whereKeyNot($doc->id))->exists()) {
                throw ValidationException::withMessages(['num' => "Номер {$num} уже есть"]);
            }
            $supplier = $data['supplier'] ? Supplier::query()->active()->where('name', $data['supplier'])->orderBy('id')->first() : null;
            $doc->fill(['num' => $num, 'doc_date' => $data['date'], 'supplier_name' => $data['supplier'], 'supplier_id' => $supplier?->id, 'esf_ref' => $data['esf_ref'], 'note' => $data['note'], 'status' => 'draft']);
            if ($doc->exists) {
                $doc->lock_version++;
            }
            $doc->save();
            $doc->lines()->delete();
            foreach ($lines as $i => $l) {
                $qty = bcadd((string) $l['qty'], '0', 3);
                $price = bcadd((string) $l['price'], '0', 2);
                StockDocumentLine::query()->create(['document_id' => $doc->id, 'line_no' => $i + 1, 'material_id' => $l['material_id'], 'qty' => $qty,
                    'unit' => $materials[$l['material_id']]->unit, 'price' => $price, 'amount' => bcadd(bcmul($qty, $price, 5), '0.005', 2)]); // to the tiyn, half up (quantity and price are not negative)
            }
            Audit::record($id === null ? 'stock_document.created' : 'stock_document.updated', 'stock_document', $doc->id, ['num' => $doc->num, 'lines' => count($lines)], $by);
            if ($post) {
                $this->postLocked($doc, $by);
            }

            return $doc->refresh();
        });
    }

    /**
     * «Входящие ЭСФ» → «📦 Принять» (to the material the line matches) or «📦 На склад» (a new material named by the user,
     * with the line's text as its alias) — legacy acceptItemToWarehouse (L13117). Legacy changed the stock of the material
     * directly; here it is a posted receipt of today with the supplier and the ESF as its basis, one line taken in once. As in
     * legacy the material moves to the chosen warehouse; a new one gets that warehouse's first category.
     */
    public function receiveEsfItem(int $itemId, ?int $materialId, string $warehouseCode, ?string $name, ?string $unit, User $by): StockDocument
    {
        return DB::transaction(function () use ($itemId, $materialId, $warehouseCode, $name, $unit, $by) {
            $item = IncomingInvoiceItem::query()->lockForUpdate()->find($itemId) ?? throw ValidationException::withMessages(['item' => 'Строка ЭСФ не найдена — обновите страницу.']);
            $taken = StockDocumentLine::query()->where('esf_item_id', $item->id)->first();
            if ($taken) {
                throw ValidationException::withMessages(['item' => 'Строка ЭСФ уже принята на склад документом '.StockDocument::query()->whereKey($taken->document_id)->value('num')]);
            }
            if (bccomp((string) $item->qty, '0', 3) <= 0 || bccomp((string) $item->unit_price, '0', 2) < 0) {
                throw ValidationException::withMessages(['item' => 'В строке ЭСФ нет количества или цена меньше нуля — принять нечего']);
            }
            $warehouse = Warehouse::query()->where('code', $warehouseCode)->where('type', 'material')->first() ?? throw ValidationException::withMessages(['warehouse' => 'Склад не найден — обновите страницу.']);
            $inv = IncomingInvoice::query()->findOrFail($item->invoice_id);
            if ($materialId !== null) {
                $material = Material::query()->active()->lockForUpdate()->find($materialId) ?? throw ValidationException::withMessages(['material' => 'Материал не найден — обновите страницу.']);
                if ($material->warehouse_code !== $warehouse->code) {
                    $material->forceFill(['warehouse_code' => $warehouse->code, 'lock_version' => $material->lock_version + 1])->save();
                }
            } else {
                $name = trim((string) $name);
                if ($name === '') {
                    throw ValidationException::withMessages(['name' => 'Укажите название материала']);
                }
                if (Material::query()->active()->whereRaw('lower(name) = lower(?)', [$name])->exists()) {
                    throw ValidationException::withMessages(['name' => "Материал «{$name}» уже есть на складе"]);
                }
                $material = new Material(['name' => $name, 'category' => $warehouse->categories[0] ?? 'Прочее', 'unit' => trim((string) $unit) ?: 'шт',
                    'price' => bcadd((string) $item->unit_price, '0', 2), 'min_qty' => 0, 'supplier_name' => $inv->supplier_name, 'warehouse_code' => $warehouse->code]);
                $material->forceFill(['qty' => '0', 'created_by_id' => $by->id, 'created_by_name' => $by->name])->save();
                Audit::record('material.created', 'material', $material->id, ['name' => $material->name, 'from_esf_item' => $item->id], $by);
                if ($name !== $item->description && ! MaterialAlias::query()->where('material_name', $name)->where('alias_name', $item->description)->exists()) {
                    MaterialAlias::query()->create(['material_name' => $name, 'alias_name' => $item->description, 'category' => 'esf']);
                }
            }
            $doc = StockDocument::query()->create(['doc_type' => 'receipt', 'num' => self::nextNumber('receipt'), 'doc_date' => now(config('erp.business_timezone'))->format('Y-m-d'),
                'status' => 'draft', 'supplier_name' => $inv->supplier_name, 'supplier_id' => $inv->supplier_id, 'esf_ref' => mb_substr('ЭСФ №'.$inv->num, 0, 64),
                'note' => mb_substr((string) $item->description, 0, 1000), 'created_by_id' => $by->id, 'created_by_name' => $by->name]);
            $qty = bcadd((string) $item->qty, '0', 3);
            $price = bcadd((string) $item->unit_price, '0', 2);
            StockDocumentLine::query()->create(['document_id' => $doc->id, 'line_no' => 1, 'material_id' => $material->id, 'esf_item_id' => $item->id, 'qty' => $qty,
                'unit' => $material->unit, 'price' => $price, 'amount' => bcadd(bcmul($qty, $price, 5), '0.005', 2)]);
            Audit::record('stock_document.created', 'stock_document', $doc->id, ['num' => $doc->num, 'lines' => 1, 'esf_item' => $item->id], $by);
            $this->postLocked($doc, $by);

            return $doc->refresh();
        });
    }

    /** «✅ Провести» of a saved draft. */
    public function post(int $id, int $lockVersion, User $by): StockDocument
    {
        return DB::transaction(function () use ($id, $lockVersion, $by) {
            $doc = $this->draft($id, $lockVersion);
            $doc->lock_version++;
            $doc->save();
            $this->postLocked($doc, $by);

            return $doc->refresh();
        });
    }

    private function postLocked(StockDocument $doc, User $by): void
    {
        $lines = $doc->lines()->get();
        // materials locked in the order of their ids: two documents posted at once do not wait for each other in a circle
        $materials = Material::query()->whereIn('id', $lines->pluck('material_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $now = now();
        foreach ($lines as $line) {
            $m = $materials[$line->material_id];
            $after = bcadd((string) $m->qty, (string) $line->qty, 3);
            $m->forceFill(['qty' => $after, 'lock_version' => $m->lock_version + 1])->save();
            DB::table('stock_movements')->insert(['material_id' => $m->id, 'document_id' => $doc->id, 'line_id' => $line->id, 'moved_on' => $doc->doc_date->format('Y-m-d'),
                'qty_delta' => $line->qty, 'qty_after' => $after, 'below_zero' => bccomp($after, '0', 3) < 0, 'created_at' => $now->format('Y-m-d H:i:s.uP')]);
        }
        $doc->forceFill(['status' => 'posted', 'posted_at' => $now, 'posted_by_id' => $by->id])->save();
        Audit::record('stock_document.posted', 'stock_document', $doc->id, ['num' => $doc->num, 'lines' => $lines->count(),
            'materials' => $lines->map(fn ($l) => ['material' => $l->material_id, 'qty' => (string) $l->qty])->all()], $by);
    }

    private function draft(int $id, ?int $lockVersion): StockDocument
    {
        $doc = StockDocument::query()->lockForUpdate()->find($id);
        if (! $doc) {
            throw ValidationException::withMessages(['lock_version' => 'Документ не найден — обновите страницу.']);
        }
        if ($lockVersion !== null && $doc->lock_version !== $lockVersion) {
            throw ValidationException::withMessages(['lock_version' => self::STALE]);
        }
        if ($doc->status !== 'draft') {
            throw ValidationException::withMessages(['lock_version' => 'Документ уже проведён — изменить его нельзя.']);
        }

        return $doc;
    }
}
