<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\ConfiguracionPlaneacion;

/**
 * Correos a los que se envía el programa semanal.
 *
 * Se guardan en el parámetro `correos_programa` de `plan_configuracion` como
 * JSON de `{correo, nombre}` en orden de captura. El correo va en minúsculas y
 * sin espacios y no se repite (sin distinguir mayúsculas); el nombre es
 * opcional (cadena vacía si no hay). Sin parámetro, o con un valor que no es
 * una lista, no hay destinatarios. Lo guardado se lee con tolerancia: las
 * entradas que no son `{correo: texto}` se descartan.
 */
final class DestinatariosPrograma
{
    public const CLAVE = 'correos_programa';

    public const MAXIMO = 50;

    /**
     * @return list<array{correo: string, nombre: string}>
     */
    public function todos(): array
    {
        $valor = ConfiguracionPlaneacion::valor(self::CLAVE);
        if ($valor === null) {
            return [];
        }
        $lista = json_decode((string) $valor, true);
        if (! is_array($lista) || ! array_is_list($lista)) {
            return [];
        }

        return self::normalizar(array_filter($lista, fn ($d) => is_array($d) && isset($d['correo']) && is_string($d['correo'])));
    }

    /**
     * Guarda la lista (ya validada), normalizada y en orden de captura.
     *
     * @param  array<int, array{correo: string, nombre?: string|null}>  $destinatarios
     */
    public function guardar(array $destinatarios): void
    {
        ConfiguracionPlaneacion::updateOrCreate(
            ['clave' => self::CLAVE],
            ['valor' => json_encode(self::normalizar($destinatarios), JSON_UNESCAPED_UNICODE)],
        );
    }

    /**
     * Correo en minúsculas y sin espacios, nombre recortado y sin repetidos
     * (gana la primera aparición).
     *
     * @param  array<int, array{correo: string, nombre?: string|null}>  $destinatarios
     * @return list<array{correo: string, nombre: string}>
     */
    public static function normalizar(array $destinatarios): array
    {
        $salida = [];
        foreach ($destinatarios as $destinatario) {
            $correo = mb_strtolower(trim((string) ($destinatario['correo'] ?? '')));
            if ($correo === '' || isset($salida[$correo])) {
                continue;
            }
            $nombre = $destinatario['nombre'] ?? '';
            $salida[$correo] = [
                'correo' => $correo,
                'nombre' => is_string($nombre) ? trim($nombre) : '',
            ];
        }

        return array_values($salida);
    }
}
