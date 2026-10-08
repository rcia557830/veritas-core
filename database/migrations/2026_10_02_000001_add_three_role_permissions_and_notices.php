<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->string('slug')->nullable()->unique());
        // Preserve user IDs, passwords and assignments when upgrading the original roles.
        foreach (DB::table('roles')->get() as $role) {
            $name = ['Administrator' => 'Owner', 'Staff' => 'Bookkeeper'][$role->name] ?? $role->name;
            DB::table('roles')->where('id', $role->id)->update(['name' => $name, 'slug' => Str::slug($name)]);
        }
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['permission_id', 'role_id']);
        });
        Schema::create('notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('title');
            $t->text('body');
            $t->string('status')->default('Draft')->index();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        DB::table('roles')->where('slug', 'owner')->update(['name' => 'Administrator']);
        DB::table('roles')->where('slug', 'bookkeeper')->update(['name' => 'Staff']);
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('slug'));
    }
};
