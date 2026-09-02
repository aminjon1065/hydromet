<?php

namespace App\Support\Csv;

/**
 * Neutralises a CSV cell that a spreadsheet would treat as a formula.
 *
 * Excel, LibreOffice and Sheets evaluate a cell that begins with `=`, `+`, `-`,
 * `@` or a leading tab or carriage return. A stored value such as
 * `=HYPERLINK("http://…"&A1)` is harmless in the database and in the portal,
 * and becomes an attack the moment an administrator opens the export. A leading
 * apostrophe is the conventional neutraliser: the spreadsheet shows the literal
 * text, and a plain CSV reader sees one extra character rather than a formula.
 *
 * Guarding a column is therefore only correct where no consumer needs the cell
 * as a number. That holds for the audit export, whose every column is a code,
 * an identifier or free text. It does NOT hold for the measurement export,
 * where a negative reading legitimately starts with `-`, so that export
 * deliberately does not use this.
 */
final class SpreadsheetSafeText
{
    /**
     * @var array<int, string>
     */
    private const FORMULA_LEADS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    public static function cell(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // C0 controls other than tab, newline and carriage return are never
        // meaningful here and confuse parsers and terminals alike. The class is
        // byte-oriented on purpose: these bytes cannot occur inside a UTF-8
        // multi-byte sequence, so no `/u` modifier is needed and invalid input
        // cannot make the whole cell disappear.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        if (! is_string($value) || $value === '') {
            return '';
        }

        return in_array($value[0], self::FORMULA_LEADS, true) ? "'".$value : $value;
    }
}
