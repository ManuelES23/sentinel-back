<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Los avances de Planeación identifican la semana por su lunes (AAAA-MM-DD).
 */
trait ValidaLunes
{
    /** El `lunes` de la petición, ya validado: fecha real y lunes (422 en `lunes` si no). */
    protected function lunesValidado(Request $request): string
    {
        $lunes = $request->validate([
            'lunes' => ['required', 'date_format:Y-m-d'],
        ])['lunes'];

        try {
            $fecha = Carbon::createFromFormat('!Y-m-d', $lunes);
        } catch (InvalidFormatException) {
            $fecha = null;
        }
        if (! $fecha || $fecha->format('Y-m-d') !== $lunes || ! $fecha->isMonday()) {
            throw ValidationException::withMessages(['lunes' => 'La semana se identifica por su lunes (AAAA-MM-DD).']);
        }

        return $lunes;
    }
}
