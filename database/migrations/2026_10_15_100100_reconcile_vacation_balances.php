<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Los días usados y pendientes dejan de leerse de los contadores de
 * vacation_balances (se descuadraban) y salen de las solicitudes. Para que
 * ningún empleado vea cambiar su saldo el día del despliegue, la diferencia
 * se convierte en un ajuste del balance del año en curso. Después alinea los
 * contadores con las solicitudes, así que correrla de nuevo no ajusta nada.
 */
return new class extends Migration
{
    public const MOTIVO = 'Conciliación al migrar (portal de vacaciones)';

    public function up(): void
    {
        $anio = (int) now()->year;
        $solicitudes = fn () => DB::table('vacation_requests')->whereNull('deleted_at');

        $empleados = DB::table('vacation_balances')->distinct()->pluck('employee_id')
            ->merge($solicitudes()->whereIn('status', ['approved', 'pending'])->distinct()->pluck('employee_id'))
            ->unique()
            ->values();

        $ajustados = [];

        foreach ($empleados as $empleadoId) {
            $contadores = (int) DB::table('vacation_balances')->where('employee_id', $empleadoId)->sum(DB::raw('used_days + pending_days'));
            $usados = (int) $solicitudes()->where('employee_id', $empleadoId)->where('status', 'approved')->sum('days_requested');
            $pendientes = (int) $solicitudes()->where('employee_id', $empleadoId)->where('status', 'pending')->sum('days_requested');
            $diferencia = $contadores - ($usados + $pendientes);

            DB::table('vacation_balances')->where('employee_id', $empleadoId)->update(['used_days' => 0, 'pending_days' => 0]);

            $balance = DB::table('vacation_balances')->where('employee_id', $empleadoId)->where('year', $anio)->first();
            if (! $balance) {
                $id = DB::table('vacation_balances')->insertGetId([
                    'employee_id' => $empleadoId, 'year' => $anio, 'entitled_days' => 0, 'used_days' => 0,
                    'pending_days' => 0, 'carried_over' => 0, 'adjustment_days' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $balance = DB::table('vacation_balances')->find($id);
            }

            $cambios = ['used_days' => $usados, 'pending_days' => $pendientes, 'updated_at' => now()];

            if ($diferencia !== 0) {
                $ajuste = -$diferencia;
                $cambios['adjustment_days'] = (int) ($balance->adjustment_days ?? 0) + $ajuste;
                // adjustment_reason es string(255).
                $cambios['adjustment_reason'] = mb_substr(
                    ($balance->adjustment_reason ? $balance->adjustment_reason.' | ' : '').self::MOTIVO.' ('.($ajuste > 0 ? '+' : '').$ajuste.')',
                    0,
                    255,
                );
                $cambios['adjusted_at'] = now();
                $ajustados[] = ['employee_id' => (int) $empleadoId, 'ajuste' => $ajuste];
            }

            DB::table('vacation_balances')->where('id', $balance->id)->update($cambios);
        }

        Log::info('Conciliación de saldos de vacaciones', [
            'empleados_revisados' => $empleados->count(),
            'empleados_ajustados' => count($ajustados),
            'ajuste_total' => array_sum(array_column($ajustados, 'ajuste')),
            'detalle' => $ajustados,
        ]);
    }

    public function down(): void
    {
        // Sin reversa: los ajustes quedan registrados con su motivo.
    }
};
