<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One folio per stay. `balance` is a denormalized running total
     * maintained transactionally alongside folio_transactions inserts
     * (never edited directly) purely so lists/dashboards can read it
     * without summing the ledger each time; the ledger itself is the
     * source of truth and folio balance can always be recomputed from it.
     */
    public function up(): void
    {
        Schema::create('folios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('stays')->restrictOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open')->index();
            $table->decimal('balance', 14, 2)->default(0)->comment('Positive = guest owes the hotel. Denormalized cache of SUM(folio_transactions); recomputable.');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folios');
    }
};
