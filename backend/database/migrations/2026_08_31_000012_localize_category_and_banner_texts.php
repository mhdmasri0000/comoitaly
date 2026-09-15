<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->localizeStringColumn('categories', 'name');

        foreach (['subtitle', 'heading', 'title', 'button_text'] as $column) {
            $this->localizeStringColumn('hero_banners', $column);
        }
    }

    public function down(): void
    {
        // Keep localized JSON payloads.
    }

    private function localizeStringColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $rows = DB::table($table)->select(['id', $column])->get();
        foreach ($rows as $row) {
            $raw = $row->{$column};
            if ($raw === null || $raw === '') {
                continue;
            }

            if (! is_string($raw)) {
                continue;
            }

            $trimmed = trim($raw);
            if ($trimmed === '') {
                continue;
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && (isset($decoded['en']) || isset($decoded['ar']))) {
                continue;
            }

            DB::table($table)->where('id', $row->id)->update([
                $column => json_encode(['en' => $trimmed, 'ar' => $trimmed], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
};
