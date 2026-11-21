<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Mail\Planeacion\ProgramaSemanalMail;
use App\Models\ActivityLog;
use App\Models\Cultivo;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\DestinatariosPrograma;
use App\Services\Planeacion\ExportadorProgramaExcel;
use App\Services\Planeacion\ProgramaSemanalService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Enviar el programa semanal por correo (tablero-semanal.enviar): un solo
 * correo con un Excel de una hoja por semana, en `to` el remitente del sistema
 * y los destinatarios de la lista guardada en copia oculta.
 *
 * Enviar nunca crea un programa: solo semanas que ya existen. Los servicios se
 * inyectan por método (guardan cálculos en memoria: una instancia por petición).
 */
class EnviarProgramaController extends Controller
{
    use PermisoPlaneacion;

    public const MAXIMO_SEMANAS = 8;

    /** Semanas que se ofrecen en el modal: los programas más recientes. */
    public const SEMANAS_OFRECIDAS = 12;

    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private const MENSAJE_FALLA = 'No se pudo enviar el correo. Revisa la configuración del servidor de correo.';

    public function opciones(DestinatariosPrograma $destinatarios): JsonResponse
    {
        $this->exigirPermiso('tablero-semanal', 'enviar');

        $semanas = ProgramaSemanal::orderByDesc('lunes')->limit(self::SEMANAS_OFRECIDAS)->get(['id', 'lunes'])
            ->map(fn (ProgramaSemanal $p) => [
                'lunes' => $p->lunes->format('Y-m-d'),
                'semana_iso' => (int) $p->lunes->isoWeek(),
            ])->values()->all();

        return response()->json(['success' => true, 'data' => [
            'semanas' => $semanas,
            'destinatarios' => $destinatarios->todos(),
        ]]);
    }

    public function enviar(
        Request $request,
        ProgramaSemanalService $servicio,
        ExportadorProgramaExcel $exportador,
        DestinatariosPrograma $destinatarios,
    ): JsonResponse {
        $this->exigirPermiso('tablero-semanal', 'enviar');

        $lista = $destinatarios->todos();
        $nombres = array_column($lista, 'nombre', 'correo');

        // Repetidos fuera antes de validar: un lunes o un correo dos veces se manda una vez.
        $request->merge([
            'lunes' => $this->sinRepetidos($request->input('lunes'), fn ($v) => $v),
            'destinatarios' => $this->sinRepetidos($request->input('destinatarios'), fn ($v) => mb_strtolower(trim($v))),
        ]);

        $datos = $request->validate([
            'lunes' => ['required', 'array', 'max:'.self::MAXIMO_SEMANAS],
            'lunes.*' => ['bail', 'required', 'string', 'date_format:Y-m-d', $this->esLunes(), $this->conPrograma()],
            'cultivo_id' => ['nullable', 'integer', 'exists:cultivos,id'],
            'destinatarios' => ['required', 'array', 'max:'.DestinatariosPrograma::MAXIMO],
            'destinatarios.*' => ['bail', 'required', 'string', Rule::in(array_keys($nombres))],
            'nota' => ['nullable', 'string', 'max:500'],
        ], [
            'lunes.required' => 'Elige al menos una semana.',
            'lunes.array' => 'Elige al menos una semana.',
            'lunes.max' => 'Máximo '.self::MAXIMO_SEMANAS.' semanas por envío.',
            'lunes.*.required' => 'La semana se identifica por su lunes (AAAA-MM-DD).',
            'lunes.*.string' => 'La semana se identifica por su lunes (AAAA-MM-DD).',
            'lunes.*.date_format' => 'La semana se identifica por su lunes (AAAA-MM-DD).',
            'cultivo_id.integer' => 'Ese cultivo no existe.',
            'cultivo_id.exists' => 'Ese cultivo no existe.',
            'destinatarios.required' => 'Elige al menos un destinatario.',
            'destinatarios.array' => 'Elige al menos un destinatario.',
            'destinatarios.max' => 'Máximo '.DestinatariosPrograma::MAXIMO.' destinatarios.',
            'destinatarios.*.required' => 'Ese correo no está en la lista de correos del programa.',
            'destinatarios.*.string' => 'Ese correo no está en la lista de correos del programa.',
            'destinatarios.*.in' => 'Ese correo no está en la lista de correos del programa.',
            'nota.string' => 'La nota debe ser texto.',
            'nota.max' => 'La nota admite hasta 500 caracteres.',
        ]);

        $lunes = $datos['lunes'];
        sort($lunes);

        // Cada semana con su propio cálculo: olvidar() entre una y otra para no mezclar lo guardado en memoria.
        $semanas = [];
        foreach ($lunes as $l) {
            $programa = ProgramaSemanal::whereDate('lunes', $l)->firstOrFail();
            $semanas[] = $servicio->semana($programa, []);
            $servicio->olvidar();
        }

        $cultivoId = isset($datos['cultivo_id']) ? (int) $datos['cultivo_id'] : $exportador->cultivoPorDefecto($semanas[0]);
        $cultivoNombre = $cultivoId !== null ? Cultivo::whereKey($cultivoId)->value('nombre') : null;

        $ruta = $exportador->generarVarias($semanas, $cultivoId, $cultivoNombre);
        try {
            $contenido = file_get_contents($ruta);
        } finally {
            @unlink($ruta);
        }
        if ($contenido === false) {
            throw new RuntimeException('No se pudo leer el Excel del programa.');
        }

        $numeros = array_map(fn ($s) => (int) $s['programa']['semana_iso'], $semanas);
        $correo = new ProgramaSemanalMail(
            contenidoExcel: $contenido,
            nombreArchivo: $this->nombreArchivo($semanas, $exportador, $lunes, $numeros),
            asunto: 'Programa semanal '.$this->semanasDelAsunto($lunes, $numeros).($cultivoNombre ? ' · '.$cultivoNombre : ''),
            semanas: array_map(fn ($s) => $this->etiqueta($s['programa']), $semanas),
            cultivo: $cultivoNombre,
            nota: isset($datos['nota']) && trim($datos['nota']) !== '' ? trim($datos['nota']) : null,
            remitente: (string) Auth::user()->name,
        );
        $enviados = count($datos['destinatarios']);

        try {
            // Las direcciones se arman dentro del try: una que el validador de Symfony no acepte da el 422 amable, no un 500.
            $ocultos = array_map(fn ($c) => new Address($c, $nombres[$c] !== '' ? $nombres[$c] : null), $datos['destinatarios']);
            Mail::to(new Address((string) config('mail.from.address'), config('mail.from.name')))->bcc($ocultos)->send($correo);
        } catch (Throwable $e) {
            Log::error('No se pudo enviar el programa semanal por correo.', [
                'error' => $e::class.': '.$e->getMessage(),
                'semanas' => $lunes,
                'destinatarios' => $enviados,
            ]);

            return response()->json(['success' => false, 'message' => self::MENSAJE_FALLA], 422);
        }

        // Bitácora: semanas, cultivo y cuántos destinatarios; nunca las direcciones.
        ActivityLog::log(
            action: 'programa_enviado',
            model: 'ProgramaSemanal',
            modelId: (int) $semanas[0]['programa']['id'],
            newValues: [
                'semanas' => $lunes,
                'cultivo_id' => $cultivoId,
                'cultivo' => $cultivoNombre,
                'destinatarios' => $enviados,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => $enviados === 1 ? 'Programa enviado a 1 destinatario.' : "Programa enviado a {$enviados} destinatarios.",
            'data' => ['enviados' => $enviados, 'semanas' => $lunes],
        ]);
    }

    /**
     * Quita repetidos de una lista de textos (con la llave dada); si no es una
     * lista de textos la deja igual para que la validación responda.
     */
    private function sinRepetidos(mixed $valores, Closure $llave): mixed
    {
        if (! is_array($valores) || ! array_is_list($valores)) {
            return $valores;
        }
        foreach ($valores as $v) {
            if (! is_string($v)) {
                return $valores;
            }
        }

        $salida = [];
        foreach ($valores as $v) {
            $salida[$llave($v)] ??= $llave($v);
        }

        return array_values($salida);
    }

    private function esLunes(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falla) {
            $fecha = Carbon::createFromFormat('!Y-m-d', $valor);
            if (! $fecha || ! $fecha->isMonday()) {
                $falla('La semana se identifica por su lunes (AAAA-MM-DD).');
            }
        };
    }

    private function conPrograma(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falla) {
            if (! ProgramaSemanal::whereDate('lunes', $valor)->exists()) {
                $falla('La semana del '.$valor.' no tiene programa; ábrela primero en el Tablero.');
            }
        };
    }

    /** «WK48» con una semana; «WK48–WK50» si son seguidas; «WK48, WK50» si no. */
    private function semanasDelAsunto(array $lunes, array $numeros): string
    {
        $etiquetas = $this->etiquetasDeSemanas($numeros);

        return $this->sonSeguidas($lunes)
            ? (count($etiquetas) === 1 ? $etiquetas[0] : $etiquetas[0].'–'.end($etiquetas))
            : implode(', ', $etiquetas);
    }

    /**
     * `Programa_WK48_2026-11-23.xlsx` con una semana; `Programa_WK48-WK49_2026-11-23.xlsx` con varias
     * seguidas; `Programa_WK48_WK51_2026-11-23.xlsx` si no lo son (misma regla que el asunto).
     */
    private function nombreArchivo(array $semanas, ExportadorProgramaExcel $exportador, array $lunes, array $numeros): string
    {
        if (count($semanas) === 1) {
            return $exportador->nombreArchivo($semanas[0]);
        }
        $etiquetas = $this->etiquetasDeSemanas($numeros);
        $semanasDelNombre = $this->sonSeguidas($lunes)
            ? $etiquetas[0].'-'.end($etiquetas)
            : implode('_', $etiquetas);

        return sprintf('Programa_%s_%s.xlsx', $semanasDelNombre, $semanas[0]['programa']['lunes']);
    }

    /** @return list<string> «WK48», «WK49»… */
    private function etiquetasDeSemanas(array $numeros): array
    {
        return array_map(fn ($n) => sprintf('WK%02d', $n), $numeros);
    }

    /** ¿Cada lunes (ya ordenados) es exactamente una semana después del anterior? */
    private function sonSeguidas(array $lunes): bool
    {
        foreach (array_slice($lunes, 1, null, true) as $i => $l) {
            if (Carbon::createFromFormat('!Y-m-d', $lunes[$i - 1])->addWeek()->format('Y-m-d') !== $l) {
                return false;
            }
        }

        return true;
    }

    /** «WK48 · 23–29 nov» o, si cambia de mes, «WK49 · 30 nov–6 dic». */
    private function etiqueta(array $programa): string
    {
        $lunes = Carbon::createFromFormat('!Y-m-d', $programa['lunes']);
        $domingo = $lunes->copy()->addDays(6);
        $mes = fn (Carbon $f) => self::MESES[$f->month - 1];
        $rango = $lunes->month === $domingo->month
            ? $lunes->day.'–'.$domingo->day.' '.$mes($domingo)
            : $lunes->day.' '.$mes($lunes).'–'.$domingo->day.' '.$mes($domingo);

        return sprintf('WK%02d · %s', $programa['semana_iso'], $rango);
    }
}
