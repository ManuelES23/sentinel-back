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
                // Se revisa de nuevo bajo el bloqueo: el convenio pudo pasar a por calibre después de validar,
                // y una cabecera sin renglones en un convenio por calibre no cuenta en indicadores ni se valora.
                if ($convenio->usa_calibres) {
                    throw ValidationException::withMessages([
                        'archivo' => "El convenio {$convenio->folio} ahora es por calibre: la importación por calibre llegará con el formato de Excel. Revisa el convenio y vuelve a subir el archivo.",
                    ]);
                }
            }

            // Orden de bloqueo: convenios (compartido, por id) → contador de folios → lectura de tickets.
            // Un solo bloqueo y un solo UPDATE del contador reservan los N folios; desde aquí y hasta el
            // commit otra importación o un alta manual esperan.
            $folios = $this->folios->reservar($empresa, 'RC', count($filasValidas));
            $this->exigirTicketsLibres($convenioIds, $filasValidas);

            foreach ($filasValidas as $indice => $fila) {
                Recepcion::create($fila['datos'] + [
                    'enterprise_id' => $empresa->id,
                    'folio' => $folios[$indice],
                    'created_by' => $usuario->id,
                ]);
            }

            return [
                'importadas' => count($filasValidas),
                'convenio_ids' => $convenioIds,
                'folios' => ['primero' => $folios[0], 'ultimo' => $folios[count($folios) - 1]],
            ];
        }, 3);
    }

    /**
     * Revisa los tickets del archivo contra lo ya confirmado en esos convenios.
     *
     * Es una lectura CON bloqueo (`sharedLock`) a propósito: MySQL corre en REPEATABLE READ y la primera
     * lectura sin bloqueo de la transacción (el `exists()` del contador de folios) fija el snapshot ANTES
     * de esperar el bloqueo del contador. Una lectura normal vería ese snapshot viejo y no los tickets que
     * otra importación confirmó mientras esta esperaba; una lectura con bloqueo lee lo último confirmado.
     * No crea un orden de bloqueo nuevo: el contador ya serializa a los escritores.
     *
     * @param  list<int>  $convenioIds
     */
    private function exigirTicketsLibres(array $convenioIds, array $filasValidas): void
    {
        if (collect($filasValidas)->pluck('datos.ticket_proveedor')->filter()->isEmpty()) {
            return;
        }

        $existentes = [];
        Recepcion::whereIn('convenio_id', $convenioIds)->whereNotNull('ticket_proveedor')
            ->sharedLock()->orderBy('id')
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
