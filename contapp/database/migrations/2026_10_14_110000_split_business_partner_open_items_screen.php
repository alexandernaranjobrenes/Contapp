<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Aplicar cobros y pagos pasa a ser su propia pantalla,
     * business_partners.open_items, aparte de la ficha del socio
     * (separación de funciones, CLAUDE.md secc. 36).
     *
     * Nadie pierde lo que tenía: quien tenía la pantalla de socios queda con
     * el mismo nivel en la nueva, y lo mismo las invitaciones pendientes.
     * Después, el Superusuario puede separar.
     */
    private const FROM = 'business_partners.partners';

    private const TO = 'business_partners.open_items';

    public function up(): void
    {
        $now = now();

        foreach (DB::table('screen_permissions')->where('screen', self::FROM)->get() as $row) {
            $exists = DB::table('screen_permissions')
                ->where('company_id', $row->company_id)->where('user_id', $row->user_id)->where('screen', self::TO)
                ->exists();

            if (! $exists) {
                DB::table('screen_permissions')->insert([
                    'company_id' => $row->company_id,
                    'user_id' => $row->user_id,
                    'screen' => self::TO,
                    'access_level' => $row->access_level,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (DB::table('company_invitations')->whereNotNull('screen_permissions')->get(['id', 'screen_permissions']) as $invitation) {
            $screens = json_decode($invitation->screen_permissions, true) ?: [];

            if (isset($screens[self::FROM]) && ! isset($screens[self::TO])) {
                $screens[self::TO] = $screens[self::FROM];
                DB::table('company_invitations')->where('id', $invitation->id)->update(['screen_permissions' => json_encode($screens)]);
            }
        }
    }

    public function down(): void
    {
        DB::table('screen_permissions')->where('screen', self::TO)->delete();

        foreach (DB::table('company_invitations')->whereNotNull('screen_permissions')->get(['id', 'screen_permissions']) as $invitation) {
            $screens = json_decode($invitation->screen_permissions, true) ?: [];

            if (isset($screens[self::TO])) {
                unset($screens[self::TO]);
                DB::table('company_invitations')->where('id', $invitation->id)->update(['screen_permissions' => json_encode($screens)]);
            }
        }
    }
};
