<?php

namespace App\Services\Planeacion;

use App\Models\CRM\CrmCliente;
use App\Models\Enterprise;
use App\Models\Planeacion\PlanCliente;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lista propia de clientes de Planeación: clave de comparación, resolver (y
 * registrar) el cliente de un nombre, sugerencias y «Traer del CRM».
 *
 * Carrera de dos guardados que registran el mismo cliente: el alta choca con
 * la unicidad de `nombre_clave` (en InnoDB el INSERT espera a que la otra
 * transacción confirme y entonces falla). En MySQL/InnoDB y en SQLite un
 * error de llave duplicada solo deshace esa sentencia, no la transacción de la
 * hoja (a diferencia de PostgreSQL, por eso no hace falta un savepoint), así
 * que se captura `UniqueConstraintViolationException` y se relee al ganador
 * con una lectura con bloqueo (`lockForUpdate`): en InnoDB ve lo ya confirmado
 * aunque la transacción tenga una foto REPEATABLE READ anterior (una lectura
 * normal no lo vería). La búsqueda previa por clave es una lectura normal:
 * bloquearla tomaría un bloqueo de hueco y dos hojas que registran clientes
 * distintos podrían interbloquearse (1213).
 */
final class ClientesPlaneacion
{
    public const MAX_NOMBRE = 150;

    public const LIMITE_SUGERENCIAS = 20;

    /**
     * Clave de comparación: minúsculas, sin acentos, espacios colapsados y
     * recortados (máximo 255). La migración `2026_11_23_110000` tiene una
     * copia exacta: si esto cambia, cambia también allá (hay una prueba).
     */
    public static function clave(string $nombre): string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', trim($nombre)));

        return mb_substr(trim(Str::lower(Str::ascii($limpio))), 0, 255);
    }

    /** Nombre como se guarda: recortado y con los espacios repetidos colapsados. */
    public static function limpiar(string $nombre): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim($nombre)));
    }

    /**
     * Cliente por id (activo o no; lo reactiva) o, si el id no existe o no
     * viene, por clave del nombre; si no existe, lo crea. 422 en `nombre` si
     * el texto queda vacío, sin letras ni números, o pasa de 150 caracteres.
     */
    public function resolver(string $nombre, ?int $planClienteId = null): PlanCliente
    {
        if ($planClienteId !== null) {
            $cliente = PlanCliente::find($planClienteId);
            if ($cliente !== null) {
                return $this->activar($cliente);
            }
        }

        $limpio = self::limpiar($nombre);
        if ($limpio === '') {
            throw ValidationException::withMessages(['nombre' => 'El cliente es obligatorio.']);
        }
        if (mb_strlen($limpio) > self::MAX_NOMBRE) {
            throw ValidationException::withMessages(['nombre' => 'El nombre del cliente no puede pasar de '.self::MAX_NOMBRE.' caracteres.']);
        }
        $clave = self::clave($limpio);
        if ($clave === '') {
            throw ValidationException::withMessages(['nombre' => 'El nombre del cliente debe tener letras o números.']);
        }

        $existente = PlanCliente::where('nombre_clave', $clave)->first();
        if ($existente !== null) {
            return $this->activar($existente);
        }

        try {
            return PlanCliente::create([
                'nombre' => $limpio,
                'nombre_clave' => $clave,
                'activo' => true,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $ganador = PlanCliente::where('nombre_clave', $clave)->lockForUpdate()->first();
            if ($ganador === null) {
                throw $e;
            }

            return $this->activar($ganador);
        }
    }

    /**
     * Clientes activos cuya clave contiene la de `$q` (`%` y `_` literales),
     * por nombre.
     *
     * @return list<array{id: int, nombre: string}>
     */
    public function sugerir(string $q = '', int $limite = self::LIMITE_SUGERENCIAS): array
    {
        $clave = self::clave($q);

        return PlanCliente::where('activo', true)
            ->when($clave !== '', fn ($query) => $query->whereRaw(
                "nombre_clave LIKE ? ESCAPE '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $clave).'%'],
            ))
            ->orderBy('nombre')
            ->orderBy('id')
            ->limit(max(1, $limite))
            ->get(['id', 'nombre'])
            ->map(fn (PlanCliente $c) => ['id' => (int) $c->id, 'nombre' => $c->nombre])
            ->all();
    }

    /**
     * Agrega los clientes del CRM de Splendid by Porvenir (sin los eliminados)
     * cuya clave aún no está en la lista. Devuelve cuántos agregó; 0 si no hay
     * empresa o CRM.
     */
    public function importarDelCrm(): int
    {
        if (! Schema::hasTable('crm_clientes')) {
            return 0;
        }
        $empresaId = Enterprise::where('slug', 'splendidbyporvenir')->value('id');
        if (! $empresaId) {
            return 0;
        }

        $existentes = PlanCliente::pluck('nombre_clave')->flip()->all();
        $agregados = 0;

        CrmCliente::where('empresa_id', $empresaId)
            ->select(['id', 'nombre'])
            ->chunkById(500, function ($clientes) use (&$existentes, &$agregados) {
                foreach ($clientes as $cliente) {
                    $limpio = mb_substr(self::limpiar((string) $cliente->nombre), 0, 255);
                    $clave = self::clave($limpio);
                    if ($clave === '' || isset($existentes[$clave])) {
                        continue;
                    }
                    try {
                        PlanCliente::create(['nombre' => $limpio, 'nombre_clave' => $clave, 'activo' => true]);
                        $agregados++;
                    } catch (UniqueConstraintViolationException) {
                        // Otro lo agregó mientras tanto.
                    }
                    $existentes[$clave] = true;
                }
            });

        return $agregados;
    }

    private function activar(PlanCliente $cliente): PlanCliente
    {
        if (! $cliente->activo) {
            $cliente->activo = true;
            $cliente->save();
        }

        return $cliente;
    }
}
