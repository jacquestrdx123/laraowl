<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the FULLTEXT index on `records.message`.
     *
     * It only served log search, but every insert into `records` (hundreds a
     * second, almost none of them logs) paid for it, and after a restart
     * InnoDB rebuilt its FTS cache while blocking inserts for 40+ minutes.
     * Log search now runs a LIKE over `message`, bounded by the
     * `(project_id, type, created_at)` index to one project's logs in the
     * selected period, which with raw retention measured in days is small.
     *
     * The `message` column itself stays.
     */
    public function up(): void
    {
        if ($this->hasFullText()) {
            Schema::table('records', function (Blueprint $table) {
                $table->dropFullText('records_message_fulltext');
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true) && ! $this->hasFullText()) {
            Schema::table('records', function (Blueprint $table) {
                $table->fullText('message', 'records_message_fulltext');
            });
        }
    }

    protected function hasFullText(): bool
    {
        return collect(Schema::getIndexes('records'))
            ->contains(fn (array $index) => $index['name'] === 'records_message_fulltext');
    }
};
