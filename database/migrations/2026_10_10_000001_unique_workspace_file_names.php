<?php

use App\Services\DocumentService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->string('filename_key', 64)->nullable()->unique();
            $t->string('display_name_key', 64)->nullable()->unique();
        });
        // Preserve every existing file; disambiguate only its display/download name.
        foreach (DB::table('documents')->orderBy('created_at')->orderBy('id')->get() as $d) {
            $v = DB::table('document_versions')->where('document_id', $d->id)->orderByDesc('number')->first();
            if (!$v) continue;
            $title = $d->title;
            $filename = $v->filename;
            $suffix = 1;
            do {
                $keys = DocumentService::nameKeys($d, $filename, $title);
                $exists = DB::table('documents')->where(fn ($q) => $q->where('filename_key', $keys['filename_key'])->orWhere('display_name_key', $keys['display_name_key']))->exists();
                if ($exists) {
                    $suffix++;
                    $title = mb_substr($d->title, 0, 180).' ('.$suffix.')';
                    $ext = pathinfo($v->filename, PATHINFO_EXTENSION);
                    $filename = mb_substr(pathinfo($v->filename, PATHINFO_FILENAME), 0, 175).' ('.$suffix.')'.($ext ? '.'.$ext : '');
                }
            } while ($exists);
            DB::table('documents')->where('id', $d->id)->update($keys + ['title'=>$title]);
            if ($filename !== $v->filename) DB::table('document_versions')->where('id', $v->id)->update(['filename'=>$filename]);
        }
        DB::table('document_versions')->whereIn('scan', ['Pending','Failed'])->update(['scan'=>'Not required','status'=>'Available']);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropUnique(['filename_key']);
            $t->dropUnique(['display_name_key']);
            $t->dropColumn(['filename_key','display_name_key']);
        });
    }
};
