<?php

namespace App\Support\RH;

use Illuminate\Http\Request;

/** Regla del motivo cuando es el propio empleado quien solicita (portal y Mi Perfil). RH al capturar lo deja opcional. */
class MotivoVacaciones
{
    public const MENSAJE = 'Escribe para qué son estos días. Con una frase basta (mínimo 10 letras).';

    public const REGLAS = ['reason' => 'required|string|min:10|max:500'];

    public const MENSAJES = [
        'reason.required' => self::MENSAJE,
        'reason.string' => self::MENSAJE,
        'reason.min' => self::MENSAJE,
        'reason.max' => 'El motivo no puede pasar de 500 letras.',
    ];

    /** Recorta el texto ANTES de validar: «  Viaje cor  » (9 letras) debe fallar y solo espacios cuenta como vacío. */
    public static function recortar(Request $request): void
    {
        $motivo = $request->input('reason');

        if (is_string($motivo)) {
            $request->merge(['reason' => trim($motivo)]);
        }
    }
}
