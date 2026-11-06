<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guarda de una sola vez las recepciones que ValidadorImportacionRecepciones dejó listas
 * (todo o nada). Corre en una transacción: bloquea los convenios implicados (lectura
 * compartida, como el alta manual, en orden de id para no cruzarse con otra importación),
 * confirma que siguen activos y crea las recepciones en el orden del archivo con folios
 * consecutivos. No difunde nada: el controlador lo hace después del commit.
 */
class ImportadorRecepciones
{
    public function __construct(private GeneradorFolio $folios) {}

    /**
     * @param  list<array{fila: int, datos: array}>  $filasValidas  renglones con estado «lista», en el orden del archivo
     * @return array{importadas: int, convenio_ids: list<int>, folios: array{primero: ?string, ultimo: ?string}}
     */
    public function importar(Enterprise $empresa, User $usuario, array $filasValidas): array
    {
        $filasValidas = array_values($filasValidas);
        if ($filasValidas === []) {
            return ['importadas' => 0, 'convenio_ids' => [], 'folios' => ['primero' => null, 'ultimo' => null]];
        }

        $convenioIds = collect($filasValidas)->pluck('datos.convenio_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return DB::transaction(function () use ($empresa, $usuario, $filasValidas, $convenioIds) {
            $convenios = Convenio::deEmpresa($empresa->id)
                ->whereIn('id', $convenioIds)
                ->orderBy('id')
                ->sharedLock()
                ->get()
                ->keyBy('id');

            foreach ($convenioIds as $id) {
                $convenio = $convenios->get($id);
                if ($convenio === null || $convenio->status !== 'activo') {
                    $folio = $convenio?->folio ?? "#{$id}";
                    throw ValidationException::withMessages([
                        'archivo' => "El convenio {$folio} ya no está activo; revisa el convenio y vuelve a subir el archivo.",
                    ]);
                }
            }

            $primero = $ultimo = null;
            foreach ($filasValidas as $indice => $fila) {
                $folio = $this->folios->siguiente($empresa, 'RC');
                if ($indice === 0) {
                    // El contador de folios está bloqueado desde aquí hasta el commit: otra importación o un
                    // alta manual espera. Con esa exclusión se revisan los tickets contra lo ya confirmado,
                    // por si alguien los registró entre la vista previa y este guardado.
                    $this->exigirTicketsLibres($convenioIds, $filasValidas);
                }

                Recepcion::create($fila['datos'] + [
                    'enterprise_id' => $empresa->id,
                    'folio' => $folio,
                    'created_by' => $usuario->id,
                ]);
                $primero ??= $folio;
                $ultimo = $folio;
            }

            return [
                'importadas' => count($filasValidas),
                'convenio_ids' => $convenioIds,
                'folios' => ['primero' => $primero, 'ultimo' => $ultimo],
            ];
        }, 3);
    }

    /** @param list<int> $convenioIds */
    private function exigirTicketsLibres(array $convenioIds, array $filasValidas): void
    {
        $existentes = [];
        Recepcion::whereIn('convenio_id', $convenioIds)->whereNotNull('ticket_proveedor')->orderBy('id')
            ->get(['id', 'convenio_id', 'ticket_proveedor', 'folio'])
            ->each(function (Recepcion $r) use (&$existentes) {
                $existentes[$r->convenio_id.'|'.ValidadorImportacionRecepciones::normalizarTicket($r->ticket_proveedor)] ??= $r->folio;
            });
        if ($existentes === []) {
            return;
        }

        foreach ($filasValidas as $fila) {
            $ticket = $fila['datos']['ticket_proveedor'] ?? null;
            if ($ticket !== null && isset($existentes[$fila['datos']['convenio_id'].'|'.ValidadorImportacionRecepciones::normalizarTicket($ticket)])) {
                throw ValidationException::withMessages([
                    'archivo' => "El ticket «{$ticket}» (renglón {$fila['fila']}) se registró mientras se importaba; vuelve a subir el archivo.",
                ]);
            }
        }
    }
}
