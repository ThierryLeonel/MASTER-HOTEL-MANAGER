<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class PayslipLine extends Model
{
    use HasSyncUlid;
    protected $fillable = ['payslip_id', 'type', 'label', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payslip()
    {
        return $this->belongsTo(Payslip::class);
    }
}
