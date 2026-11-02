<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_resumes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employer_id');
            $table->unsignedBigInteger('candidate_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['employer_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_resumes');
    }
};