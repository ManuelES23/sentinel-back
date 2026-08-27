<?php
// sentinel-back/app/Console/Commands/PurgeBiometricDataCommand.php
namespace App\Console\Commands;

use App\Models\SfEmployeeFaceTemplate;
use App\Models\SfFieldCheck;
use App\Models\TimeClockCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeBiometricDataCommand extends Command
{
    protected $signature = 'biometrics:purge';

    protected $description = 'Purga fotos de evidencia y de plantillas revocadas que superaron su plazo de retención (LFPDPPP)';

    public function handle(): int
    {
        $evidencePurged = $this->purgeEvidencePhotos();
        $timeClockEvidencePurged = $this->purgeTimeClockCheckEvidence();
        $templatesPurged = $this->purgeRevokedTemplates();

        // Solo se loguean conteos, nunca contenido ni rutas de archivo —
        // dato biométrico sensible incluso en logs de operación.
        $this->info("Fotos de evidencia purgadas: {$evidencePurged}");
        $this->info("Fotos de evidencia de checador purgadas: {$timeClockEvidencePurged}");
        $this->info("Plantillas revocadas purgadas: {$templatesPurged}");

        return self::SUCCESS;
    }

    /**
     * Checks en estado terminal-resuelto (verified/manually_approved/rejected),
     * con created_at más viejo que el plazo configurado, y que aún tienen una
     * foto (no purgados ya). Nunca toca pending/low_confidence/mismatch/no_template
     * — un check aún no resuelto por un humano no debe perder su evidencia.
     */
    private function purgeEvidencePhotos(): int
    {
        $cutoff = now()->subDays((int) config('biometrics.evidence_retention_days'));
        $resolvedStatuses = [
            SfFieldCheck::STATUS_VERIFIED,
            SfFieldCheck::STATUS_MANUALLY_APPROVED,
            SfFieldCheck::STATUS_REJECTED,
        ];

        $checks = SfFieldCheck::whereIn('verification_status', $resolvedStatuses)
            ->whereNotNull('evidence_photo_path')
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;
        foreach ($checks as $check) {
            if (Storage::disk('local')->exists($check->evidence_photo_path)) {
                Storage::disk('local')->delete($check->evidence_photo_path);
            }
            $check->update(['evidence_photo_path' => null]);
            $count++;
        }

        return $count;
    }

    /**
     * Checks de checador (time_clock_checks) en estado terminal-resuelto
     * (verified/manually_approved/rejected), con created_at más viejo que el
     * plazo configurado, y que aún tienen una foto (no purgados ya). Mismo
     * criterio que purgeEvidencePhotos() para sf_field_checks — nunca toca
     * pending/low_confidence/no_template.
     *
     * El corte usa created_at (server-side, inmutable), NO checked_at
     * (dato del dispositivo del empleado, potencialmente incorrecto según
     * el propio spec de este feature) — mismo criterio que
     * purgeEvidencePhotos() arriba.
     */
    private function purgeTimeClockCheckEvidence(): int
    {
        $cutoff = now()->subDays((int) config('biometrics.evidence_retention_days'));
        $resolvedStatuses = [
            TimeClockCheck::STATUS_VERIFIED,
            TimeClockCheck::STATUS_MANUALLY_APPROVED,
            TimeClockCheck::STATUS_REJECTED,
        ];

        $checks = TimeClockCheck::whereIn('verification_status', $resolvedStatuses)
            ->whereNotNull('evidence_photo_path')
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;
        foreach ($checks as $check) {
            if (Storage::disk('local')->exists($check->evidence_photo_path)) {
                Storage::disk('local')->delete($check->evidence_photo_path);
            }
            $check->update(['evidence_photo_path' => null]);
            $count++;
        }

        return $count;
    }

    /**
     * Plantillas revocadas cuyo revoked_at superó el plazo configurado y aún
     * tienen foto o embedding. El embedding se limpia en el mismo paso — ya
     * no tiene uso legítimo una vez revocada, y es el dato biométrico más
     * sensible del sistema.
     */
    private function purgeRevokedTemplates(): int
    {
        $cutoff = now()->subDays((int) config('biometrics.revoked_template_retention_days'));

        $templates = SfEmployeeFaceTemplate::where('status', SfEmployeeFaceTemplate::STATUS_REVOKED)
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('photo_path')->orWhereNotNull('embedding');
            })
            ->get();

        $count = 0;
        foreach ($templates as $template) {
            if ($template->photo_path && Storage::disk('local')->exists($template->photo_path)) {
                Storage::disk('local')->delete($template->photo_path);
            }
            $template->update(['photo_path' => null, 'embedding' => null]);
            $count++;
        }

        return $count;
    }
}
