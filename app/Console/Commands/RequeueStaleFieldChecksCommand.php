<?php
// sentinel-back/app/Console/Commands/RequeueStaleFieldChecksCommand.php
namespace App\Console\Commands;

use App\Jobs\VerifyFieldCheckJob;
use App\Jobs\VerifyTimeClockCheckJob;
use App\Models\SfFieldCheck;
use App\Models\TimeClockCheck;
use Illuminate\Console\Command;

class RequeueStaleFieldChecksCommand extends Command
{
    protected $signature = 'biometrics:requeue-stale-checks';

    protected $description = 'Re-despacha VerifyFieldCheckJob y VerifyTimeClockCheckJob para checks atascados en pending más allá de la ventana normal de reintento';

    /**
     * STATUS_PENDING solo se escribe en SfFieldCheckController::sync() y solo
     * tiene dos salidas normales: VerifyFieldCheckJob::handle() completa, o
     * su failed() se dispara tras agotar los 5 reintentos (~21.5min de
     * ventana total, ver backoff()). No hay ningún camino que degrade un
     * check si el worker muere a media ventana, se pierde la fila del job
     * (queue:clear/flush, truncar 'jobs'), o cualquier otro fallo que
     * impida que failed() llegue a dispararse — quedaría invisible tanto en
     * la bandeja de revisión como en la campanita de aprobaciones
     * (RevisionAsistenciaSfView / review() solo actúan sobre
     * low_confidence/mismatch/no_template), violando "nunca se pierde el
     * evento" (spec biometría de campo).
     *
     * Este comando es el sweeper de ese hueco: re-despacha el mismo job para
     * todo pending más viejo que el umbral configurado, dejando que
     * handle()/failed() —la única fuente de verdad de transiciones fuera de
     * pending— terminen de resolverlo por el camino normal. Se prefirió este
     * enfoque sobre permitir que review() actúe también sobre pending (y
     * sumarlos a la campanita) porque un pending viejo no tiene ningún dato
     * de verificación (server_confidence sigue null, nunca se llamó al
     * face-service) — no hay nada real que un humano pueda revisar ahí, la
     * única acción con sentido es reintentar la verificación real.
     *
     * Idempotente por diseño: si el check sigue pending y viejo en la
     * siguiente corrida (p. ej. el worker sigue caído), se re-despacha de
     * nuevo — no se marca como "ya procesado" tras un solo intento.
     */
    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) config('biometrics.stale_pending_requeue_minutes'));

        $staleChecks = SfFieldCheck::where('verification_status', SfFieldCheck::STATUS_PENDING)
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($q) use ($cutoff) {
                    $q->whereNotNull('synced_at')->where('synced_at', '<', $cutoff);
                })->orWhere(function ($q) use ($cutoff) {
                    $q->whereNull('synced_at')->where('created_at', '<', $cutoff);
                });
            })
            ->get();

        foreach ($staleChecks as $check) {
            VerifyFieldCheckJob::dispatch($check->id);
        }

        $staleTimeClockChecks = TimeClockCheck::where('verification_status', TimeClockCheck::STATUS_PENDING)
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($q) use ($cutoff) {
                    $q->whereNotNull('synced_at')->where('synced_at', '<', $cutoff);
                })->orWhere(function ($q) use ($cutoff) {
                    $q->whereNull('synced_at')->where('created_at', '<', $cutoff);
                });
            })
            ->get();

        foreach ($staleTimeClockChecks as $check) {
            VerifyTimeClockCheckJob::dispatch($check->id);
        }

        $this->info("SfFieldCheck pending re-despachados: {$staleChecks->count()}, TimeClockCheck pending re-despachados: {$staleTimeClockChecks->count()}");

        return self::SUCCESS;
    }
}
