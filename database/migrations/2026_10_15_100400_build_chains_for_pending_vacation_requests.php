<?php

use App\Models\VacationRequest;
use App\Services\RH\CadenaAprobacionVacaciones;
use Illuminate\Database\Migrations\Migration;

/**
 * Las solicitudes que estaban pendientes al desplegar reciben su cadena con
 * la configuración vigente y empiezan en el paso 1 (o se saltan los pasos
 * sin aprobador). No manda avisos. Re-ejecutable: solo toca las que no
 * tienen cadena.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cadena = app(CadenaAprobacionVacaciones::class);

        VacationRequest::with('employee')
            ->where('status', 'pending')
            ->whereDoesntHave('approvals')
            ->orderBy('id')
            ->chunkById(100, function ($solicitudes) use ($cadena) {
                foreach ($solicitudes as $solicitud) {
                    DB::transaction(fn () => $cadena->construir($solicitud));
                }
            });
    }

    public function down(): void
    {
        // Se conservan: borrarlas dejaría solicitudes pendientes sin paso.
    }
};
