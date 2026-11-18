<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->setThemeValues(['daylight', 'nightflight', 'terracotta', 'monsoon', 'retro-wanderlust', 'sakura-bloom', 'auto']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->setThemeValues(['daylight', 'nightflight', 'terracotta', 'monsoon', 'auto']);
    }

    private function setThemeValues(array $values): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($value) => "'{$value}'::character varying", $values));
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_theme_check');
            DB::statement("ALTER TABLE users ALTER COLUMN theme SET DEFAULT 'daylight'");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_theme_check CHECK (theme::text = ANY (ARRAY[{$list}]::text[]))");
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($values) {
            $table->enum('theme', $values)->default('daylight')->change();
        });
    }
};
