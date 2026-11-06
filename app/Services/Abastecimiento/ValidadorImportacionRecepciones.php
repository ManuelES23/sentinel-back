<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Enterprise;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Valida renglón por renglón las filas que entrega LectorRecepciones, sin guardar nada.
 * Todo llega como texto: los números se interpretan aquí de forma estricta (solo punto
 * decimal; «1,234» o «100,5» se rechazan porque la coma es ambigua). Las reglas de negocio
 * son las de ReglasRecepcion; aquí se suman el folio del convenio, el tipo de carga por
 * nombre y los duplicados (spec §5.7). Un número constante de consultas: convenios (con
 * productor y precios), tipos de carga y tickets existentes, sin importar cuántos renglones.
 */
class ValidadorImportacionRecepciones
{
    private const MAX_ENTERO = 999999999999;

    private const MAX_TICKET = 60;

    /** Largo máximo del texto del usuario que se refleja en un mensaje de error. */
    private const MAX_MENSAJE = 60;

    /**
     * @param  list<array<string, mixed>>  $filas  salida de LectorRecepciones::leer
     * @return array{
     *     filas: list<array{fila: int, estado: string, errores: list<string>, convenio: ?array{id: int, folio: string, productor: ?string, moneda: string}, tipo_carga: ?string, datos: ?array}>,
     *     resumen: array{total: int, listas: int, con_error: int, por_convenio: list<array{convenio_id: int, folio: string, renglones: int, cantidad: float, kilos: float}>},
     *     advertencias: list<string>
     * }
     */
    public function validar(Enterprise $empresa, array $filas): array
    {
        $filas = array_values($filas);
        $convenios = $this->convenios($empresa, $filas);
        $tipos = $this->tipos($empresa);
        $existentes = $this->ticketsExistentes($convenios);
        $precios = $this->preciosEnMemoria($convenios);

        $resultado = [];
        $identicas = [];
        $ticketsDelArchivo = [];
        $sinTicket = 0;

        foreach ($filas as $indice => $crudo) {
            $numero = (int) ($crudo['fila'] ?? $indice + 2);
            $errores = [];
            $folio = $this->texto($crudo['convenio_folio'] ?? null);
            $convenio = $folio === null ? null : $convenios->get(self::folio($folio));
            $nombreTipo = $this->texto($crudo['tipo_carga'] ?? null);
            $tipoId = null;
            $tipo = null;
            $ticket = $this->texto($crudo['ticket'] ?? null);
            $fecha = $this->texto($crudo['fecha'] ?? null);

            if ($folio === null) {
                $errores[] = 'Falta el folio del convenio.';
            } elseif ($convenio === null) {
                $errores[] = 'No existe el convenio «'.self::corto($folio).'» en esta empresa.';
            }

            if ($nombreTipo === null) {
                $errores[] = 'Falta el tipo de carga.';
            } else {
                $candidatos = $tipos->get(self::normalizar($nombreTipo), []);
                if (count($candidatos) === 0) {
                    $errores[] = 'No existe el tipo de carga «'.self::corto($nombreTipo).'».';
                } elseif (count($candidatos) > 1) {
                    $errores[] = 'Hay más de un tipo de carga llamado «'.self::corto($nombreTipo).'»; corrige el catálogo o el archivo.';
                } else {
                    $tipo = $candidatos[0];
                    $tipoId = (int) $tipo->id;
                }
            }

            if ($fecha === null) {
                $original = $this->texto($crudo['fecha_original'] ?? null);
                $errores[] = $original === null
                    ? 'Falta la fecha.'
                    : 'La fecha «'.self::corto($original).'» no se entiende; usa AAAA-MM-DD o DD/MM/AAAA.';
            } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || ! checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
                $errores[] = 'La fecha «'.self::corto($fecha).'» no es válida; usa AAAA-MM-DD o DD/MM/AAAA.';
                $fecha = null;
            }

            [$cantidad, $errorCantidad] = $this->numero($this->texto($crudo['cantidad'] ?? null), ['La cantidad', 'es', 'válida'], 0);
            if ($errorCantidad !== null) {
                $errores[] = $errorCantidad;
            } elseif ($cantidad === null) {
                $errores[] = 'Falta la cantidad.';
            }
            [$kilos, $errorKilos] = $this->numero($this->texto($crudo['kilos'] ?? null), ['Los kilos', 'son', 'válidos'], 2);
            [$rezaga, $errorRezaga] = $this->numero($this->texto($crudo['rezaga_kg'] ?? null), ['La rezaga', 'es', 'válida'], 2);
            array_push($errores, ...array_values(array_filter([$errorKilos, $errorRezaga])));

            if ($ticket !== null && mb_strlen($ticket) > self::MAX_TICKET) {
                $errores[] = 'El ticket no puede pasar de '.self::MAX_TICKET.' caracteres.';
            }
            $ticket === null && $sinTicket++;

            $datos = null;
            // Con todo interpretado se aplican las reglas del negocio; si no, los errores de
            // lectura ya explican el renglón y las reglas solo repetirían o confundirían.
            if ($errores === [] && $convenio !== null && $tipoId !== null && $fecha !== null && $cantidad !== null) {
                $datos = [
                    'convenio_id' => (int) $convenio->id,
                    'fecha' => $fecha,
                    'tipo_carga_id' => $tipoId,
                    'cantidad' => $cantidad,
                    'kilos' => $kilos,
                    'rezaga_kg' => $rezaga,
                    'ticket_proveedor' => $ticket,
                    'notas' => null,
                ];
                array_push($errores, ...$this->reglas($convenio, $datos, $precios));
                $errores = array_merge($errores, $this->duplicados(
                    $convenio, $datos, $numero, $identicas, $ticketsDelArchivo, $existentes,
                ));
                $datos = $this->normalizarDatos($datos);
            }

            $resultado[] = [
                'fila' => $numero,
                'estado' => $errores === [] ? 'lista' : 'error',
                'errores' => $errores,
                'convenio' => $convenio === null ? null : [
                    'id' => (int) $convenio->id,
                    'folio' => $convenio->folio,
                    'productor' => $convenio->productor?->nombre,
                    'moneda' => $convenio->moneda,
                ],
                'tipo_carga' => $tipo?->nombre,
                'datos' => $errores === [] ? $datos : null,
            ];
        }

        $listas = count(array_filter($resultado, fn (array $f) => $f['estado'] === 'lista'));

        return [
            'filas' => $resultado,
            'resumen' => [
                'total' => count($resultado),
                'listas' => $listas,
                'con_error' => count($resultado) - $listas,
                'por_convenio' => $this->porConvenio($resultado),
            ],
            'advertencias' => $sinTicket === 0 ? [] : [
                ($sinTicket === 1 ? '1 renglón no trae' : "{$sinTicket} renglones no traen")
                .' ticket: sin ticket no se puede detectar si el mismo archivo ya se importó antes.',
            ],
        ];
    }

    /** Errores de ReglasRecepcion como lista de mensajes (una sola ValidationException por renglón). */
    private function reglas(Convenio $convenio, array $datos, PrecioVigente $precios): array
    {
        try {
            ReglasRecepcion::validar($convenio, $datos, null, $precios);
        } catch (ValidationException $e) {
            return array_values(array_map(fn (array $mensajes) => $mensajes[0], $e->errors()));
        }

        return [];
    }

    /**
     * Ruling 5: renglón idéntico a otro del archivo, ticket repetido en el archivo (mismo
     * convenio) o ticket ya existente en el convenio (recepción no borrada).
     *
     * @param  array<string, int>  $identicas  huella => número del primer renglón
     * @param  array<string, int>  $ticketsDelArchivo  convenio|ticket => número del primer renglón
     * @param  array<string, string>  $existentes  convenio|ticket => folio de la recepción
     */
    private function duplicados(Convenio $convenio, array $datos, int $numero, array &$identicas, array &$ticketsDelArchivo, array $existentes): array
    {
        $errores = [];
        $huella = implode('|', [
            $datos['convenio_id'], $datos['fecha'], $datos['tipo_carga_id'],
            $this->canonico($datos['cantidad']), $this->canonico($datos['kilos']), $this->canonico($datos['rezaga_kg']),
            $datos['ticket_proveedor'] === null ? '' : self::normalizarTicket($datos['ticket_proveedor']),
        ]);
        $esIdentico = false;
        if (isset($identicas[$huella])) {
            $esIdentico = true;
            $errores[] = "Es idéntico al renglón {$identicas[$huella]} del archivo.";
        } else {
            $identicas[$huella] = $numero;
        }

        $ticket = $datos['ticket_proveedor'];
        if ($ticket !== null) {
            $clave = $datos['convenio_id'].'|'.self::normalizarTicket($ticket);
            if (isset($existentes[$clave])) {
                $errores[] = 'El ticket «'.self::corto($ticket)."» ya existe en el convenio {$convenio->folio} (recepción {$existentes[$clave]}).";
            }
            if (isset($ticketsDelArchivo[$clave])) {
                if (! $esIdentico) {
                    $errores[] = 'El ticket «'.self::corto($ticket)."» ya aparece en el renglón {$ticketsDelArchivo[$clave]} del archivo para este convenio.";
                }
            } else {
                $ticketsDelArchivo[$clave] = $numero;
            }
        }

        return $errores;
    }

    /** @return Collection<string, Convenio> por folio normalizado */
    private function convenios(Enterprise $empresa, array $filas): Collection
    {
        $folios = collect($filas)
            ->map(fn (array $f) => $this->texto($f['convenio_folio'] ?? null))
            ->filter()
            ->map(fn (string $f) => self::folio($f))
            ->unique()
            ->values();

        if ($folios->isEmpty()) {
            return collect();
        }

        return Convenio::deEmpresa($empresa->id)
            ->whereIn('folio', $folios->all())
            ->with(['productor:id,nombre', 'precios'])
            ->get()
            ->keyBy(fn (Convenio $c) => self::folio($c->folio));
    }

    /** @return Collection<string, list<TipoCarga>> por nombre normalizado (sin acentos ni mayúsculas) */
    private function tipos(Enterprise $empresa): Collection
    {
        return TipoCarga::deEmpresa($empresa->id)->get(['id', 'nombre'])
            ->groupBy(fn (TipoCarga $t) => self::normalizar($t->nombre))
            ->map(fn (Collection $grupo) => $grupo->values()->all());
    }

    /**
     * Tickets de recepciones no borradas de los convenios del archivo: convenio|ticket → folio.
     * Se trae por convenio (no por ticket) para que la consulta no crezca con los renglones.
     *
     * @return array<string, string>
     */
    private function ticketsExistentes(Collection $convenios): array
    {
        if ($convenios->isEmpty()) {
            return [];
        }

        $mapa = [];
        Recepcion::whereIn('convenio_id', $convenios->pluck('id')->all())
            ->whereNotNull('ticket_proveedor')
            ->orderBy('id')
            ->get(['id', 'convenio_id', 'ticket_proveedor', 'folio'])
            ->each(function (Recepcion $r) use (&$mapa) {
                $mapa[$r->convenio_id.'|'.self::normalizarTicket($r->ticket_proveedor)] ??= $r->folio;
            });

        return $mapa;
    }

    /** PrecioVigente que usa los precios ya cargados con los convenios, sin consultar la base. */
    private function preciosEnMemoria(Collection $convenios): PrecioVigente
    {
        $porConvenio = $convenios->mapWithKeys(fn (Convenio $c) => [$c->id => $c->precios])->all();

        return new class($porConvenio) extends PrecioVigente
        {
            public function __construct(private array $porConvenio) {}

            protected function precios(Convenio $convenio): Collection
            {
                return $this->porConvenio[$convenio->id] ?? collect();
            }
        };
    }

    /** @return list<array{convenio_id: int, folio: string, renglones: int, cantidad: float, kilos: float}> */
    private function porConvenio(array $filas): array
    {
        $grupos = [];
        foreach ($filas as $fila) {
            if ($fila['estado'] !== 'lista') {
                continue;
            }
            $id = $fila['convenio']['id'];
            $grupos[$id] ??= ['convenio_id' => $id, 'folio' => $fila['convenio']['folio'], 'renglones' => 0, 'cantidad' => 0.0, 'kilos' => 0.0];
            $grupos[$id]['renglones']++;
            $grupos[$id]['cantidad'] += (float) $fila['datos']['cantidad'];
            $grupos[$id]['kilos'] += (float) ($fila['datos']['kilos'] ?? 0);
        }

        $lista = array_map(function (array $g) {
            $g['cantidad'] = round($g['cantidad'], 2);
            $g['kilos'] = round($g['kilos'], 2);

            return $g;
        }, array_values($grupos));
        usort($lista, fn (array $a, array $b) => strcmp($a['folio'], $b['folio']));

        return $lista;
    }

    /**
     * Número estricto: dígitos con punto decimal opcional (y signo menos). La coma, los
     * espacios y los separadores de miles se rechazan: «1,234» podría ser 1234 o 1.234.
     * También se rechaza lo que no cabe en la columna (decimal(14,2)): más de `$decimales`
     * decimales significativos (con 0 decimales, cualquier fracción distinta de cero).
     *
     * @param  array{0: string, 1: string, 2: string}  $campo  [sujeto, «es»|«son», adjetivo concordado]
     * @return array{0: ?string, 1: ?string} [valor, mensaje de error]
     */
    private function numero(?string $texto, array $campo, int $decimales): array
    {
        if ($texto === null) {
            return [null, null];
        }
        [$sujeto, $verbo, $adjetivo] = $campo;
        $no = "{$sujeto} «".self::corto($texto)."» no {$verbo} {$adjetivo}";

        if (preg_match('/[,\s]/u', $texto)) {
            return [null, $decimales === 0 ? "{$no}: use un número entero, sin comas ni puntos." : "{$no}: escribe el número con punto decimal, sin coma ni separador de miles ni espacios (por ejemplo 1234.5)."];
        }
        if (! preg_match('/^-?\d+(\.\d+)?$/', $texto)) {
            return [null, $decimales === 0 ? "{$no}: use un número entero, sin comas ni puntos." : "{$no}: escribe solo un número (por ejemplo 1234.5)."];
        }
        [$entera, $fraccion] = array_pad(explode('.', ltrim($texto, '-'), 2), 2, '');
        if (strlen(ltrim($entera, '0')) > strlen((string) self::MAX_ENTERO)) {
            return [null, "{$no}: es demasiado grande."];
        }
        if (strlen(rtrim($fraccion, '0')) > $decimales) {
            return [null, $decimales === 0
                ? "{$sujeto} va en números enteros."
                : "{$sujeto} ".($verbo === 'son' ? 'aceptan' : 'acepta')." hasta {$decimales} decimales."];
        }

        return [$texto, null];
    }

    /** Valores listos para `Recepcion::create`: cantidad entera y kilos/rezaga con 2 decimales exactos. */
    private function normalizarDatos(array $datos): array
    {
        $datos['cantidad'] = number_format((float) $datos['cantidad'], 0, '.', '');
        foreach (['kilos', 'rezaga_kg'] as $campo) {
            if ($datos[$campo] !== null) {
                $datos[$campo] = number_format((float) $datos[$campo], 2, '.', '');
            }
        }

        return $datos;
    }

    /** Texto del usuario recortado para reflejarlo en un mensaje sin inflarlo. */
    private static function corto(string $texto): string
    {
        return mb_strlen($texto) > self::MAX_MENSAJE ? mb_substr($texto, 0, self::MAX_MENSAJE).'…' : $texto;
    }
    /** Valor numérico comparable (huella de duplicados): 10, 10.0 y 10.00 son lo mismo. */
    private function canonico(?string $numero): string
    {
        return $numero === null ? '' : sprintf('%.4F', (float) $numero);
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null || is_bool($valor) || is_array($valor)) {
            return null;
        }
        $texto = trim(str_replace("\u{00A0}", ' ', (string) $valor));

        return $texto === '' ? null : $texto;
    }

    private static function folio(string $folio): string
    {
        return mb_strtoupper(trim($folio));
    }

    /**
     * Ticket comparable: minúsculas, sin espacios en los bordes y con los espacios internos colapsados.
     * No usa Str::ascii: transliterar colapsaría tickets distintos escritos en otros alfabetos.
     */
    public static function normalizarTicket(string $ticket): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($ticket)));
    }

    /** Minúsculas, sin acentos y con los espacios colapsados (nombres de tipo de carga). */
    private static function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(Str::ascii($texto))));
    }
}
