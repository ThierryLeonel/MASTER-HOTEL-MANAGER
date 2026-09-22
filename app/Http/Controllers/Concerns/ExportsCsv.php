<?php

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): CSV export via native PHP
 * (`fputcsv` against a streamed response) — no new Composer dependency,
 * unlike PDF export (see the decision recorded in §3.6/§7 for why that
 * one genuinely does need a package this sandbox cannot install or
 * verify). Streaming rather than building the whole file in memory first
 * keeps this safe for a large export without a row-count cap.
 */
trait ExportsCsv
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<array<int, string|int|float|null>>  $rows
     */
    protected function csvResponse(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map([self::class, 'neutralizeFormula'], $headers));
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'neutralizeFormula'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Security re-audit finding (PHASE9_AUDIT_AND_PLAN.md §9.8, fixed):
     * defensive CSV/formula-injection guard. A cell value starting with
     * =, +, -, or @ is interpreted as a formula by Excel/Sheets/LibreOffice
     * when the file is opened, not as plain text — if any exported field
     * (a description, a guest/employee name) ever started with one of
     * these characters, opening the export could execute a formula built
     * from other cells' data. Every export using this trait is already
     * gated behind view-financial-reports/view-operational-reports (Owner/
     * GM/Accountant/Duty Manager), so today's exposure is low, but this is
     * cheap, standard, and zero-cost to apply universally rather than
     * relying on every current and future export column being reviewed by
     * hand: prefix such a value with a leading apostrophe, which every
     * spreadsheet application treats as "force text," leaving the visible
     * content unchanged.
     */
    private static function neutralizeFormula(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
