<?php

namespace App\Services\CRM\ImportacionClientes;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmVendedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Decide qué hace la importación de clientes con cada renglón del Excel y la aplica.
 *
 * - Un cliente existente se reconoce por su clave; si aún no tiene clave, por RFC
 *   (no genérico) o por nombre, y en ese caso se le pone la clave del Excel.
 * - Al actualizar se cambian nombre, RFC y correo; el vendedor solo se pone si el
 *   cliente no tiene, y las notas no se tocan.
 * - Vendedor «0», «1», «8» (solo número) o vacío deja al cliente pendiente por
 *   asignar; un vendedor con nombre se empareja con uno del CRM según `$mapeo`
 *   (texto del Excel => id de vendedor o null) o, en la revisión, con la sugerencia
 *   por nombre. Sin pareja, el cliente queda pendiente.
 * - Los correos de dominios internos (los de la propia empresa) no se guardan: el
 *   primero del cliente va a Correo y los demás a Notas (solo al crear).
 */
class PlanImportacionClientes
{
    public const RFC_GENERICOS = ['XAXX010101000', 'XEXX010101000'];

    /** Misma forma que valida el formulario de clientes (ClienteFormModal). */
    private const FORMA_RFC = '/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{0,3}$/u';

    /** Proveedores de correo públicos: nunca se sugieren como dominio interno. */
    private const DOMINIOS_PUBLICOS = [
        'gmail.com', 'hotmail.com', 'hotmail.es', 'outlook.com', 'outlook.es', 'live.com', 'live.com.mx',
        'yahoo.com', 'yahoo.com.mx', 'icloud.com', 'me.com', 'msn.com', 'aol.com', 'prodigy.net.mx', 'protonmail.com',
    ];

    private const MAX_DOMINIOS = 12;

    /**
     * @param  list<array{fila:int, clave:string, nombre:string, rfc:string, correos:string, vendedor:string}>  $filas
     * @param  array<string, int|null>|null  $mapeo  null = usar la sugerencia por nombre
     * @param  list<string>|null  $dominiosInternos  null = usar los sugeridos
     * @return array{resumen: array, operaciones: list<array>}
     */
    public function planificar(int $empresaId, array $filas, ?array $mapeo = null, ?array $dominiosInternos = null): array
    {
        $correosPorFila = [];
        foreach ($filas as $i => $fila) {
            $correosPorFila[$i] = $this->correos($fila['correos']);
        }

        $dominios = $this->dominios($correosPorFila, count($filas), $dominiosInternos);
        $internos = array_column(array_filter($dominios, fn ($d) => $d['interno']), 'dominio');

        $vendedoresCrm = CrmVendedor::query()
            ->where('empresa_id', $empresaId)->where('activo', true)
            ->orderBy('nombre')->get(['id', 'nombre']);
        $vendedores = $this->vendedoresDelExcel($filas, $vendedoresCrm);

        // Con los eliminados: un cliente que borraron a propósito no se vuelve a crear.
        $existentes = CrmCliente::withTrashed()->where('empresa_id', $empresaId)
            ->get(['id', 'clave', 'nombre', 'rfc', 'email', 'vendedor_id', 'deleted_at']);
        $porClave = [];
        $porRfc = [];
        $porNombre = [];
        // Correo => id del cliente que lo usa (el CRM no permite repetir correo en la empresa).
        $correosUsados = [];
        foreach ($existentes as $cliente) {
            if ($cliente->trashed()) {
                if ($cliente->clave !== null && $cliente->clave !== '') {
                    $porClave[Str::upper($cliente->clave)] ??= $cliente;
                }

                continue;
            }
            if ($cliente->email !== null && $cliente->email !== '') {
                $correosUsados[Str::lower($cliente->email)] ??= $cliente->id;
            }
            if ($cliente->clave !== null && $cliente->clave !== '') {
                $porClave[Str::upper($cliente->clave)] ??= $cliente;

                continue;
            }
            $rfc = Str::upper(trim((string) $cliente->rfc));
            if ($rfc !== '' && ! in_array($rfc, self::RFC_GENERICOS, true)) {
                $porRfc[$rfc] ??= $cliente;
            }
            $porNombre[$this->nombreClave($cliente->nombre)] ??= $cliente;
        }

        $resumen = [
            'nuevos' => 0, 'actualizados' => 0, 'sin_cambio' => 0, 'con_problema' => 0,
            'con_vendedor' => 0, 'pendientes' => 0, 'sin_correo' => 0, 'problemas' => [],
        ];
        $operaciones = [];
        $clavesVistas = [];
        $ligados = [];

        foreach ($filas as $i => $fila) {
            $clave = trim($fila['clave']);
            $nombre = preg_replace('/\s+/u', ' ', trim($fila['nombre']));
            $rfc = Str::upper(preg_replace('/\s+/', '', $fila['rfc']));

            $motivo = match (true) {
                $clave === '' => 'Falta la clave',
                $nombre === '' => 'Falta la razón social',
                isset($clavesVistas[Str::upper($clave)]) => 'Clave repetida en el archivo (fila '.$clavesVistas[Str::upper($clave)].')',
                mb_strlen($clave) > 30 => 'Clave demasiado larga',
                mb_strlen($nombre) > 255 => 'Razón social demasiado larga',
                strlen($rfc) > 20 => 'RFC demasiado largo',
                default => null,
            };
            if ($motivo !== null) {
                $resumen['con_problema']++;
                $resumen['problemas'][] = ['fila' => $fila['fila'], 'motivo' => $motivo];

                continue;
            }
            $clavesVistas[Str::upper($clave)] = $fila['fila'];

            $existente = $porClave[Str::upper($clave)] ?? null;
            if ($existente?->trashed()) {
                $resumen['con_problema']++;
                $resumen['problemas'][] = ['fila' => $fila['fila'], 'motivo' => 'El cliente se eliminó en el CRM: no se vuelve a crear'];

                continue;
            }

            // Un RFC que no tiene forma de RFC (relleno como «XAXAXAXAXAAXAX») no se guarda
            // en el campo —el formulario lo rechazaría al editar— sino en las notas.
            $rfcDeRelleno = $rfc !== '' && ! preg_match(self::FORMA_RFC, $rfc);
            $rfcValido = $rfcDeRelleno ? '' : $rfc;
            $rfcLigable = $rfcValido !== '' && ! in_array($rfcValido, self::RFC_GENERICOS, true);

            if ($existente === null) {
                $porSuRfc = $rfcLigable ? ($porRfc[$rfcValido] ?? null) : null;
                $porSuNombre = $porNombre[$this->nombreClave($nombre)] ?? null;
                // Mismo nombre pero otro RFC real: son dos empresas distintas, no se ligan.
                if ($porSuNombre !== null && $rfcLigable) {
                    $suRfc = Str::upper(trim((string) $porSuNombre->rfc));
                    if ($suRfc !== '' && ! in_array($suRfc, self::RFC_GENERICOS, true) && $suRfc !== $rfcValido) {
                        $porSuNombre = null;
                    }
                }
                foreach ([$porSuRfc, $porSuNombre] as $candidato) {
                    if ($candidato !== null && ! isset($ligados[$candidato->id])) {
                        $existente = $candidato;
                        break;
                    }
                }
            }

            $externos = array_values(array_filter(
                $correosPorFila[$i],
                fn ($correo) => ! in_array(Str::after($correo, '@'), $internos, true),
            ));
            // El primer correo del cliente que nadie más usa en la empresa; los demás, a notas.
            $email = null;
            foreach ($externos as $correo) {
                $duenio = $correosUsados[$correo] ?? null;
                if ($duenio === null || ($existente !== null && $duenio === $existente->id)) {
                    $email = $correo;
                    break;
                }
            }
            $otros = array_values(array_diff($externos, [$email]));
            $vendedorId = $this->vendedorDeFila($fila['vendedor'], $vendedores, $mapeo);
            $textoVendedor = trim($fila['vendedor']);

            if ($existente === null) {
                $notas = array_filter([
                    $otros !== [] ? 'Otros correos: '.implode(', ', $otros) : null,
                    $rfcDeRelleno ? 'RFC del Excel: '.$rfc : null,
                ]);
                $operaciones[] = ['accion' => 'crear', 'datos' => [
                    'empresa_id' => $empresaId,
                    'clave' => $clave,
                    'nombre' => $nombre,
                    'rfc' => $rfcValido !== '' ? $rfcValido : null,
                    'email' => $email,
                    'notas' => $notas !== [] ? implode("\n", $notas) : null,
                    'vendedor_id' => $vendedorId,
                    'estatus' => 'activo',
                ]];
                if ($email !== null) {
                    $correosUsados[$email] = 'fila '.$fila['fila'];
                }
                if (isset($vendedores[$textoVendedor])) {
                    $vendedores[$textoVendedor]['afectados']++;
                }
                $resumen['nuevos']++;
                $vendedorFinal = $vendedorId;
                $emailFinal = $email;
            } else {
                $ligados[$existente->id] = true;
                $cambios = array_filter([
                    'clave' => $existente->clave === null || $existente->clave === '' ? $clave : null,
                    'nombre' => $existente->nombre !== $nombre ? $nombre : null,
                    'rfc' => $rfcValido !== '' && $existente->rfc !== $rfcValido ? $rfcValido : null,
                    'email' => $email !== null && $existente->email !== $email ? $email : null,
                    'vendedor_id' => $existente->vendedor_id === null ? $vendedorId : null,
                ], fn ($valor) => $valor !== null);
                if (isset($cambios['email'])) {
                    $correosUsados[$email] = $existente->id;
                }
                if ($existente->vendedor_id === null && isset($vendedores[$textoVendedor])) {
                    $vendedores[$textoVendedor]['afectados']++;
                }

                if ($cambios === []) {
                    $resumen['sin_cambio']++;
                } else {
                    $operaciones[] = ['accion' => 'actualizar', 'cliente' => $existente, 'datos' => $cambios];
                    $resumen['actualizados']++;
                }
                $vendedorFinal = $existente->vendedor_id ?? $vendedorId;
                $emailFinal = $email ?? $existente->email;
            }

            $resumen[$vendedorFinal !== null ? 'con_vendedor' : 'pendientes']++;
            if ($emailFinal === null || $emailFinal === '') {
                $resumen['sin_correo']++;
            }
        }

        $resumen['vendedores'] = array_values(array_map(
            // `afectados`: clientes que tomarán el vendedor elegido (nuevos o existentes sin
            // vendedor); el modal mueve esos entre «con vendedor» y «pendientes».
            fn ($v) => ['texto' => $v['texto'], 'codigo' => $v['codigo'], 'nombre' => $v['nombre'], 'clientes' => $v['clientes'], 'afectados' => $v['afectados'], 'sugerido_id' => $v['sugerido_id']],
            $vendedores,
        ));
        $resumen['vendedores_crm'] = $vendedoresCrm->map(fn ($v) => ['id' => $v->id, 'nombre' => $v->nombre])->all();
        $resumen['dominios'] = $dominios;

        return ['resumen' => $resumen, 'operaciones' => $operaciones];
    }

    /** Aplica las operaciones del plan en una transacción y devuelve los conteos. */
    public function aplicar(array $plan): array
    {
        DB::transaction(function () use ($plan) {
            foreach ($plan['operaciones'] as $operacion) {
                if ($operacion['accion'] === 'crear') {
                    CrmCliente::create($operacion['datos']);
                } else {
                    $operacion['cliente']->update($operacion['datos']);
                }
            }
        });

        return array_intersect_key($plan['resumen'], array_flip([
            'nuevos', 'actualizados', 'sin_cambio', 'con_problema', 'con_vendedor', 'pendientes', 'sin_correo',
        ]));
    }

    /** Ids de vendedor que la importación asignará (para exigir el permiso). */
    public function asignaVendedores(array $plan): bool
    {
        foreach ($plan['operaciones'] as $operacion) {
            if (($operacion['datos']['vendedor_id'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Correos válidos de la celda, en minúsculas. Separa por «;», «,» y «:»; un pedazo con
     * espacios se une si así queda un correo válido («jflores@canes agro.com») y si no se
     * parte por los espacios.
     *
     * @return list<string>
     */
    private function correos(string $texto): array
    {
        $correos = [];
        foreach (preg_split('/[;,:]+/', $texto) as $pedazo) {
            $pedazo = trim($pedazo);
            if ($pedazo === '') {
                continue;
            }
            $unido = preg_replace('/\s+/', '', $pedazo);
            $candidatos = filter_var($unido, FILTER_VALIDATE_EMAIL) ? [$unido] : preg_split('/\s+/', $pedazo);
            foreach ($candidatos as $candidato) {
                $candidato = Str::lower(trim($candidato));
                if (filter_var($candidato, FILTER_VALIDATE_EMAIL) && mb_strlen($candidato) <= 255 && ! in_array($candidato, $correos, true)) {
                    $correos[] = $candidato;
                }
            }
        }

        return $correos;
    }

    /**
     * Dominios más frecuentes, con cuántos clientes los traen y si son internos. Sin
     * elección del usuario se sugieren internos los que aparecen en al menos el 20 %
     * de los clientes (mínimo 2) y no son de correo público, más su variante «.mx».
     *
     * @return list<array{dominio: string, clientes: int, interno: bool}>
     */
    private function dominios(array $correosPorFila, int $totalFilas, ?array $elegidos): array
    {
        $conteo = [];
        foreach ($correosPorFila as $correos) {
            foreach (array_unique(array_map(fn ($c) => Str::after($c, '@'), $correos)) as $dominio) {
                $conteo[$dominio] = ($conteo[$dominio] ?? 0) + 1;
            }
        }
        arsort($conteo);

        if ($elegidos !== null) {
            $internos = array_values(array_unique(array_map(fn ($d) => Str::lower(trim((string) $d)), $elegidos)));
        } else {
            $minimo = max(2, (int) ceil($totalFilas * 0.2));
            $internos = [];
            foreach ($conteo as $dominio => $veces) {
                if ($veces >= $minimo && ! in_array($dominio, self::DOMINIOS_PUBLICOS, true)) {
                    $internos[] = $dominio;
                }
            }
            foreach ($internos as $dominio) {
                $variante = str_ends_with($dominio, '.mx') ? substr($dominio, 0, -3) : $dominio.'.mx';
                if (isset($conteo[$variante]) && ! in_array($variante, $internos, true)) {
                    $internos[] = $variante;
                }
            }
        }

        $lista = [];
        foreach ($conteo as $dominio => $veces) {
            $interno = in_array($dominio, $internos, true);
            if ($interno || count($lista) < self::MAX_DOMINIOS) {
                $lista[] = ['dominio' => (string) $dominio, 'clientes' => $veces, 'interno' => $interno];
            }
        }

        return $lista;
    }

    /**
     * Vendedores con nombre que trae el Excel, con su sugerencia del CRM.
     *
     * @return array<string, array{texto: string, codigo: ?string, nombre: string, clientes: int, sugerido_id: ?int}>
     */
    private function vendedoresDelExcel(array $filas, $vendedoresCrm): array
    {
        $vendedores = [];
        foreach ($filas as $fila) {
            $texto = trim($fila['vendedor']);
            if ($this->sinVendedor($texto)) {
                continue;
            }
            if (! isset($vendedores[$texto])) {
                preg_match('/^(\d+)\s+(.*)$/u', $texto, $partes);
                $nombre = $partes[2] ?? $texto;
                $vendedores[$texto] = [
                    'texto' => $texto,
                    'codigo' => $partes[1] ?? null,
                    'nombre' => $nombre,
                    'clientes' => 0,
                    'afectados' => 0,
                    'sugerido_id' => $this->sugerir($nombre, $vendedoresCrm),
                ];
            }
            $vendedores[$texto]['clientes']++;
        }

        return $vendedores;
    }

    private function vendedorDeFila(string $texto, array $vendedores, ?array $mapeo): ?int
    {
        $texto = trim($texto);
        if ($this->sinVendedor($texto)) {
            return null;
        }
        if ($mapeo === null) {
            return $vendedores[$texto]['sugerido_id'] ?? null;
        }
        $id = $mapeo[$texto] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** Vacío o solo número («0», «1», «8»): el Excel no trae vendedor. */
    private function sinVendedor(string $texto): bool
    {
        return $texto === '' || ctype_digit($texto);
    }

    /**
     * Vendedor del CRM cuyo nombre comparte más palabras con el del Excel (al menos
     * dos, o todas si el del CRM tiene una sola). En empate no se sugiere ninguno.
     */
    private function sugerir(string $nombre, $vendedoresCrm): ?int
    {
        $palabras = $this->palabras($nombre);
        $mejor = null;
        $mejorPuntaje = 0;
        $empate = false;
        foreach ($vendedoresCrm as $vendedor) {
            $delCrm = $this->palabras($vendedor->nombre);
            if ($delCrm === []) {
                continue;
            }
            $puntaje = count(array_intersect($palabras, $delCrm));
            if ($puntaje < min(2, count($delCrm))) {
                continue;
            }
            if ($puntaje > $mejorPuntaje) {
                [$mejor, $mejorPuntaje, $empate] = [$vendedor->id, $puntaje, false];
            } elseif ($puntaje === $mejorPuntaje) {
                $empate = true;
            }
        }

        return $empate ? null : $mejor;
    }

    /** @return list<string> */
    private function palabras(?string $texto): array
    {
        $normal = preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $texto)));

        return array_values(array_unique(array_filter(
            explode(' ', $normal),
            fn ($p) => strlen($p) >= 3 && ! in_array($p, ['del', 'las', 'los'], true),
        )));
    }

    private function nombreClave(?string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii((string) $texto))));
    }
}
