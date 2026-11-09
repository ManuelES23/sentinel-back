<?php

namespace App\Http\Controllers\Api\SplendidFarms\ChecadorCampo;

use App\Http\Controllers\Controller;
use App\Models\DevicePairing;
use App\Models\User;
use App\Services\SplendidFarms\ChecadorCampo\AutorizacionChecadorCampo;
use App\Services\SplendidFarms\ChecadorCampo\PaqueteCuadrillaSf;
use App\Services\SplendidFarms\ChecadorCampo\RegistradorChequeosCampo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Endpoints públicos de la app directa del checador de campo SF. No usan
 * Sanctum: `emparejar` valida usuario y contraseña una sola vez y entrega un
 * token de dispositivo (modo sf_field); el resto se autentica con ese token
 * (middleware sf.field.device) y solo sirve para descargar el paquete de la
 * cuadrilla y subir checadas.
 */
class ChecadorCampoDispositivoController extends Controller
{
    public const MOTIVO_RESCATE = 'dispositivo_revocado';

    /** Hash bcrypt válido de una cadena cualquiera, para igualar tiempos cuando el correo no existe. */
    private const HASH_SEÑUELO = '$2y$12$Wl7Gm1bVf0N1u2Zk5mYxQeJb7vQy8mP2r0o3dKq9hJ4xS6tUaB1Ci';

    public function __construct(
        private readonly AutorizacionChecadorCampo $autorizacion,
        private readonly PaqueteCuadrillaSf $paquete,
        private readonly RegistradorChequeosCampo $registrador,
    ) {
    }

    /**
     * Empareja el celular con un ingeniero. Las credenciales se verifican ANTES
     * que el permiso, para que una contraseña incorrecta nunca revele si el
     * correo existe ni qué permisos tiene. Si viene `token_anterior` (cambio de
     * ingeniero en el mismo celular) se revoca solo ese emparejamiento.
     */
    public function emparejar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|max:255',
            'password' => 'required|string',
            'label' => 'nullable|string|max:255',
            'token_anterior' => 'nullable|string|max:255',
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Se hace un Hash::check aunque el usuario no exista para que el
        // tiempo de respuesta no delate si el correo está registrado.
        $passwordValida = Hash::check($validated['password'], $user ? $user->password : self::HASH_SEÑUELO);

        if (! $user || ! $passwordValida) {
            return $this->error('credenciales_invalidas', 'Usuario o contraseña incorrectos.', 422);
        }

        $empresa = $this->autorizacion->empresa();
        if (! $empresa) {
            return response()->json([
                'status' => 'error',
                'message' => 'El checador de campo no está configurado en el servidor.',
            ], 503);
        }

        if ($user->must_change_password) {
            return $this->error(
                'cambio_contrasena_requerido',
                'Cambia tu contraseña en Sentinel antes de usar el checador.',
                403
            );
        }

        if (! $this->autorizacion->usuarioAutorizado($user, $empresa)) {
            return $this->error(
                'sin_permiso',
                'No tienes permiso para usar el checador de campo. Pide acceso a tu administrador.',
                403
            );
        }

        $rawToken = DevicePairing::generateToken();

        $pairing = DB::transaction(function () use ($validated, $user, $empresa, $rawToken) {
            if (! empty($validated['token_anterior'])) {
                $anterior = DevicePairing::findByToken($validated['token_anterior'], [DevicePairing::MODE_SF_FIELD]);
                if ($anterior && ! $anterior->isRevoked()) {
                    $anterior->update(['revoked_at' => now()]);
                }
            }

            return DevicePairing::create([
                'device_token_hash' => DevicePairing::hashToken($rawToken),
                'mode' => DevicePairing::MODE_SF_FIELD,
                'paired_by_user_id' => $user->id,
                'enterprise_id' => $empresa->id,
                'label' => $validated['label'] ?? null,
                'last_used_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Celular emparejado correctamente.',
            'data' => [
                'device_token' => $rawToken,
                'emparejamiento_id' => $pairing->id,
                'enterprise_id' => $empresa->id,
                'ingeniero' => ['id' => $user->id, 'name' => $user->name],
                'emparejado_en' => $pairing->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /** Paquete de cuadrilla de la empresa del emparejamiento. */
    public function paquete(Request $request): JsonResponse
    {
        /** @var DevicePairing $pairing */
        $pairing = $request->attributes->get('devicePairing');

        return response()->json([
            'success' => true,
            'data' => $this->paquete->armar((int) $pairing->enterprise_id),
        ]);
    }

    /** Sube checadas a nombre del ingeniero que emparejó el celular. */
    public function sync(Request $request): JsonResponse
    {
        /** @var DevicePairing $pairing */
        $pairing = $request->attributes->get('devicePairing');
        $enterpriseId = (int) $pairing->enterprise_id;

        $validated = $request->validate($this->registrador->reglas($enterpriseId));

        $results = $this->registrador->registrar($validated['checks'], $enterpriseId, [
            'checked_by_user_id' => $pairing->paired_by_user_id,
            'device_pairing_id' => $pairing->id,
        ]);

        return response()->json(['success' => true, 'data' => ['results' => $results]]);
    }

    /**
     * Rescate de checadas tomadas con un celular revocado o caducado. Se
     * autentica con el token NUEVO (quien rescata ya inició sesión y tiene
     * permiso); el token viejo viaja en el cuerpo y solo prueba de qué
     * emparejamiento vienen las checadas. Quedan a nombre del ingeniero
     * original y todas pasan por revisión humana.
     */
    public function rescatar(Request $request): JsonResponse
    {
        /** @var DevicePairing $actual */
        $actual = $request->attributes->get('devicePairing');
        $enterpriseId = (int) $actual->enterprise_id;

        $validated = $request->validate(array_merge(
            ['token_anterior' => 'required|string|max:255'],
            $this->registrador->reglas($enterpriseId),
        ));

        $anterior = DevicePairing::findByToken($validated['token_anterior'], [DevicePairing::MODE_SF_FIELD]);

        if (! $anterior || $anterior->id === $actual->id || (int) $anterior->enterprise_id !== $enterpriseId) {
            return response()->json([
                'status' => 'error',
                'message' => 'El celular anterior no es válido para rescatar estas checadas.',
            ], 422);
        }

        if ($anterior->estaVigente()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ese celular sigue autorizado; sincroniza normal.',
            ], 422);
        }

        $results = $this->registrador->registrar($validated['checks'], $enterpriseId, [
            'checked_by_user_id' => $anterior->paired_by_user_id,
            'device_pairing_id' => $anterior->id,
            'rescued_by_user_id' => $actual->paired_by_user_id,
            'review_reason' => self::MOTIVO_RESCATE,
        ]);

        return response()->json(['success' => true, 'data' => ['results' => $results]]);
    }

    /** Revoca el propio emparejamiento (devolver el celular). */
    public function desemparejar(Request $request): JsonResponse
    {
        /** @var DevicePairing $pairing */
        $pairing = $request->attributes->get('devicePairing');
        $pairing->update(['revoked_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Celular desemparejado.',
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'code' => $code, 'message' => $message], $status);
    }
}
