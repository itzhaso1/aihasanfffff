<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'website_domain_reminder_logs',
            'website_domain_contacts',
            'website_domain_operations',
            'website_assets',
            'website_sections',
            'website_pages',
            'website_domains',
            'websites',
            'website_templates',
            'appointment_reminders',
            'appointment_booking_resources',
            'appointment_request_slots',
            'appointment_holidays',
            'appointment_service_staff',
            'appointment_bookings',
            'appointment_requests',
            'appointment_resources',
            'appointment_staff',
            'appointment_services',
            'appointment_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        $this->retireEntitlements();
    }

    public function down(): void
    {
        // Intentionally irreversible: retired website/appointment structures are not reconstructed.
    }

    private function retireEntitlements(): void
    {
        $retiredFeatures = ['website_builder', 'website', 'custom_domains', 'custom_domain'];
        $retiredMeters = ['websites', 'domains'];

        if (Schema::hasTable('plans')) {
            DB::table('plans')->orderBy('id')->chunkById(100, function ($plans) use ($retiredFeatures, $retiredMeters): void {
                foreach ($plans as $plan) {
                    $updates = [];

                    if (Schema::hasColumn('plans', 'features')) {
                        $features = $this->decodeArray($plan->features ?? null);
                        $updates['features'] = json_encode(
                            array_values(array_diff($features, $retiredFeatures)),
                            JSON_UNESCAPED_UNICODE
                        );
                    }

                    if (Schema::hasColumn('plans', 'permissions')) {
                        $permissions = $this->decodeArray($plan->permissions ?? null);
                        $updates['permissions'] = json_encode(
                            array_values(array_filter(
                                $permissions,
                                fn ($permission): bool => ! str_starts_with((string) $permission, 'website')
                                    && ! str_starts_with((string) $permission, 'appointments.')
                            )),
                            JSON_UNESCAPED_UNICODE
                        );
                    }

                    if (Schema::hasColumn('plans', 'limits')) {
                        $limits = $this->decodeObject($plan->limits ?? null);
                        foreach ($retiredMeters as $meter) {
                            unset($limits[$meter]);
                        }
                        $updates['limits'] = json_encode($limits, JSON_UNESCAPED_UNICODE);
                    }

                    if ($updates !== []) {
                        DB::table('plans')->where('id', $plan->id)->update($updates);
                    }
                }
            });
        }

        if (Schema::hasTable('workspace_feature_flags')) {
            DB::table('workspace_feature_flags')->whereIn('feature_key', $retiredFeatures)->delete();
        }

        if (Schema::hasTable('workspace_usage_meters')) {
            DB::table('workspace_usage_meters')->whereIn('meter_key', $retiredMeters)->delete();
        }

        if (Schema::hasTable('plan_addons')) {
            DB::table('plan_addons')->orderBy('id')->chunkById(100, function ($addons) use ($retiredFeatures, $retiredMeters): void {
                foreach ($addons as $addon) {
                    $grants = $this->decodeObject($addon->grants ?? null);
                    $features = is_array($grants['features'] ?? null) ? $grants['features'] : [];
                    $limits = is_array($grants['limits'] ?? null) ? $grants['limits'] : [];

                    $grants['features'] = array_values(array_diff($features, $retiredFeatures));
                    foreach ($retiredMeters as $meter) {
                        unset($limits[$meter]);
                    }
                    $grants['limits'] = $limits;

                    $updates = ['grants' => json_encode($grants, JSON_UNESCAPED_UNICODE)];
                    if (in_array($addon->meter_key ?? null, $retiredMeters, true)) {
                        $updates['meter_key'] = null;
                        $updates['is_active'] = false;
                    }

                    DB::table('plan_addons')->where('id', $addon->id)->update($updates);
                }
            });
        }

        $this->removeWebsitePermissions();
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeArray(mixed $value): array
    {
        $decoded = $this->decodeObject($value);

        return array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @return array<mixed>
     */
    private function decodeObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function removeWebsitePermissions(): void
    {
        $permissionTable = config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($permissionTable)) {
            return;
        }

        $ids = DB::table($permissionTable)
            ->where(function ($query): void {
                $query->where('name', 'like', 'website%')
                    ->orWhere('name', 'like', 'appointments.%');
            })
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        foreach ([
            config('permission.table_names.role_has_permissions', 'role_has_permissions'),
            config('permission.table_names.model_has_permissions', 'model_has_permissions'),
        ] as $pivotTable) {
            if (Schema::hasTable($pivotTable)) {
                DB::table($pivotTable)->whereIn('permission_id', $ids)->delete();
            }
        }

        DB::table($permissionTable)->whereIn('id', $ids)->delete();
    }
};
