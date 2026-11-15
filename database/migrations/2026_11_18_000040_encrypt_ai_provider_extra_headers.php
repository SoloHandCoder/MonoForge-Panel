<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extra provider headers can hold keys (an Azure api-key, a gateway token), so they are stored
 * encrypted like the API key. The JSON column becomes text holding the encrypted JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convert(fn (string $value): string => Crypt::encryptString($value), 'text');
    }

    public function down(): void
    {
        $this->convert(fn (string $value): string => Crypt::decryptString($value), 'json');
    }

    /**
     * @param  Closure(string): string  $map
     */
    private function convert(Closure $map, string $to): void
    {
        Schema::table('ai_providers', function (Blueprint $table) use ($to): void {
            $table->{$to}('extra_headers_converted')->nullable()->after('extra_headers');
        });

        DB::table('ai_providers')->whereNotNull('extra_headers')->orderBy('id')->each(function (object $row) use ($map): void {
            /** @var object{id: int, extra_headers: string} $row */
            DB::table('ai_providers')->where('id', $row->id)->update(['extra_headers_converted' => $map($row->extra_headers)]);
        });

        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->dropColumn('extra_headers');
        });

        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->renameColumn('extra_headers_converted', 'extra_headers');
        });
    }
};
