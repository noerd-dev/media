<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        // Create MEDIA app if it does not exist
        $exists = DB::table('tenant_apps')->where('name', 'MEDIA')->exists();
        if (!$exists) {
            DB::table('tenant_apps')->insert([
                'title' => 'Media',
                'name' => 'MEDIA',
                'icon' => 'icons.media',
                'route' => 'media.dashboard',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Assign MEDIA app to the first tenant
        $tenantId = DB::table('tenants')->orderBy('id')->value('id');
        $appId = DB::table('tenant_apps')->where('name', 'MEDIA')->value('id');
        if ($tenantId && $appId) {
            $pivotTable = DB::getSchemaBuilder()->hasTable('tenant_app') ? 'tenant_app' : 'tenant_app_tenant';
            $existsPivot = DB::table($pivotTable)
                ->where('tenant_id', $tenantId)
                ->where('tenant_app_id', $appId)
                ->exists();
            if (!$existsPivot) {
                DB::table($pivotTable)->insert([
                    'tenant_id' => $tenantId,
                    'tenant_app_id' => $appId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // do not remove app or assignment in down to avoid accidental data loss
    }
};


