<?php

namespace App\Support\CRM;

use App\Models\Calibre;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmPresentacion;
use App\Models\CRM\CrmProducto;
use App\Models\Variedad;
use Illuminate\Support\Facades\DB;

/**
 * Prepara Splendid by Porvenir para cotizar por caja (spec 2026-10-31 §5):
 * el elote pasa a tres variedades, cada producto se liga a su variedad del
 * catálogo agrícola, se precargan las presentaciones y se enciende el
 * interruptor. Re-ejecutable sin duplicar; solo toca la empresa recibida.
 */
class CotizacionPorCajaSbp
{
    /** @var array<string, string> nombre viejo => nombre nuevo */
    public const RENOMBRAR = [
        'Yellow Corn Bulk' => 'Yellow Corn',
        'White Corn Bulk' => 'White Corn',
        'Bicolor Corn Bulk' => 'Bicolor Corn',
    ];

    /** @var list<string> */
    public const DESACTIVAR = [
        'Yellow Corn Tray', 'White Corn Tray', 'Bicolor Corn Tray',
        'Mini Cob Yellow', 'Mini Cob Bicolor',
    ];

    /** @var array<string, array{0: string, 1: string}> producto => [cultivo, variedad] */
    public const LIGAS = [
        'Kent Mango' => ['Mango', 'Kent'], 'Kent Organic Mango' => ['Mango', 'Kent'],
        'Ataulfo Mango' => ['Mango', 'Ataulfo'], 'Ataulfo Organic Mango' => ['Mango', 'Ataulfo'],
        'Keitt Mango' => ['Mango', 'Keitt'], 'Tommy Atkins Mango' => ['Mango', 'Tommy Atkins'],
        'Nam Doc Mai Mango' => ['Mango', 'Nam Dok Mai'],
        'Yellow Corn' => ['Elote Dulce', 'Amarillo'], 'White Corn' => ['Elote Dulce', 'Blanco'], 'Bicolor Corn' => ['Elote Dulce', 'Bicolor'],
    ];

    /** @var array<string, list<string>> commodity => presentaciones (el orden es la posición) */
    public const PRESENTACIONES = [
        'Mango' => ['Caja cartón 8.85 LBS B15', 'Caja cartón 9 LBS B14', 'Charola Master', 'Charola 18 LBS', 'Charola 24 LBS', 'Caja plástico RPC 6411'],
        'Corn' => ['Bulk RPC', 'Bulk DPC', 'Tray RPC', 'Tray cartón euro', 'Tray 5 pz', 'Minicobs'],
    ];

    public static function aplicar(int $empresaId): void
    {
        DB::transaction(function () use ($empresaId) {
            self::renombrar($empresaId);
            self::desactivar($empresaId);
            self::ligar($empresaId);
            self::precargarPresentaciones($empresaId);

            CrmConfiguracionComercial::paraEmpresa($empresaId)->update(['cotizacion_por_caja' => true]);
        });
    }

    private static function renombrar(int $empresaId): void
    {
        foreach (self::RENOMBRAR as $viejo => $nuevo) {
            $productos = CrmProducto::where('empresa_id', $empresaId)->where('nombre', $viejo)->orderBy('id')->get();

            // Si el nombre destino ya existe, el viejo no se renombra: se desactiva.
            // Con duplicados a mano solo el primero toma el nombre nuevo; los demás se desactivan.
            $yaExiste = CrmProducto::where('empresa_id', $empresaId)->where('nombre', $nuevo)->exists();
            foreach ($productos as $producto) {
                if ($yaExiste) {
                    $producto->update(['activo' => false]);
                } else {
                    $producto->update(['nombre' => $nuevo]);
                    $yaExiste = true;
                }
            }
        }
    }

    private static function desactivar(int $empresaId): void
    {
        CrmProducto::where('empresa_id', $empresaId)
            ->whereIn('nombre', self::DESACTIVAR)
            ->where('activo', true)
            ->update(['activo' => false]);
    }

    private static function ligar(int $empresaId): void
    {
        foreach (self::LIGAS as $nombre => [$cultivo, $variedad]) {
            $productos = CrmProducto::where('empresa_id', $empresaId)
                ->where('nombre', $nombre)
                ->whereNull('variedad_id')
                ->get();
            if ($productos->isEmpty()) {
                continue;
            }

            // Sin cultivo/variedad (o borrados) la liga queda vacía; no es un error.
            // Con nombres repetidos gana la que tiene calibres activos y luego la de menor id.
            $variedadModelo = Variedad::where('nombre', $variedad)
                ->whereHas('cultivo', fn ($q) => $q->where('nombre', $cultivo))
                ->orderByDesc(Calibre::query()->where('is_active', true)
                    ->whereColumn('calibres.variedad_id', 'variedades.id')
                    ->selectRaw('count(*)'))
                ->orderBy('id')
                ->first();
            if ($variedadModelo === null) {
                continue;
            }

            foreach ($productos as $producto) {
                $producto->update(['variedad_id' => $variedadModelo->id]);
            }
        }
    }

    private static function precargarPresentaciones(int $empresaId): void
    {
        foreach (self::PRESENTACIONES as $commodity => $nombres) {
            foreach ($nombres as $i => $nombre) {
                CrmPresentacion::firstOrCreate(
                    ['empresa_id' => $empresaId, 'commodity' => $commodity, 'nombre' => $nombre],
                    ['orden' => $i + 1, 'activo' => true]
                );
            }
        }
    }
}
