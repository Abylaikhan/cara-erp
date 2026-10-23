<?php

namespace App\Http\Controllers;

use App\Services\Excel\XlsxFromRows;
use App\Support\Audit;
use App\Support\Erp;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Excel exports (ADR-0044): legacy builds each file in the browser from the rows on the screen (SheetJS); here the page sends
 * those rows and the server builds the file. The export belongs to a page: only a user who may open it may export from it.
 * Nothing is read from the database: the file holds what the page already showed to this user.
 */
class ExportController extends Controller
{
    private const MAX_ROWS = 50000;

    private const MAX_COLS = 60;

    public function xlsx(Request $request, XlsxFromRows $builder): Response
    {
        $data = $request->validate([
            'page' => ['required', 'string', 'max:32'],
            'filename' => ['required', 'string', 'max:120'],
            'sheets' => ['required', 'array', 'min:1', 'max:10'],
            'sheets.*.name' => ['required', 'string', 'max:64'],
            'sheets.*.rows' => ['present', 'array', 'max:'.self::MAX_ROWS],
            'sheets.*.rows.*' => ['array', 'max:'.self::MAX_COLS],
            'sheets.*.cols' => ['nullable', 'array', 'max:'.self::MAX_COLS],
            'sheets.*.cols.*' => ['nullable', 'numeric', 'min:0', 'max:255'],
        ]);
        abort_unless(Erp::canAccess($request->user(), $data['page']), 403);
        foreach ($data['sheets'] as $sheet) {
            foreach ($sheet['rows'] as $row) {
                foreach ($row as $value) {
                    if (! ($value === null || is_bool($value) || is_int($value) || is_float($value) || (is_string($value) && mb_strlen($value) <= 32767))) {
                        abort(422, 'Недопустимое значение ячейки');
                    }
                }
            }
        }
        $sheets = array_map(fn (array $s) => ['name' => $s['name'], 'rows' => array_map('array_values', $s['rows']), 'cols' => array_values($s['cols'] ?? [])], $data['sheets']);
        $bytes = $builder->build($sheets);
        Audit::record('export.xlsx', 'page', $data['page'], ['filename' => self::filename($data['filename']), 'rows' => array_sum(array_map(fn ($s) => count($s['rows']), $sheets))], $request->user());

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => self::disposition(self::filename($data['filename'])),
            'Cache-Control' => 'no-store',
        ]);
    }

    /** The name legacy gives the file, without path parts or control characters, ending in .xlsx. */
    public static function filename(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]/u', '_', $name));
        $name = (string) preg_replace('/\.xlsx$/iu', '', $name);

        return ($name !== '' ? mb_substr($name, 0, 100) : 'export').'.xlsx';
    }

    private static function disposition(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return "attachment; filename=\"{$ascii}\"; filename*=UTF-8''".rawurlencode($name);
    }
}
