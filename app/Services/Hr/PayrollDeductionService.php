<?php

namespace App\Services\Hr;

use App\Models\PayrollDeductionType;

/**
 * The configurable statutory-deduction engine (spec section 23):
 * nothing about a tax/contribution schedule is hardcoded in PHP — it's
 * all read from `payroll_deduction_types` / `payroll_deduction_brackets`,
 * editable by Owner/GM from the UI. Two calculation methods:
 *
 *   flat_percent         — a single rate applied to the whole amount
 *                           (e.g. a flat social-security contribution).
 *   progressive_brackets — a real progressive scale: only the slice of
 *                           the amount that falls within each bracket
 *                           is taxed at that bracket's rate, exactly
 *                           like a standard income-tax computation.
 */
class PayrollDeductionService
{
    /**
     * @return array{total: float, breakdown: array<int, array{type: PayrollDeductionType, amount: float}>}
     */
    public function calculate(float $grossPay): array
    {
        $breakdown = [];
        $total = 0.0;

        $types = PayrollDeductionType::where('is_active', true)
            ->orderBy('display_order')
            ->with('brackets')
            ->get();

        foreach ($types as $type) {
            $amount = $type->isProgressive()
                ? $this->applyBrackets($grossPay, $type)
                : round($grossPay * ((float) $type->flat_rate_percent / 100), 2);

            if ($amount > 0) {
                $breakdown[] = ['type' => $type, 'amount' => $amount];
                $total += $amount;
            }
        }

        return ['total' => round($total, 2), 'breakdown' => $breakdown];
    }

    private function applyBrackets(float $grossPay, PayrollDeductionType $type): float
    {
        $amount = 0.0;

        foreach ($type->brackets as $bracket) {
            $min = (float) $bracket->min_amount;
            $max = $bracket->max_amount !== null ? (float) $bracket->max_amount : null;

            if ($grossPay <= $min) {
                continue;
            }

            $taxableInBracket = ($max !== null ? min($grossPay, $max) : $grossPay) - $min;
            if ($taxableInBracket <= 0) {
                continue;
            }

            $amount += $taxableInBracket * ((float) $bracket->rate_percent / 100);
        }

        return round($amount, 2);
    }
}
