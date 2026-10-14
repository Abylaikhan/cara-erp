<?php

namespace App\Services\Esf;

use App\Support\LegacyCsv;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

/**
 * XML of the IS ESF export → invoices, with the rules of legacy parseEsfXmlText (L12043) and its helpers xmlFindText,
 * xmlFindContainer, xmlNum, ddmmyyyyToIso, abbreviateLegalForm (L11979–12040):
 * - every <invoiceInfo> is one invoice: registrationNumber and invoiceStatus outside, the invoice itself is a nested XML
 *   document in <invoiceBody> (CDATA);
 * - the date is the turnover date when there is one, otherwise the document date (dd.mm.yyyy);
 * - the supplier is <sellers><seller> (not the consignor), its long legal form is shortened («ТОО …»);
 * - lines are <product> with description, quantity, unitPrice, priceWithoutTax|turnoverSize, ndsRate, ndsAmount, priceWithTax.
 * Tag names are matched as the browser does: the exact qualified name first, then the local name ignoring case.
 * Numbers stay exact decimal strings; external entities and network access are disabled.
 */
class EsfXmlParser
{
    /** Legacy LEGAL_FORM_ABBREV (L11981). */
    private const LEGAL_FORMS = [
        '/^Товарищество с ограниченной ответственностью\.?\s*/iu' => 'ТОО ',
        '/^Жауапкершілігі шектеулі серіктестік\.?\s*/iu' => 'ТОО ',
        '/^Индивидуальный предприниматель\.?\s*/iu' => 'ИП ',
        '/^Некоммерческое акционерное общество\.?\s*/iu' => 'НАО ',
        '/^Акционерное общество\.?\s*/iu' => 'АО ',
        '/^Производственный кооператив\.?\s*/iu' => 'ПК ',
        '/^Крестьянское\s*\(?фермерское\)?\s*хозяйство\.?\s*/iu' => 'КХ ',
        '/^Государственное учреждение\.?\s*/iu' => 'ГУ ',
        '/^Частное учреждение\.?\s*/iu' => 'ЧУ ',
        '/^Общественное объединение\.?\s*/iu' => 'ОО ',
        '/^Республиканское государственное предприятие\.?\s*/iu' => 'РГП ',
    ];

    /**
     * @return list<array{fileName: string, ok: bool, error?: string, date?: string, num?: string, regNum?: string, status?: string,
     *     supplier_name?: string, supplier_bin?: string, total_amount?: string, total_nds?: string, total_with_nds?: string,
     *     items?: list<array{description: string, qty: string, unit_price: string, amount: string, nds_rate: string, nds_amount: string, total: string}>}>
     */
    public function parse(string $xml, string $fileName): array
    {
        $doc = self::load($xml);
        if ($doc === null) {
            return [['error' => 'Не удалось разобрать XML — файл повреждён или это не XML', 'fileName' => $fileName, 'ok' => false]];
        }
        $infos = self::elements($doc, 'invoiceInfo', exact: true);
        if (! $infos) {
            return [['error' => 'Не найден блок invoiceInfo — формат файла не распознан', 'fileName' => $fileName, 'ok' => false]];
        }

        return array_map(function (DOMElement $info, int $idx) use ($fileName, $infos) {
            try {
                $regNum = self::findText($info, ['registrationNumber']);
                $status = self::findText($info, ['invoiceStatus']) ?: 'CREATED';
                $body = self::elements($info, 'invoiceBody', exact: true)[0] ?? null;
                $cdata = $body?->textContent ?? '';
                if ($cdata === '') {
                    return ['error' => 'Пустой invoiceBody', 'fileName' => $fileName, 'ok' => false];
                }
                $inner = self::load($cdata);
                if ($inner === null) {
                    return ['error' => 'Не удалось разобрать вложенный XML внутри invoiceBody', 'fileName' => $fileName, 'ok' => false];
                }
                $date = self::isoDate(self::findText($inner, ['turnoverDate'])) ?: self::isoDate(self::findText($inner, ['date']));
                $num = self::findText($inner, ['num']);
                $sellers = self::elements($inner, 'sellers', exact: true)[0] ?? null;
                $seller = ($sellers ? (self::elements($sellers, 'seller', exact: true)[0] ?? null) : null) ?? $inner;
                $supplierName = self::abbreviateLegalForm(self::findText($seller, ['name']));
                $supplierBin = self::findText($seller, ['tin']);

                $products = self::elements($inner, 'products', exact: true)[0] ?? $inner;
                $items = [];
                foreach (self::elements($products, 'product', exact: true) as $product) {
                    $item = [
                        'description' => self::findText($product, ['description']),
                        'qty' => self::number(self::findText($product, ['quantity'])),
                        'unit_price' => self::number(self::findText($product, ['unitPrice'])),
                        'amount' => self::number(self::findText($product, ['priceWithoutTax', 'turnoverSize'])),
                        'nds_rate' => self::number(self::findText($product, ['ndsRate'])),
                        'nds_amount' => self::number(self::findText($product, ['ndsAmount'])),
                        'total' => self::number(self::findText($product, ['priceWithTax'])),
                    ];
                    if ($item['description'] !== '' || bccomp($item['total'], '0', 10) !== 0) {
                        $items[] = $item;
                    }
                }
                $sum = fn (string $field) => array_reduce($items, fn (string $s, array $it) => bcadd($s, $it[$field], 10), '0');

                return [
                    'fileName' => $fileName.(count($infos) > 1 ? ' (#'.($idx + 1).')' : ''),
                    'date' => $date, 'num' => $num, 'regNum' => $regNum, 'status' => $status,
                    'supplier_name' => $supplierName, 'supplier_bin' => $supplierBin,
                    'total_amount' => $sum('amount'), 'total_nds' => $sum('nds_amount'), 'total_with_nds' => $sum('total'),
                    'items' => $items,
                    'ok' => $date !== '' && $supplierName !== '' && $items !== [],
                ];
            } catch (Throwable $e) {
                return ['error' => 'Ошибка разбора: '.$e->getMessage(), 'fileName' => $fileName, 'ok' => false];
            }
        }, $infos, array_keys($infos));
    }

    public static function abbreviateLegalForm(string $name): string
    {
        $s = LegacyCsv::trim($name);
        foreach (self::LEGAL_FORMS as $re => $abbr) {
            if (preg_match($re, $s)) {
                return preg_replace($re, $abbr, $s, 1) ?? $s;
            }
        }

        return $s;
    }

    private static function load(string $xml): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_PARSEHUGE) && $doc->documentElement !== null;

            return $ok ? $doc : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Descendant elements in document order whose qualified name is $tag (browser getElementsByTagName); or, when $exact is
     * false, whose local name equals $tag ignoring case.
     *
     * @return list<DOMElement>
     */
    private static function elements(DOMNode $root, string $tag, bool $exact): array
    {
        $doc = $root instanceof DOMDocument ? $root : $root->ownerDocument;
        $found = [];
        foreach ((new DOMXPath($doc))->query('descendant::*', $root) ?: [] as $el) {
            if ($el instanceof DOMElement && ($exact ? $el->nodeName === $tag : mb_strtolower((string) $el->localName) === mb_strtolower($tag))) {
                $found[] = $el;
            }
        }

        return $found;
    }

    /** Legacy xmlFindText: the first non-empty text of the tags in order, then the same by local name ignoring case. */
    private static function findText(DOMNode $root, array $tags): string
    {
        foreach ([true, false] as $exact) {
            foreach ($tags as $tag) {
                foreach (self::elements($root, $tag, $exact) as $el) {
                    $text = LegacyCsv::trim($el->textContent);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return '';
    }

    /** Legacy ddmmyyyyToIso: the first dd.mm.yyyy in the text. */
    private static function isoDate(string $s): string
    {
        return preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $s, $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : '';
    }

    /** Legacy xmlNum (parseFloat of the text without spaces, first comma as the point) as an exact decimal string; 0 if none. */
    private static function number(string $s): string
    {
        $s = preg_replace('/\s+/u', '', $s) ?? '';
        $pos = strpos($s, ',');
        if ($pos !== false) {
            $s = substr_replace($s, '.', $pos, 1);
        }
        if (! preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $s, $m)) {
            return '0';
        }
        $value = $m[0];
        if (isset($m[2])) {
            $value = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');
        }
        $value = ltrim($value, '+');
        $value = preg_replace('/^(-?)\./', '${1}0.', rtrim($value, '.')) ?? $value; // «.5» → «0.5», «12.» → «12»

        return bcadd($value, '0', 10);
    }
}
