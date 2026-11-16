<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Con qué contacto se habló en una actividad (o evento de agenda).
 *
 * Decide si la actividad lo exige (bandera `prospecto_como_empresa`, tipo y
 * fuente), resuelve la «empresa de la actividad» (donde viven los contactos) y
 * valida o crea el contacto. Lo usan todos los endpoints de alta. No revisa
 * permisos: el permiso es el de la actividad que se registra y lo exige quien
 * llama.
 */
class ContactoActividadService
{
    /** Tipos de actividad que exigen contacto; `nota` no. */
    public const TIPOS_CON_CONTACTO = ['llamada', 'whatsapp', 'correo', 'visita', 'reunion'];

    /** Fuentes que exigen contacto; las demás (sistema, Dialpad) nunca. */
    public const FUENTES_CON_CONTACTO = ['manual', 'whatsapp_enlace'];

    public const MENSAJE_FALTA = 'Elige con quién hablaste o agrega un contacto.';

    public const MENSAJE_AJENO = 'El contacto no pertenece a esta empresa.';

    public const MENSAJE_AMBOS = 'Elige un contacto o agrega uno nuevo, no ambos.';

    public const MENSAJE_SIN_ENTIDAD = 'Este registro no tiene empresa para ligar un contacto.';

    /**
     * La empresa que guarda los contactos de la entidad: el prospecto, o su
     * cliente si ya fue convertido; el cliente; la empresa externa; y para una
     * oportunidad, su cliente o (si no tiene) la empresa de su prospecto.
     */
    public function empresaDe(?Model $entidad): ?Model
    {
        if ($entidad instanceof CrmProspecto) {
            return $entidad->clientes()->first() ?? $entidad;
        }

        if ($entidad instanceof CrmCliente || $entidad instanceof CrmEmpresaExterna) {
            return $entidad;
        }

        if ($entidad instanceof CrmOportunidad) {
            return $entidad->cliente ?? $this->empresaDe($entidad->prospecto);
        }

        return null;
    }

    /** ¿Esta actividad debe traer contacto? */
    public function exige(int $empresaId, ?Model $entidad, ?string $tipoActividad, string $fuente = 'manual'): bool
    {
        return ($entidad instanceof CrmProspecto || $entidad instanceof CrmCliente || $entidad instanceof CrmOportunidad)
            && in_array($tipoActividad, self::TIPOS_CON_CONTACTO, true)
            && in_array($fuente, self::FUENTES_CON_CONTACTO, true)
            && CrmConfiguracionComercial::prospectoComoEmpresa($empresaId);
    }

    /** Reglas de validación del payload del contacto (a nivel raíz del cuerpo). */
    public function reglas(): array
    {
        return [
            'contacto_id' => 'nullable|integer',
            'contacto_nuevo' => 'nullable|array',
            'contacto_nuevo.nombre' => 'required_with:contacto_nuevo|string|max:255',
            'contacto_nuevo.cargo' => 'nullable|string|max:255',
            'contacto_nuevo.email' => 'nullable|email|max:255',
            'contacto_nuevo.telefono' => 'nullable|string|max:50',
        ];
    }

    /**
     * Devuelve el id de contacto a guardar (o null). Se llama dentro de la
     * transacción de quien registra la actividad.
     *
     * @param  array  $datos  Cuerpo ya validado: `contacto_id` o `contacto_nuevo`.
     * @throws ValidationException en la llave `contacto_id`.
     */
    public function resolver(int $empresaId, ?Model $entidad, array $datos, bool $obligatorio): ?int
    {
        $contactoId = $datos['contacto_id'] ?? null;
        $nuevo = $datos['contacto_nuevo'] ?? null;

        if ($contactoId !== null && $nuevo !== null) {
            $this->fallar(self::MENSAJE_AMBOS);
        }

        if ($contactoId === null && $nuevo === null) {
            if ($obligatorio) {
                $this->fallar(self::MENSAJE_FALTA);
            }

            return null;
        }

        $empresa = $this->empresaDe($entidad);
        if (! $empresa) {
            $this->fallar(self::MENSAJE_SIN_ENTIDAD);
        }

        if ($contactoId !== null) {
            $pertenece = CrmContacto::where('id', $contactoId)
                ->where('empresa_id', $empresaId)
                ->where('entidad_type', $empresa::class)
                ->where('entidad_id', $empresa->getKey())
                ->exists();

            if (! $pertenece) {
                $this->fallar(self::MENSAJE_AJENO);
            }

            return (int) $contactoId;
        }

        $yaTenia = CrmContacto::where('empresa_id', $empresaId)
            ->where('entidad_type', $empresa::class)
            ->where('entidad_id', $empresa->getKey())
            ->exists();

        $contacto = CrmContacto::create([
            'empresa_id' => $empresaId,
            'entidad_type' => $empresa::class,
            'entidad_id' => $empresa->getKey(),
            'nombre' => $nuevo['nombre'],
            'cargo' => $nuevo['cargo'] ?? null,
            'email' => $nuevo['email'] ?? null,
            'telefono' => $nuevo['telefono'] ?? null,
            'es_principal' => ! $yaTenia,
        ]);

        return (int) $contacto->id;
    }

    private function fallar(string $mensaje): never
    {
        throw ValidationException::withMessages(['contacto_id' => $mensaje]);
    }
}
