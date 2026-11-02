<?php

namespace App\Services;

use App\Exceptions\ApiError;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use XMLReader;
use ZipArchive;

class ImportFileReader
{
    private array $sourceRows = [];

    private const MAX_BYTES = 15000000;

    private const MAX_XML_BYTES = 40000000;

    private const MAX_RAW_ROWS = 53000;

    private const MAX_SHARED_STRINGS = 250000;

    private const MAX_SHARED_STRING_BYTES = 16000000;

    private function invalid(string $message): never
    {
        throw new ApiError($message, 422);
    }

    /** @return array<array{name:string,rows:array<array<string>>}> */
    public function read(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            $this->invalid('الحد الأقصى للملف 15 ميغابايت.');
        }
        $extension = mb_strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (! in_array($extension, ['txt', 'csv', 'tsv', 'xlsx'], true)) {
            $this->invalid('اختر ملف TXT أو CSV أو XLSX.');
        }
        if ($extension === 'xlsx') {
            if (! class_exists(ZipArchive::class) || ! class_exists(XMLReader::class)) {
                throw new ApiError('قراءة XLSX تحتاج إضافتي ZIP وXMLReader على الخادم؛ استخدم CSV مؤقتاً', 503);
            }

            return $this->checked($this->excel($file));
        }
        $text = file_get_contents($file->getPathname());
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $text = mb_convert_encoding(substr($text, 2), 'UTF-8', str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            $this->invalid('احفظ النص بترميز UTF-8 أو Unicode قبل الاستيراد.');
        }

        $rows = $this->delimited($text);

        return $this->checked([['name' => basename($file->getClientOriginalName()), 'rows' => $rows, 'sourceRows' => $this->sourceRows]]);
    }

    private function checked(array $sheets): array
    {
        foreach ($sheets as $sheet) {
            $rows = $sheet['rows'];
            $head = $rows[0];
            $clean = fn ($v) => trim(preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', (string) $v));
            if (count($head) >= 5 && preg_match('/^\d+$/D', $head[1]) && preg_match('/^E[A-Z0-9-]+$/iD', $clean($head[2]))) {
                if ((int) $head[1] !== count($rows) - 1) {
                    $this->invalid('عدد البطاقات لا يطابق رأس ملف المجهّز.');
                }
                foreach (array_slice($rows, 1) as $row) {
                    if (count($row) < 2 || count($row) > 3) {
                        $this->invalid('ملف المجهّز يجب أن يحتوي سيريال وPIN وانتهاء اختياري.');
                    }
                }

                continue;
            }
            $normal = fn ($v) => preg_replace('/[\s_\-\x{064B}-\x{065F}\x{0670}]/u', '', mb_strtolower($clean($v)));
            $category = null;
            foreach ($head as $i => $h) {
                if (in_array($normal($h), ['category', 'categorycode', 'productcode', 'رمزالفئة', 'معرفالفئة'], true)) {
                    $category = $i;
                }
            }
            if ($category !== null) {
                $codes = [];
                foreach (array_slice($rows, 1) as $row) {
                    $code = $clean($row[$category] ?? '');
                    if ($code !== '') {
                        $codes[ImportCategories::code($code)] = true;
                    }
                }
                if (count($codes) > 1) {
                    $this->invalid('الورقة تحتوي أكثر من رمز فئة؛ افصل الفئات.');
                }
            }
        }

        return $sheets;
    }

    /** @return array<array<string>> */
    public function delimited(string $text): array
    {
        $this->sourceRows = [];
        $physical = 1;
        $start = 1;
        $text = preg_replace('/^\x{FEFF}/u', '', $text);
        $first = preg_split('/\r?\n/', $text, 2)[0];
        $delimiter = str_contains($first, "\t") ? "\t" : (str_contains($first, ';') ? ';' : ',');
        $rows = [];
        $row = [];
        $cell = '';
        $quoted = false;
        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $character = $text[$index];
            if ($character === '"') {
                if ($quoted && ($text[$index + 1] ?? '') === '"') {
                    $cell .= '"';
                    $index++;
                } else {
                    $quoted = ! $quoted;
                }
            } elseif ($character === $delimiter && ! $quoted) {
                $row[] = trim($cell);
                $cell = '';
                if (count($row) > 128) {
                    $this->invalid('عدد أعمدة الملف يتجاوز الحد المسموح.');
                }
            } elseif (($character === "\r" || $character === "\n") && ! $quoted) {
                if ($character === "\r" && ($text[$index + 1] ?? '') === "\n") {
                    $index++;
                }
                $row[] = trim($cell);
                $before = count($rows);
                $this->appendRow($rows, $row);
                if (count($rows) > $before) {
                    $this->sourceRows[] = $start;
                }
                $physical++;
                $start = $physical;
                $row = [];
                $cell = '';
            } else {
                $cell .= $character;
                if ($character === "\n") {
                    $physical++;
                }
            }
            if (strlen($cell) > 20000) {
                $this->invalid('قيمة في الملف تتجاوز الحد المسموح.');
            }
        }
        if ($quoted) {
            $this->invalid('علامة اقتباس غير مغلقة.');
        }
        $row[] = trim($cell);
        $before = count($rows);
        $this->appendRow($rows, $row);
        if (count($rows) > $before) {
            $this->sourceRows[] = $start;
        }

        if (! $rows) {
            $this->invalid('الملف فارغ.');
        }

        return $rows;
    }

    private function appendRow(array &$rows, array $row): void
    {
        if (array_filter($row, fn (string $cell): bool => $cell !== '')) {
            $rows[] = $row;
        }
        if (count($rows) > self::MAX_RAW_ROWS) {
            $this->invalid('الحد الأقصى 50,000 بطاقة لكل رفع.');
        }
    }

    private function document(string $xml): DOMDocument
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
            $this->invalid('تعريف الكيانات الخارجية غير مسموح في الملف.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->invalid('بنية Excel غير صالحة.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<array{name:string,rows:array<array<string>>}> */
    private function excel(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getPathname(), ZipArchive::RDONLY) !== true) {
            $this->invalid('ملف Excel غير صالح.');
        }
        try {
            if ($zip->numFiles > 3000) {
                $this->invalid('ملف Excel كبير جدًا.');
            }
            $files = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $total += $stat['size'];
                if ($total > self::MAX_XML_BYTES) {
                    $this->invalid('محتوى Excel يتجاوز 40 ميغابايت.');
                }
                if (preg_match('#^xl/(worksheets/sheet\d+|sharedStrings|styles|workbook)\.xml$#', $stat['name'])) {
                    if (isset($files[$stat['name']])) {
                        $this->invalid('ملف Excel يتضمن أجزاء مكررة.');
                    }
                    $data = $zip->getFromIndex($index, $stat['size'] + 1);
                    if ($data === false || strlen($data) !== $stat['size'] || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $data)) {
                        $this->invalid('بنية Excel غير صالحة.');
                    }
                    $files[$stat['name']] = $data;
                }
            }
        } finally {
            $zip->close();
        }
        $strings = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            $strings = $this->sharedStrings($files['xl/sharedStrings.xml']);
            unset($files['xl/sharedStrings.xml']);
        }
        $dateStyles = [];
        $numberStyles = [];
        $formats = [];
        if (isset($files['xl/styles.xml'])) {
            $document = $this->document($files['xl/styles.xml']);
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//*[local-name()="numFmt"]') as $format) {
                $formats[$format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
            }
            foreach ($xpath->query('//*[local-name()="cellXfs"]/*') as $style) {
                $id = (int) $style->getAttribute('numFmtId');
                $dateStyles[] = ($id >= 14 && $id <= 22) || (bool) preg_match('/[yd]/i', $formats[$id] ?? '');
                $numberStyles[] = $formats[$id] ?? ($id === 1 ? '0' : ($id === 49 ? '@' : 'General'));
            }
            unset($document);
        }
        $date1904 = false;
        if (isset($files['xl/workbook.xml'])) {
            $document = $this->document($files['xl/workbook.xml']);
            foreach ((new DOMXPath($document))->query('//*[local-name()="workbookPr"]') as $properties) {
                $date1904 = in_array($properties->getAttribute('date1904'), ['1', 'true'], true);
            }
            unset($document);
        }
        $result = [];
        $rowCount = 0;
        foreach ($files as $name => $content) {
            if (! str_starts_with($name, 'xl/worksheets/')) {
                continue;
            }
            $reader = new XMLReader;
            $rows = [];
            $sourceRows = [];
            $previous = libxml_use_internal_errors(true);
            try {
                if (! $reader->XML($content, null, LIBXML_NONET | LIBXML_COMPACT)) {
                    $this->invalid('بنية Excel غير صالحة.');
                }
                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                        continue;
                    }
                    $document = $this->document($reader->readOuterXml());
                    $xpath = new DOMXPath($document);
                    $sourceRow = (int) $document->documentElement->getAttribute('r');
                    $cells = [];
                    foreach ($xpath->query('//*[local-name()="row"]/*[local-name()="c"]') as $cell) {
                        $column = preg_replace('/\d/', '', $cell->getAttribute('r') ?: 'A');
                        $offset = 0;
                        foreach (str_split($column) as $letter) {
                            $offset = $offset * 26 + ord($letter) - 64;
                        }
                        if ($offset < 1 || $offset > 128) {
                            $this->invalid('عدد أعمدة الملف يتجاوز الحد المسموح.');
                        }
                        if ($xpath->query('*[local-name()="f"]', $cell)->length) {
                            $this->invalid('استبدل المعادلات بقيم ثابتة قبل الاستيراد.');
                        }
                        $raw = $xpath->query('*[local-name()="v"]', $cell)->item(0)?->textContent ?? '';
                        $type = $cell->getAttribute('t');
                        if (! in_array($type, ['', 'n', 's', 'inlineStr', 'str'], true)) {
                            $this->invalid('نوع خلية غير مدعوم؛ استخدم نصوصاً أو أرقاماً ثابتة.');
                        }
                        $value = match ($type) {
                            's' => $strings[(int) $raw] ?? '', 'inlineStr' => $xpath->query('*[local-name()="is"]', $cell)->item(0)?->textContent ?? '', default => $raw
                        };
                        if (($type === '' || $type === 'n') && $raw !== '' && (! is_numeric($raw) || abs((float) $raw) >= 1e15)) {
                            $this->invalid('الأرقام الطويلة يجب حفظها كنص في Excel لحماية رموز البطاقات.');
                        }
                        if (! in_array($type, ['s', 'inlineStr'], true) && ($dateStyles[(int) $cell->getAttribute('s')] ?? false) && $raw !== '') {
                            if (! is_numeric($raw) || abs((float) $raw) > 3650000) {
                                $this->invalid('تاريخ Excel غير صالح.');
                            }
                            $day = (int) floor((float) $raw);
                            if ((! $date1904 && ($day < 1 || $day === 60)) || $day < 0) {
                                $this->invalid('تاريخ Excel غير صالح.');
                            }
                            $epoch = $date1904 ? '1904-01-01' : ($day < 60 ? '1899-12-31' : '1899-12-30');
                            $value = (new DateTimeImmutable($epoch))->modify($day.' days')->format('Y-m-d');
                        } elseif (($type === '' || $type === 'n') && $raw !== '') {
                            $format = $numberStyles[(int) $cell->getAttribute('s')] ?? 'General';
                            if (preg_match('/^0{1,256}$/D', $format) && preg_match('/^\d+$/D', $raw)) {
                                $value = str_pad($raw, strlen($format), '0', STR_PAD_LEFT);
                            } elseif (! in_array($format, ['General', '@'], true)) {
                                $this->invalid('تنسيق رقمي غير مدعوم؛ احفظ الأكواد نصاً أو بتنسيق أصفار فقط.');
                            }
                        }
                        $cells[$offset - 1] = (string) $value;
                    }
                    if ($cells) {
                        $row = array_fill(0, max(array_keys($cells)) + 1, '');
                        foreach ($cells as $offset => $value) {
                            $row[$offset] = $value;
                        }
                        $before = count($rows);
                        $this->appendRow($rows, $row);
                        if (count($rows) > $before) {
                            $sourceRows[] = $sourceRow ?: count($rows);
                        }
                    }
                }
                if (libxml_get_errors()) {
                    $this->invalid('بنية Excel غير صالحة.');
                }
            } finally {
                $reader->close();
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $rowCount += count($rows);
            if ($rowCount > self::MAX_RAW_ROWS) {
                $this->invalid('الحد الأقصى 50,000 بطاقة لكل رفع.');
            }
            if ($rows) {
                $result[] = ['name' => basename($file->getClientOriginalName()).' / '.basename($name), 'rows' => $rows, 'sourceRows' => $sourceRows];
            }
            if (count($result) > 200) {
                $this->invalid('الحد الأقصى 200 ورقة.');
            }
        }
        if (! $result) {
            $this->invalid('ملف Excel لا يحتوي أوراق بيانات.');
        }

        return $result;
    }

    /** Bound string-table memory independently of the number of worksheet rows. */
    private function sharedStrings(string $xml): array
    {
        $reader = new XMLReader;
        $previous = libxml_use_internal_errors(true);
        $strings = [];
        $bytes = 0;
        $depth = null;
        $value = '';
        try {
            if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->invalid('بنية Excel غير صالحة.');
            }
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    if ($depth !== null || count($strings) >= self::MAX_SHARED_STRINGS) {
                        $this->invalid('جدول نصوص Excel كبير جدًا؛ قسم الملف إلى أجزاء أصغر.');
                    }
                    $depth = $reader->depth;
                    $value = '';
                    if ($reader->isEmptyElement) {
                        $strings[] = '';
                        $depth = null;
                    }
                } elseif ($depth !== null && in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                    $length = strlen($reader->value);
                    if (strlen($value) + $length > 20000 || $bytes + $length > self::MAX_SHARED_STRING_BYTES) {
                        $this->invalid('نصوص Excel تتجاوز حدود الذاكرة؛ قسم الملف إلى أجزاء أصغر.');
                    }
                    $value .= $reader->value;
                    $bytes += $length;
                } elseif ($depth !== null && $reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'si') {
                    $strings[] = $value;
                    $depth = null;
                }
            }
            if ($depth !== null || libxml_get_errors()) {
                $this->invalid('بنية Excel غير صالحة.');
            }

            return $strings;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
