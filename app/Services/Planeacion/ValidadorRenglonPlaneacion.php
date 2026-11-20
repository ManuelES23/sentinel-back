<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\GrupoBalance;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\TipoEmpaque;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de negocio comunes a los renglones de las hojas de Necesidades y de
 * Sobrantes: presentación activa (y su cultivo), color (variedades o grupo de
 * balance, del cultivo y de un solo grupo), temporada abierta para subir
 * cajas, y bodega y tipo de empaque activos cuando un cambio los modifica.
 *
 * Cada falla es una `ValidationException` con la clave del campo que se le
 * pasa. Se crea por petición: el servicio de la semana guarda cálculos en
 * memoria.
 */
class ValidadorRenglonPlaneacion
{
    public const MENSAJE_GRUPO = 'Las variedades deben ser del mismo grupo de balance';

    public const MENSAJE_TEMPORADA = 'El cultivo no tiene una temporada abierta en esta semana.';

    public function __construct(private readonly ProgramaSemanalService $servicio) {}

    /**
     * Presentaciones que mencionan las filas (nuevas y cambios), por id.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return Collection<int, Presentacion>
     */
    public function presentaciones(array $filas): Collection
    {
        $ids = [];
        foreach ($filas as $fila) {
            if (isset($fila['presentacion_id'])) {
                $ids[(int) $fila['presentacion_id']] = true;
            }
        }

        return Presentacion::whereIn('id', array_keys($ids))
            ->get(['id', 'cultivo_id', 'is_active'])
            ->keyBy('id');
    }

    /** @param  Collection<int, Presentacion>  $presentaciones */
    public function presentacionActiva(Collection $presentaciones, int $id, string $campo): Presentacion
    {
        $presentacion = $presentaciones->get($id);
        if (! $presentacion || ! $presentacion->is_active) {
            throw ValidationException::withMessages([$campo => 'La presentación no existe o está desactivada.']);
        }

        return $presentacion;
    }

    public function exigirBodegaActiva(int $id, string $campo): void
    {
        if (! BodegaDestino::whereKey($id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([$campo => 'La bodega no existe o está desactivada.']);
        }
    }

    public function exigirTipoEmpaqueActivo(int $id, string $campo): void
    {
        if (! TipoEmpaque::whereKey($id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([$campo => 'El tipo de empaque no existe o está desactivado.']);
        }
    }

    public function exigirTemporadaAbierta(ProgramaSemanal $programa, int $cultivoId, string $campo): void
    {
        if (! $this->servicio->temporadasDeSemana($programa)->contains(fn ($t) => (int) $t->cultivo_id === $cultivoId)) {
            throw ValidationException::withMessages([$campo => self::MENSAJE_TEMPORADA]);
        }
    }

    /**
     * Cultivo del color que manda la fila (el de su primera variedad o el de
     * su grupo), o null si no manda color o no se puede saber. Sirve para
     * marcar en la presentación un renglón cuyo color es de otro cultivo.
     */
    public function cultivoDelColor(array $fila): ?int
    {
        if (! empty($fila['variedad_ids'])) {
            return $this->servicio->cultivoDeVariedad((int) array_values($fila['variedad_ids'])[0]);
        }
        if (! isset($fila['grupo_id'])) {
            return null;
        }

        $clave = (string) $fila['grupo_id'];
        if (str_starts_with($clave, 'v')) {
            return $this->servicio->cultivoDeVariedad((int) substr($clave, 1));
        }
        $cultivo = GrupoBalance::whereKey((int) $clave)->value('cultivo_id');

        return $cultivo !== null ? (int) $cultivo : null;
    }

    /**
     * Variedades del color de la fila: las que manda, o las de su grupo de
     * balance, comprobadas contra el cultivo y que caigan en un solo grupo.
     *
     * @return list<int>
     */
    public function resolverVariedades(array $fila, int $cultivoId, string $ruta): array
    {
        if (! empty($fila['variedad_ids'])) {
            return $this->exigirMismoGrupo(array_map('intval', $fila['variedad_ids']), $cultivoId, "$ruta.variedad_ids");
        }

        $clave = (string) $fila['grupo_id'];
        if (str_starts_with($clave, 'v')) {
            $ids = [(int) substr($clave, 1)];
        } else {
            $grupo = GrupoBalance::with('variedades:id')->find((int) $clave);
            if (! $grupo || (int) $grupo->cultivo_id !== $cultivoId) {
                throw ValidationException::withMessages(["$ruta.grupo_id" => 'El grupo no existe en el cultivo de la presentación.']);
            }
            $ids = $grupo->variedades->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                throw ValidationException::withMessages(["$ruta.grupo_id" => 'El grupo no tiene variedades.']);
            }
        }

        return $this->exigirMismoGrupo($ids, $cultivoId, "$ruta.grupo_id");
    }

    /**
     * Todas las variedades deben existir, ser del cultivo de la presentación
     * y caer en el mismo grupo de balance.
     *
     * @param  list<int>  $variedadIds
     * @return list<int> ordenadas
     */
    public function exigirMismoGrupo(array $variedadIds, int $cultivoId, string $campo): array
    {
        $grupos = [];
        foreach ($variedadIds as $variedadId) {
            if ($this->servicio->cultivoDeVariedad($variedadId) !== $cultivoId) {
                throw ValidationException::withMessages([$campo => self::MENSAJE_GRUPO]);
            }
            $grupos[$this->servicio->grupoDeVariedad($variedadId)] = true;
        }

        if (count($grupos) !== 1) {
            throw ValidationException::withMessages([$campo => self::MENSAJE_GRUPO]);
        }

        sort($variedadIds);

        return array_values($variedadIds);
    }
}
