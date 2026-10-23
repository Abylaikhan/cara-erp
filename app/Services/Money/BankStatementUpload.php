<?php

namespace App\Services\Money;

use App\Models\BankStatementRow;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * «Деньги» → «Сверка с банком» → «📥 Загрузить выписку» (legacy handleBankReconImport/detectBankFormat/
 * parseBankStatementForRecon/parseRuNumber/extractNameAndBin/parseStatementDate, L9381–9524, L14520–14570; ADR-0044, ADR-0050).
 * The first sheet is read as legacy reads it (the shown text of each cell); the header row is the first of the first 50 with
 * at least five filled cells that names a Kaspi or Halyk column; the account is the one legacy uses for that bank. Rows already
 * in the sheet of the account (by document number, else date, flow, amount and counterparty) are skipped, as legacy skips them.
 * Amounts are read exactly. Nothing is posted to the money journal: the rows wait for «Перенести» (D-03, D-11).
 */
class BankStatementUpload
{
    public const MAX_ROWS = 20000;

    /** Legacy BANK_RECON_ACCOUNTS: the accounts of each format, by their legacy id. */
    private const ACCOUNTS = ['kaspi' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'halyk' => '0265fb65-7812-4bee-b794-71306246edb7'];

    /** @return array{account: int, format: string, parsed: int, inserted: int} */
    public function import(string $path, User $by): array
    {
        $rows = $this->read($path);
        [$headerIdx, $format] = [-1, null];
        for ($i = 0; $i < min(count($rows), 50); $i++) {
            $cand = array_map(fn ($h) => trim((string) $h), $rows[$i] ?? []);
            if (count(array_filter($cand, fn ($c) => $c !== '')) < 5) {
                continue;
            }
            if ($format = self::detectFormat($cand)) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === -1) {
            throw ValidationException::withMessages(['file' => 'Не удалось найти строку заголовков в файле. Пока поддерживаются выписки Kaspi и Halyk.']);
        }
        $parsed = self::parse($rows, $headerIdx, array_map(fn ($h) => trim((string) $h), $rows[$headerIdx]), $format);
        if ($parsed === []) {
            throw ValidationException::withMessages(['file' => 'Не найдено операций для загрузки.']);
        }
        $account = DB::table('legacy_id_map')->where('source_table', 'money_accounts')->where('source_key', self::ACCOUNTS[$format])->value('target_id')
            ?? throw ValidationException::withMessages(['file' => 'Счёт '.($format === 'kaspi' ? 'Kaspi' : 'Халык').' не найден.']);

        return DB::transaction(function () use ($parsed, $account, $format, $by) {
            DB::table('money_accounts')->where('id', $account)->lockForUpdate()->first();
            $key = fn (array $r) => $r['doc_num'] !== '' ? 'doc:'.$r['doc_num'] : $r['date'].'|'.$r['flow'].'|'.bcadd($r['amount'], '0', 2).'|'.trim($r['counterparty']);
            $seen = BankStatementRow::query()->where('account_id', $account)->get()
                ->map(fn (BankStatementRow $r) => $key(['doc_num' => (string) $r->doc_num, 'date' => $r->row_date?->format('Y-m-d') ?? '', 'flow' => $r->flow,
                    'amount' => (string) $r->amount, 'counterparty' => (string) $r->counterparty]))->flip()->all();
            $insert = [];
            foreach ($parsed as $r) {
                $k = $key($r);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $insert[] = $r;
            }
            if ($insert === []) {
                throw ValidationException::withMessages(['file' => 'Все '.count($parsed).' операций из файла уже есть в сверке — новых не найдено.']);
            }
            $now = now();
            foreach (array_chunk($insert, 500) as $chunk) {
                BankStatementRow::query()->insert(array_map(fn ($r) => [
                    'uuid' => (string) Str::uuid(), 'account_id' => $account, 'bank_format' => $format, 'row_date' => $r['date'] ?: null, 'flow' => $r['flow'],
                    'amount' => bcadd($r['amount'], '0', 2), 'counterparty' => mb_substr($r['counterparty'], 0, 255) ?: null, 'bin' => mb_substr($r['bin'], 0, 32) ?: null,
                    'description' => $r['description'] ?: null, 'knp' => mb_substr($r['knp'], 0, 16) ?: null, 'doc_num' => mb_substr($r['doc_num'], 0, 64) ?: null,
                    'transferred_to_db' => false, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
            Audit::record('bank_statement.imported', 'money_account', $account, ['format' => $format, 'parsed' => count($parsed), 'inserted' => count($insert)], $by);

            return ['account' => (int) $account, 'format' => $format, 'parsed' => count($parsed), 'inserted' => count($insert)];
        });
    }

    /** @return list<list<string>> the shown text of the cells of the first sheet */
    private function read(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false);
            $sheet = $reader->load($path)->getSheet(0);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать файл Excel.']);
        }
        if ($sheet->getHighestDataRow() > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'В выписке больше '.self::MAX_ROWS.' строк — разделите её на части.']);
        }

        return array_map(fn ($r) => array_map(fn ($v) => $v === null ? '' : (string) $v, $r), $sheet->toArray('', true, true, false));
    }

    /** Legacy detectBankFormat. */
    public static function detectFormat(array $headers): ?string
    {
        $h = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $headers);
        if (array_filter($h, fn ($x) => str_contains($x, 'наименование бенефициара'))) {
            return 'kaspi';
        }
        if (array_filter($h, fn ($x) => str_contains($x, 'бин контрагента')) && in_array('контрагент', $h, true)) {
            return 'halyk';
        }

        return null;
    }

    /**
     * Legacy parseBankStatementForRecon.
     *
     * @param  list<list<string>>  $rows
     * @param  list<string>  $headers
     * @return list<array{date: string, flow: string, amount: string, counterparty: string, bin: string, description: string, knp: string, doc_num: string}>
     */
    public static function parse(array $rows, int $headerIdx, array $headers, string $format): array
    {
        $col = function (string $name) use ($headers): int {
            foreach ($headers as $i => $h) {
                if (mb_strtolower($h) === mb_strtolower($name)) {
                    return $i;
                }
            }

            return -1;
        };
        $idx = $format === 'kaspi'
            ? ['docNum' => $col('№ документа'), 'date' => $col('Дата операции'), 'debit' => $col('Дебет'), 'credit' => $col('Кредит'),
                'benef' => $col('Наименование бенефициара / отправителя денег'), 'desc' => $col('Назначение платежа'), 'knp' => $col('КНП')]
            : ['docNum' => $col('Номер документа'), 'date' => $col('Дата валютирования'), 'ctrName' => $col('Контрагент'), 'ctrBin' => $col('БИН контрагента'),
                'debit' => $col('Дебет'), 'credit' => $col('Кредит'), 'desc' => $col('Назначение платежа'), 'knp' => $col('КНП')];
        $cell = fn (array $r, int $i) => $i > -1 ? (string) ($r[$i] ?? '') : '';
        $out = [];
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $filled = array_values(array_filter($r, fn ($c) => trim($c) !== ''));
            if ($filled === []) {
                continue;
            }
            // a row of column numbers under the header (1, 2, 3 …)
            if (! array_filter($filled, fn ($c) => ! preg_match('/^\d+$/', trim($c)) || (int) trim($c) > count($headers))) {
                continue;
            }
            $debit = self::number($cell($r, $idx['debit']));
            $credit = self::number($cell($r, $idx['credit']));
            if (bccomp($debit, '0', 12) === 0 && bccomp($credit, '0', 12) === 0) {
                continue;
            }
            if ($format === 'kaspi') {
                [$name, $bin] = self::nameAndBin($cell($r, $idx['benef']));
            } else {
                [$name, $bin] = [trim($cell($r, $idx['ctrName'])), trim($cell($r, $idx['ctrBin']))];
            }
            $expense = bccomp($debit, '0', 12) > 0;
            if (bccomp($expense ? $debit : $credit, '0', 12) < 0) {
                throw ValidationException::withMessages(['file' => 'Строка '.($i + 1).': сумма меньше нуля — проверьте выписку.']);
            }
            $out[] = ['date' => self::date($cell($r, $idx['date'])) ?? '', 'flow' => $expense ? 'expense' : 'income', 'amount' => $expense ? $debit : $credit,
                'counterparty' => $name, 'bin' => $bin, 'description' => trim($cell($r, $idx['desc'])), 'knp' => trim($cell($r, $idx['knp'])), 'doc_num' => trim($cell($r, $idx['docNum']))];
        }

        return $out;
    }

    /** Legacy parseRuNumber, exact: the decimal separator is the last «,» or «.»; spaces and ₸ are dropped; not a number — 0. */
    public static function number(string $s): string
    {
        $s = (string) preg_replace('/[₸\s\x{00A0}\x{202F}]/u', '', trim($s));
        $neg = str_starts_with($s, '-');
        $pos = max(strrpos($s, ',') === false ? -1 : strrpos($s, ','), strrpos($s, '.') === false ? -1 : strrpos($s, '.'));
        $int = (string) preg_replace('/\D/', '', $pos === -1 ? $s : substr($s, 0, $pos));
        $frac = $pos === -1 ? '' : (string) preg_replace('/\D/', '', substr($s, $pos + 1));
        if ($int === '' && $frac === '') {
            return '0';
        }
        $n = ltrim($int, '0') ?: '0';

        return ($neg ? '-' : '').$n.($frac !== '' ? '.'.$frac : '');
    }

    /** Legacy extractNameAndBin: the name lines above the line with ИИН/БИН, and its 10–12 digits. @return array{string, string} */
    public static function nameAndBin(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: []), fn ($l) => $l !== ''));
        $binIdx = -1;
        foreach ($lines as $i => $l) {
            if (preg_match('/иин\/бин|бин\/иин|бин|иин/iu', $l)) {
                $binIdx = $i;
                break;
            }
        }
        $bin = $binIdx > -1 && preg_match('/(\d{10,12})/', $lines[$binIdx], $m) ? $m[1] : '';
        $name = trim(implode(' ', $binIdx > -1 ? array_slice($lines, 0, $binIdx) : $lines));

        return [$name !== '' ? $name : ($lines[0] ?? ''), $bin];
    }

    /** Legacy parseStatementDate: «ДД.ММ.ГГГГ», with a time or without, → ГГГГ-ММ-ДД; anything else — none. */
    public static function date(string $s): ?string
    {
        if (! preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', trim($s), $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
}
