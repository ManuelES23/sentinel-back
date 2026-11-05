<?php

namespace App\Services\Abastecimiento;

use Illuminate\Validation\ValidationException;

/**
 * Reglas de los precios de un convenio (modalidad, vigencias repetidas y tipo
 * de carga principal). Trabaja sobre las filas ya validadas en tipo por el
 * controlador; aquí solo viven las reglas de negocio.
 */
class ReglasConvenio
{
    /**
     * Lanza ValidationException con la clave `precios.N.campo` del primer renglón culpable.
     *
     * @param  array<int, array<string, mixed>>  $precios
     */
    public static function validarPrecios(array $precios, string $modalidad): void
    {
        $errores = [];
        $vistos = [];
        $principales = []; // tipo_carga_id => primer índice marcado

        foreach (array_values($precios) as $i => $precio) {
            if ($modalidad === 'consignacion') {
                $pct = $precio['porcentaje_productor'] ?? null;
                if ($pct === null || $pct === '') {
                    $errores["precios.{$i}.porcentaje_productor"] = 'Indica el porcentaje que corresponde al productor.';
                } elseif (! is_numeric($pct) || $pct < 0 || $pct > 100) {
                    $errores["precios.{$i}.porcentaje_productor"] = 'El porcentaje del productor debe estar entre 0 y 100.';
                }
            }

            $clave = ($precio['tipo_carga_id'] ?? '').'|'.($precio['vigencia_inicio'] ?? '');
            if (isset($vistos[$clave])) {
                $errores["precios.{$i}.vigencia_inicio"] = 'Ya hay un precio de este tipo de carga con esa fecha de vigencia.';
            }
            $vistos[$clave] = true;

            if (self::marcado($precio)) {
                $principales[$precio['tipo_carga_id']] ??= $i;
            }
        }

        if (count($principales) > 1) {
            $indices = array_values($principales);
            sort($indices);
            foreach (array_slice($indices, 1) as $i) {
                $errores["precios.{$i}.es_principal"] = 'Solo un tipo de carga puede ser el principal.';
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Filas listas para guardar: el porcentaje del productor solo existe en
     * consignación y `es_principal` identifica un TIPO de carga (todas sus
     * vigencias comparten el valor; el primer tipo de la lista si ninguno se marcó).
     *
     * @param  array<int, array<string, mixed>>  $precios
     * @return array<int, array<string, mixed>>
     */
    public static function normalizarPrecios(array $precios, string $modalidad): array
    {
        $precios = array_values($precios);
        $principal = null;
        foreach ($precios as $precio) {
            if (self::marcado($precio)) {
                $principal = (int) $precio['tipo_carga_id'];
                break;
            }
        }
        $principal ??= isset($precios[0]) ? (int) $precios[0]['tipo_carga_id'] : null;

        return array_map(fn (array $p) => [
            'tipo_carga_id' => (int) $p['tipo_carga_id'],
            'precio' => $p['precio'],
            'porcentaje_productor' => $modalidad === 'consignacion' ? ($p['porcentaje_productor'] ?? null) : null,
            'vigencia_inicio' => $p['vigencia_inicio'],
            'es_principal' => (int) $p['tipo_carga_id'] === $principal,
            'notas' => $p['notas'] ?? null,
        ], $precios);
    }

    private static function marcado(array $precio): bool
    {
        return filter_var($precio['es_principal'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
