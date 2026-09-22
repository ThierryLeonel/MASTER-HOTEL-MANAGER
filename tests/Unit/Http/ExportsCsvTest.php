<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\Concerns\ExportsCsv;
use Tests\TestCase;

/**
 * Security re-audit finding (PHASE9_AUDIT_AND_PLAN.md §9.8, fixed):
 * csvResponse() previously wrote every cell through fputcsv() verbatim.
 * A cell value starting with =, +, -, or @ is interpreted as a formula
 * by Excel/Sheets/LibreOffice when the export is opened, not as plain
 * text — classic CSV/formula injection. Exercised directly against the
 * trait (via an anonymous class) rather than through a full report
 * controller, since the neutralization logic lives entirely here.
 */
class ExportsCsvTest extends TestCase
{
    private function exporter(): object
    {
        return new class {
            use ExportsCsv;

            public function export(array $headers, array $rows): string
            {
                $response = $this->csvResponse('test.csv', $headers, $rows);

                ob_start();
                $response->sendContent();

                return ob_get_clean();
            }
        };
    }

    public function test_a_cell_starting_with_an_equals_sign_is_prefixed_with_an_apostrophe(): void
    {
        $csv = $this->exporter()->export(['Description'], [['=SUM(A1:A9)']]);

        $this->assertStringContainsString("'=SUM(A1:A9)", $csv);
    }

    public function test_cells_starting_with_plus_minus_or_at_are_also_neutralized(): void
    {
        $csv = $this->exporter()->export(['Description'], [
            ['+1234'], ['-cmd'], ['@SUM(1)'],
        ]);

        $this->assertStringContainsString("'+1234", $csv);
        $this->assertStringContainsString("'-cmd", $csv);
        $this->assertStringContainsString("'@SUM(1)", $csv);
    }

    public function test_an_ordinary_cell_is_completely_unaffected(): void
    {
        $csv = $this->exporter()->export(['Guest'], [['Jane Doe'], [1500], [null]]);

        $this->assertStringContainsString('Jane Doe', $csv);
        $this->assertStringNotContainsString("'Jane Doe", $csv);
        $this->assertStringContainsString('1500', $csv);
    }
}
