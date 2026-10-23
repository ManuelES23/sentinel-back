<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmPlantillaWhatsapp;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use App\Support\CRM\PlantillaWhatsappVariables;
use App\Support\CRM\TelefonoWhatsApp;
use Illuminate\Database\Eloquent\Model;

/**
 * CRM · WhatsApp Fase 0 (spec 2026-10-12 §5.2 y §5.3): a quién se le puede
 * escribir desde una entidad y qué mensaje sale de una plantilla. No decide
 * permisos, tenant ni visibilidad: eso lo hace WhatsAppController antes.
 */
class WhatsAppMensajeService
{
    public const TIPOS_ENTIDAD = ['cliente', 'prospecto', 'empresa_externa', 'oportunidad'];

    public const CLAVE_ENTIDAD = 'entidad';

    public const PREFIJO_CONTACTO = 'contacto:';

    public function __construct(private readonly SeguimientoService $seguimientos) {}

    public function buscarEntidad(int $empresaId, string $tipo, int $id): ?Model
    {
        return in_array($tipo, self::TIPOS_ENTIDAD, true)
            ? $this->seguimientos->buscarEntidad($empresaId, $tipo, $id)
            : null;
    }

    /**
     * Dueño del teléfono y de los contactos: la oportunidad se resuelve a su
     * cliente o, si no tiene (o está eliminado), a su prospecto. Un cliente o
     * prospecto de otra empresa (FK corrupta) se descarta: nunca se exponen
     * teléfonos de otro tenant.
     */
    public function titular(Model $entidad): ?Model
    {
        if ($entidad instanceof CrmOportunidad) {
            $mismaEmpresa = fn (?Model $modelo) => $modelo !== null
                && (int) $modelo->empresa_id === (int) $entidad->empresa_id ? $modelo : null;

            return $mismaEmpresa($entidad->cliente) ?? $mismaEmpresa($entidad->prospecto);
        }

        return $entidad;
    }

    public function nombreDe(?Model $modelo): ?string
    {
        return match (true) {
            $modelo === null => null,
            $modelo instanceof CrmEmpresaExterna => $modelo->razon_social,
            default => $modelo->nombre,
        };
    }

    /**
     * El cliente o prospecto en sí (si tiene teléfono) y después sus contactos,
     * los principales primero. La empresa externa no tiene teléfono propio.
     *
     * @return array<int, array{clave: string, nombre: string, rol: string, telefono: ?string, telefono_whatsapp: ?string}>
     */
    public function destinatarios(Model $entidad): array
    {
        $titular = $this->titular($entidad);
        if (! $titular) {
            return [];
        }

        $lista = [];

        if (($titular instanceof CrmCliente || $titular instanceof CrmProspecto) && filled($titular->telefono)) {
            $lista[] = [
                'clave' => self::CLAVE_ENTIDAD,
                'nombre' => $titular->nombre,
                'rol' => $titular instanceof CrmCliente ? 'Cliente' : 'Prospecto',
                'telefono' => $titular->telefono,
                'telefono_whatsapp' => TelefonoWhatsApp::normalizar($titular->telefono),
            ];
        }

        $contactos = CrmContacto::query()
            ->where('empresa_id', $titular->empresa_id)
            ->where('entidad_type', $titular::class)
            ->where('entidad_id', $titular->id)
            ->orderByDesc('es_principal')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'cargo', 'telefono', 'es_principal']);

        foreach ($contactos as $contacto) {
            $lista[] = [
                'clave' => self::PREFIJO_CONTACTO.$contacto->id,
                'nombre' => $contacto->nombre,
                'rol' => $this->rolContacto($contacto),
                'telefono' => $contacto->telefono,
                'telefono_whatsapp' => TelefonoWhatsApp::normalizar($contacto->telefono),
            ];
        }

        return $lista;
    }

    public function destinatario(Model $entidad, string $clave): ?array
    {
        foreach ($this->destinatarios($entidad) as $destinatario) {
            if ($destinatario['clave'] === $clave) {
                return $destinatario;
            }
        }

        return null;
    }

    /**
     * La cotización debe colgar de la misma entidad: de esa oportunidad, o de
     * una oportunidad de ese cliente o prospecto. Sin oportunidad, nunca.
     */
    public function cotizacionEsDeLaEntidad(Model $entidad, CrmCotizacion $cotizacion): bool
    {
        $oportunidad = $cotizacion->oportunidad;
        if (! $oportunidad) {
            return false;
        }

        return match (true) {
            $entidad instanceof CrmOportunidad => (int) $oportunidad->id === (int) $entidad->id,
            $entidad instanceof CrmCliente => (int) $oportunidad->cliente_id === (int) $entidad->id,
            $entidad instanceof CrmProspecto => (int) $oportunidad->prospecto_id === (int) $entidad->id,
            default => false,
        };
    }

    /**
     * Valores de las variables (§5.3). `null` = sin dato (se reporta como faltante).
     *
     * @return array<string, ?string>
     */
    public function valores(Model $entidad, ?array $destinatario, ?CrmCotizacion $cotizacion, ?CrmVendedor $vendedor, ?string $empresa): array
    {
        $nombreEntidad = $this->nombreDe($this->titular($entidad));
        $oportunidad = $entidad instanceof CrmOportunidad ? $entidad : $cotizacion?->oportunidad;
        $moneda = CrmConfiguracionComercial::monedaDe((int) $entidad->empresa_id);

        return [
            'nombre' => $destinatario ? $this->nombreParaSaludo($destinatario) : $nombreEntidad,
            'entidad' => $nombreEntidad,
            'vendedor' => $vendedor?->nombre,
            'empresa' => $empresa,
            'oportunidad' => $oportunidad?->nombre,
            'monto' => $oportunidad ? self::moneda($oportunidad->monto_esperado, $moneda) : null,
            'folio' => $cotizacion?->folio,
            'total' => $cotizacion ? self::moneda($cotizacion->total, $moneda) : null,
        ];
    }

    /** @return array{mensaje: string, variables_faltantes: string[]} */
    public function renderizar(CrmPlantillaWhatsapp $plantilla, array $valores): array
    {
        return PlantillaWhatsappVariables::sustituir($plantilla->cuerpo, $valores);
    }

    /** Dinero según la moneda de la empresa: MXN `$12,345.00`, USD `USD 12,345.00`. */
    public static function moneda(mixed $valor, string $moneda = 'MXN'): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $importe = number_format((float) $valor, 2);

        return $moneda === 'USD' ? 'USD '.$importe : '$'.$importe;
    }

    private function rolContacto(CrmContacto $contacto): string
    {
        $rol = trim((string) $contacto->cargo) ?: 'Contacto';

        return $contacto->es_principal ? "{$rol} · principal" : $rol;
    }

    /**
     * A un contacto se le saluda por su primer nombre; al cliente o prospecto
     * por su nombre completo (suele ser una razón social: «Hola Agrícola» se lee mal).
     */
    private function nombreParaSaludo(array $destinatario): string
    {
        if (! str_starts_with($destinatario['clave'], self::PREFIJO_CONTACTO)) {
            return $destinatario['nombre'];
        }

        $partes = preg_split('/\s+/u', trim($destinatario['nombre']));

        return $partes[0] ?? $destinatario['nombre'];
    }
}
