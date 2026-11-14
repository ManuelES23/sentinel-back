<?php

namespace Tests\Concerns;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\ConvenioMeta;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\RecepcionCalibre;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\Abastecimiento\VariedadCalibre;
use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserSubmodulePermission;
use App\Services\Abastecimiento\GeneradorFolio;
use App\Services\Abastecimiento\LectorRecepciones;
use Database\Seeders\AbastecimientoModuleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Fixtures de Administración → Abastecimiento: Splendid by Porvenir
 * (`splendidbyporvenir`) y otra empresa (`otraempresa`), ambas con el módulo
 * sembrado (menú, permisos y catálogo inicial), y un usuario sin rol de
 * administrador (`$this->captor`) con acceso a SBP pero sin permisos.
 */
trait CreatesAbastecimientoFixtures
{
    protected Enterprise $sbp;
    protected Enterprise $otra;
    protected User $captor;

    protected function setUpAbastecimiento(): void
    {
        $this->sbp = Enterprise::create([
            'name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir',
            'description' => 'Splendid by Porvenir', 'is_active' => true,
        ]);
        $this->otra = Enterprise::create([
            'name' => 'Otra empresa', 'slug' => 'otraempresa',
            'description' => 'Otra empresa', 'is_active' => true,
        ]);
        $this->sembrarAbs('splendidbyporvenir');
        $this->sembrarAbs('otraempresa');

        $this->captor = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::create([
            'user_id' => $this->captor->id, 'enterprise_id' => $this->sbp->id,
            'is_active' => true, 'granted_at' => now(),
        ]);
    }

    /** Siembra el módulo en la empresa del slug (el seeder real solo corre en SBP). */
    protected function sembrarAbs(string $slug): void
    {
        (new AbastecimientoModuleSeeder())->sembrar($slug);
    }

    /** Da acceso a la empresa y concede los permisos indicados del submódulo. */
    protected function otorgarAbs(User $user, Enterprise $empresa, string $submodulo, array $permisos): void
    {
        UserEnterpriseAccess::firstOrCreate(
            ['user_id' => $user->id, 'enterprise_id' => $empresa->id],
            ['is_active' => true, 'granted_at' => now()],
        );

        $app = Application::firstOrCreate(
            ['enterprise_id' => $empresa->id, 'slug' => 'administration'],
            ['name' => 'Administración', 'path' => "/{$empresa->slug}/administration", 'description' => 'administration'],
        );
        $modulo = Module::firstOrCreate(['application_id' => $app->id, 'slug' => 'abastecimiento'], ['name' => 'Abastecimiento']);
        $sub = Submodule::firstOrCreate(['module_id' => $modulo->id, 'slug' => $submodulo], ['name' => Str::headline($submodulo)]);

        foreach ($permisos as $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $sub->id, 'slug' => $slug],
                ['name' => Str::headline($slug), 'is_active' => true],
            );
            UserSubmodulePermission::updateOrCreate(
                ['user_id' => $user->id, 'submodule_id' => $sub->id, 'permission_type_id' => $tipo->id],
                ['is_granted' => true],
            );
        }
    }

    /**
     * Convenio de compra directa por caja en MXN, con precio principal y metas
     * semanales. Claves especiales de $over: `precio` (número, por omisión 100)
     * y `metas` (['Y-m-d lunes' => cantidad]); el resto son columnas del convenio.
     */
    protected function crearConvenio(Enterprise $empresa, array $over = []): Convenio
    {
        $precio = array_key_exists('precio', $over) ? $over['precio'] : 100; // null = sin precio clásico
        $metas = $over['metas'] ?? ['2026-06-01' => 100, '2026-06-08' => 100, '2026-06-15' => 100, '2026-06-22' => 100];
        unset($over['precio'], $over['metas']);

        $cultivo = isset($over['cultivo_id'])
            ? Cultivo::findOrFail($over['cultivo_id'])
            : Cultivo::where('enterprise_id', $empresa->id)->where('nombre', 'Mango')->firstOrFail();
        $variedad = isset($over['variedad_id'])
            ? Variedad::findOrFail($over['variedad_id'])
            : Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();
        $tipoCarga = TipoCarga::where('enterprise_id', $empresa->id)->orderBy('id')->firstOrFail();

        $temporada = $over['temporada_id'] ?? Temporada::firstOrCreate(
            ['enterprise_id' => $empresa->id, 'cultivo_id' => $cultivo->id, 'nombre' => 'Temporada de prueba'],
            ['fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-09-30', 'estado' => 'activa'],
        )->id;
        $productor = $over['productor_id'] ?? Productor::firstOrCreate(
            ['enterprise_id' => $empresa->id, 'nombre' => 'Productor de prueba'],
            ['tipo' => 'productor', 'is_active' => true],
        )->id;

        return DB::transaction(function () use ($empresa, $over, $cultivo, $variedad, $tipoCarga, $temporada, $productor, $precio, $metas) {
            $convenio = Convenio::create(array_merge([
                'enterprise_id' => $empresa->id,
                'folio' => app(GeneradorFolio::class)->siguiente($empresa, 'CV'),
                'temporada_id' => $temporada,
                'productor_id' => $productor,
                'cultivo_id' => $cultivo->id,
                'variedad_id' => $variedad->id,
                'modalidad' => 'compra_directa',
                'unidad' => 'caja',
                'moneda' => 'MXN',
                'porcentaje_rezaga' => 0,
                'fecha_inicio' => '2026-06-01',
                'fecha_fin' => '2026-06-28',
                'modo_volumen' => 'semanal',
                'volumen_total' => null,
                'status' => 'activo',
            ], $over));

            if ($precio !== null) {
                ConvenioPrecio::create([
                    'convenio_id' => $convenio->id,
                    'tipo_carga_id' => $tipoCarga->id,
                    'precio' => $precio,
                    'vigencia_inicio' => $convenio->fecha_inicio->format('Y-m-d'),
                    'es_principal' => true,
                ]);
            }

            foreach ($metas as $semana => $cantidad) {
                ConvenioMeta::create(['convenio_id' => $convenio->id, 'semana' => $semana, 'cantidad' => $cantidad]);
            }

            return $convenio->load(['precios', 'metas']);
        });
    }

    /** Calibre de la variedad, creado directo (sin pasar por la API del catálogo). */
    protected function crearCalibre(Variedad $variedad, string $nombre, bool $activo = true): VariedadCalibre
    {
        return VariedadCalibre::create([
            'variedad_id' => $variedad->id,
            'nombre' => $nombre,
            'orden' => 0,
            'is_active' => $activo,
        ]);
    }

    /**
     * Convenio por calibre: `usa_calibres`, volumen total por calibre (modo `total`) y un
     * precio por calibre del tipo de carga principal vigente desde el inicio del convenio.
     * `$calibres` = [['calibre' => VariedadCalibre, 'volumen' => 100, 'precio' => 120.0], …].
     * Claves especiales de `$over` como en `crearConvenio` (salvo `precio` y `metas`, que no aplican).
     */
    protected function crearConvenioPorCalibre(Enterprise $empresa, array $calibres, array $over = []): Convenio
    {
        unset($over['precio'], $over['metas']);
        $convenio = $this->crearConvenio($empresa, array_merge($over, [
            'precio' => null,
            'metas' => [],
            'usa_calibres' => true,
            'modo_volumen' => 'total',
            'volumen_total' => null,
        ]));
        $tipoCarga = TipoCarga::where('enterprise_id', $empresa->id)->orderBy('id')->firstOrFail();

        DB::transaction(function () use ($convenio, $calibres, $tipoCarga) {
            foreach ($calibres as $c) {
                ConvenioCalibre::create([
                    'convenio_id' => $convenio->id,
                    'calibre_id' => $c['calibre']->id,
                    'volumen_total' => $c['volumen'] ?? null,
                ]);
                ConvenioPrecio::create([
                    'convenio_id' => $convenio->id,
                    'tipo_carga_id' => $tipoCarga->id,
                    'calibre_id' => $c['calibre']->id,
                    'precio' => $c['precio'] ?? 100,
                    'vigencia_inicio' => $convenio->fecha_inicio->format('Y-m-d'),
                    'es_principal' => true,
                ]);
            }
        });

        return $convenio->load(['precios', 'metas', 'calibres']);
    }

    /**
     * Recepción de un convenio por calibre con sus renglones; la cabecera queda como la
     * suma (kilos y rezaga nulos si ningún renglón los trae).
     * `$renglones` = [['calibre_id' => id, 'cantidad' => 10, 'kilos' => null, 'rezaga_kg' => null], …].
     */
    protected function crearRecepcionConRenglones(Convenio $convenio, array $renglones, array $over = []): Recepcion
    {
        $suma = fn (string $clave) => collect($renglones)->whereNotNull($clave)->isEmpty()
            ? null
            : collect($renglones)->sum(fn ($r) => (float) ($r[$clave] ?? 0));

        return DB::transaction(function () use ($convenio, $renglones, $over, $suma) {
            $recepcion = $this->crearRecepcion($convenio, array_merge([
                'cantidad' => $suma('cantidad') ?? 0,
                'kilos' => $suma('kilos'),
                'rezaga_kg' => $suma('rezaga_kg'),
            ], $over));

            foreach ($renglones as $r) {
                RecepcionCalibre::create([
                    'recepcion_id' => $recepcion->id,
                    'calibre_id' => $r['calibre_id'],
                    'cantidad' => $r['cantidad'],
                    'kilos' => $r['kilos'] ?? null,
                    'rezaga_kg' => $r['rezaga_kg'] ?? null,
                ]);
            }

            return $recepcion->load('renglones');
        });
    }

    /** Recepción contra el convenio (tipo de carga del precio principal, 10 cajas el primer día). */
    protected function crearRecepcion(Convenio $convenio, array $over = []): Recepcion
    {
        $empresa = Enterprise::findOrFail($convenio->enterprise_id);
        $tipoCarga = $convenio->precios()->where('es_principal', true)->value('tipo_carga_id');

        return DB::transaction(fn () => Recepcion::create(array_merge([
            'enterprise_id' => $empresa->id,
            'folio' => app(GeneradorFolio::class)->siguiente($empresa, 'RC'),
            'convenio_id' => $convenio->id,
            'fecha' => $convenio->fecha_inicio->format('Y-m-d'),
            'tipo_carga_id' => $tipoCarga,
            'cantidad' => 10,
        ], $over)));
    }

    /**
     * Liquidación en borrador del convenio, creada directo (sin pasar por los servicios):
     * mismo periodo, moneda y productor que el convenio, importes en cero. `$over` pisa
     * cualquier columna; el folio sale de GeneradorFolio ('LQ') salvo que se indique.
     */
    protected function crearLiquidacion(Convenio $convenio, array $over = []): Liquidacion
    {
        $empresa = Enterprise::findOrFail($convenio->enterprise_id);

        return DB::transaction(fn () => Liquidacion::create(array_merge([
            'enterprise_id' => $empresa->id,
            'folio' => $over['folio'] ?? app(GeneradorFolio::class)->siguiente($empresa, 'LQ'),
            'convenio_id' => $convenio->id,
            'productor_id' => $convenio->productor_id,
            'moneda' => $convenio->moneda,
            'periodo_inicio' => $convenio->fecha_inicio->format('Y-m-d'),
            'periodo_fin' => $convenio->fecha_fin->format('Y-m-d'),
        ], $over)));
    }

    /**
     * Excel de recepciones en un archivo temporal, listo para subir. `$filas` son listas de
     * valores en el orden de `$encabezados` (por omisión, los de la plantilla) y empiezan en
     * la fila 2. Los números quedan como número (así llegan las fechas en serie de Excel) y
     * el resto como texto; null no escribe celda.
     */
    protected function crearXlsx(array $filas, ?array $encabezados = null, string $nombre = 'recepciones.xlsx'): UploadedFile
    {
        $encabezados ??= LectorRecepciones::ENCABEZADOS;
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();

        foreach (array_merge([$encabezados], $filas) as $i => $fila) {
            foreach (array_values($fila) as $j => $valor) {
                if ($valor === null) {
                    continue;
                }
                $celda = Coordinate::stringFromColumnIndex($j + 1).($i + 1);
                $hoja->setCellValueExplicit(
                    $celda,
                    $valor,
                    is_int($valor) || is_float($valor) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING,
                );
            }
        }

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        unlink($ruta);
        $ruta .= '.xlsx';
        (new Xlsx($libro))->save($ruta);
        $this->beforeApplicationDestroyed(fn () => @unlink($ruta));

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    /**
     * Abono aplicado al productor, creado directo (sin aplicaciones). Por omisión: 100 MXN
     * por transferencia el 2026-06-10; el folio sale de GeneradorFolio ('AB') salvo que se indique.
     */
    protected function crearAbono(Productor $productor, array $over = []): Abono
    {
        $empresa = Enterprise::findOrFail($productor->enterprise_id);

        return DB::transaction(fn () => Abono::create(array_merge([
            'enterprise_id' => $empresa->id,
            'folio' => $over['folio'] ?? app(GeneradorFolio::class)->siguiente($empresa, 'AB'),
            'productor_id' => $productor->id,
            'fecha' => '2026-06-10',
            'monto' => 100,
            'moneda' => 'MXN',
            'metodo_pago' => 'transferencia',
        ], $over)));
    }
}
