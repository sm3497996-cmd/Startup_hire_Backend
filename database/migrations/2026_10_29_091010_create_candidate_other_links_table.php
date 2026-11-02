<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    Schema::create('candidate_other_links', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('candidate_id');
        $table->string('label', 100);
        $table->string('url', 500);
        $table->timestamps();

        $table->index('candidate_id');
    });
}

public function down(): void
{
    Schema::dropIfExists('candidate_other_links');
}
};
