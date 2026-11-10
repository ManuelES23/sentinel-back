<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmLinea;
use App\Models\CRM\CrmMarca;
use App\Models\CRM\CrmProducto;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\CRM\ImportacionCatalogo\LectorCatalogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Importación del catálogo de productos desde Excel (revisar sin escribir, luego importar). */
class ImportacionCatalogoTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const REVISAR = '/api/crm/productos/importar/revisar';

    private const IMPORTAR = '/api/crm/productos/importar';

    private const ENCABEZADO = ['CLAVE', 'PRODUCTO', 'MARCA', 'LINEA', 'SUBLINEA'];

    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['catalogo_por_lineas' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    private function h(): array
    {
        return $this->crmHeaders() + ['Accept' => 'application/json'];
    }

    /** Arma un .xlsx; los enteros PHP se guardan como número y el resto como texto. */
    private function xlsx(array $filas, ?array $encabezado = self::ENCABEZADO): string
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $todas = $encabezado === null ? $filas : array_merge([$encabezado], $filas);
        foreach ($todas as $i => $fila) {
            foreach (array_values($fila) as $j => $valor) {
                if ($valor === null) {
                    continue;
                }
                $col = Coordinate::stringFromColumnIndex($j + 1).($i + 1);
                $hoja->setCellValueExplicit($col, $valor, is_int($valor) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
        $ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'imp_'.uniqid().'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $this->temporales[] = $ruta;

        return $ruta;
    }

    private function subir(string $ruta): \Illuminate\Testing\TestResponse
    {
        $archivo = new UploadedFile($ruta, 'catalogo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        return $this->withHeaders($this->h())->post(self::REVISAR, ['archivo' => $archivo]);
    }

    private function importar(string $token, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->h())->postJson(self::IMPORTAR, $extra + ['token' => $token, 'elegidas' => [], 'desactivar_faltantes' => false]);
    }

    private function producto(array $extra = []): CrmProducto
    {
        return CrmProducto::create($extra + [
            'empresa_id' => $this->enterprise->id, 'nombre' => 'P'.uniqid(), 'precio' => 0, 'unidad_medida' => 'litro', 'activo' => true,
        ]);
    }

    private function usuarioConPermisos(array $permisos): void
    {
        $this->actingUser = User::factory()->create();
        UserEnterpriseAccess::create([
            'user_id' => $this->actingUser->id, 'enterprise_id' => $this->enterprise->id,
            'is_active' => true, 'granted_at' => now(),
        ]);
        Sanctum::actingAs($this->actingUser);
        $this->otorgarPermisosCrm('catalogos', 'productos', $permisos);
    }

    public function test_lee_claves_numericas_como_texto(): void
    {
        $ruta = $this->xlsx([
            [10240, 'A', 'Bayer', 'FUNG', null],
            [12000009, 'B', 'Bayer', 'FUNG', null],
            [1101256, 'C', 'Bayer', 'FUNG', null],
            ['0116807-1', 'D', 'Bayer', 'FUNG', null],
        ]);

        $lectura = (new LectorCatalogo())->leer($ruta);

        $this->assertSame(['10240', '12000009', '1101256', '0116807-1'], array_column($lectura['filas'], 'clave'));
        $this->assertSame([2, 3, 4, 5], array_column($lectura['filas'], 'fila'));
        $this->assertFalse($lectura['tieneUnidad']);
        $this->assertNull($lectura['filas'][0]['sublinea']);
    }

    public function test_encabezados_sin_acentos_ni_mayusculas_y_columnas_extra(): void
    {
        $ruta = $this->xlsx([
            ['1', 'Karate', 'Syngenta', 'Insecticidas', 'Piretroide', 'nota'],
            [null, null, null, null, null, null],
            ['2', 'Otro', 'Bayer', 'Fungicidas', null, 'x'],
        ], ['Clave', 'Producto', 'Marca', 'Línea', 'SubLínea', 'NOTAS']);

        $lectura = (new LectorCatalogo())->leer($ruta);

        $this->assertCount(2, $lectura['filas']);
        $this->assertSame('Insecticidas', $lectura['filas'][0]['linea']);
        $this->assertSame('Piretroide', $lectura['filas'][0]['sublinea']);
        $this->assertSame(4, $lectura['filas'][1]['fila']);
        $this->assertSame([], $lectura['problemas']);
    }

    public function test_sin_columna_clave_es_error_de_archivo(): void
    {
        $ruta = $this->xlsx([['A', 'Bayer']], ['PRODUCTO', 'MARCA']);

        $this->subir($ruta)->assertStatus(422)
            ->assertJsonPath('message', 'El archivo no tiene las columnas CLAVE y PRODUCTO.');
    }

    public function test_revision_cuenta_y_no_escribe(): void
    {
        $ruta = $this->xlsx([
            ['110104', 'Uno', 'Bayer', 'FUNG', null],
            ['110104', 'Uno bis', 'Basf', 'INSEC', 'X'],
            ['110105', 'Dos', 'Bayer', 'FUNG', null],
            ['110106', 'Tres', '', 'FUNG', null],
            ['110107', 'Cuatro', 'Bayer', 'FUNG', 'Y'],
        ]);

        $r = $this->subir($ruta)->assertOk();

        $r->assertJsonPath('data.resumen.nuevos', 3)
            ->assertJsonPath('data.resumen.actualizados', 0)
            ->assertJsonPath('data.resumen.conProblema', 1)
            ->assertJsonPath('data.resumen.problemas.0.fila', 5)
            ->assertJsonPath('data.resumen.problemas.0.motivo', 'Falta la marca')
            ->assertJsonCount(1, 'data.resumen.repetidas')
            ->assertJsonPath('data.resumen.repetidas.0.clave', '110104')
            ->assertJsonCount(2, 'data.resumen.repetidas.0.filas')
            ->assertJsonPath('data.resumen.repetidas.0.filas.1.fila', 3)
            ->assertJsonPath('data.resumen.marcasNuevas', 1)
            ->assertJsonPath('data.resumen.lineasNuevas', 1)
            ->assertJsonPath('data.resumen.sublineasNuevas', 1)
            ->assertJsonPath('data.resumen.sinUnidad', true);
        $this->assertNotEmpty($r->json('data.token'));
        $this->assertNotEmpty($r->json('data.resumen.huella'));

        $this->assertDatabaseCount('crm_productos', 0);
        $this->assertDatabaseCount('crm_marcas', 0);
        $this->assertDatabaseCount('crm_lineas', 0);
        $this->assertDatabaseCount('crm_sublineas', 0);
    }

    public function test_importa_y_crea_catalogos(): void
    {
        $ruta = $this->xlsx([
            ['1', 'Karate', 'Syngenta', 'Insecticidas', 'Piretroide'],
            ['2', 'Amistar', 'Syngenta', 'Fungicidas', 'Estrobilurina'],
            ['3', 'Folio', 'Bayer', 'Fungicidas', 'Bactericida'],
            ['4', 'Sin sub', 'Bayer', 'Fungicidas', null],
        ]);
        $token = $this->subir($ruta)->json('data.token');

        $r = $this->importar($token)->assertOk();

        $r->assertJsonPath('data.nuevos', 4)->assertJsonPath('data.marcasNuevas', 2)
            ->assertJsonPath('data.lineasNuevas', 2)->assertJsonPath('data.sublineasNuevas', 3);
        $this->assertDatabaseCount('crm_productos', 4);
        $this->assertDatabaseCount('crm_marcas', 2);
        $this->assertDatabaseCount('crm_lineas', 2);
        $this->assertDatabaseCount('crm_sublineas', 3);
        $p = CrmProducto::where('clave', '3')->first();
        $this->assertSame('pieza', $p->unidad_medida);
        $this->assertSame('3', $p->clave_normalizada);
        $this->assertTrue($p->activo);
        $this->assertEquals(0, $p->precio);
        $this->assertSame('Bayer', $p->marca->nombre);
        $this->assertSame('Fungicidas', $p->linea->nombre);
        $this->assertSame('Bactericida', $p->sublinea->nombre);
        $this->assertNull(CrmProducto::where('clave', '4')->first()->sublinea_id);
        $this->assertFalse(Storage::disk('local')->exists('crm/importaciones/'.$token.'.xlsx'));
    }

    public function test_unidades_del_excel(): void
    {
        $ruta = $this->xlsx([
            ['1', 'A', 'M', 'L', null, 'LTS'],
            ['2', 'B', 'M', 'L', null, 'Kilo'],
            ['3', 'C', 'M', 'L', null, 'galón'],
            ['4', 'D', 'M', 'L', null, null],
        ], ['CLAVE', 'PRODUCTO', 'MARCA', 'LINEA', 'SUBLINEA', 'UNIDAD']);
        $r = $this->subir($ruta)->assertOk();
        $r->assertJsonPath('data.resumen.sinUnidad', false)->assertJsonPath('data.resumen.conProblema', 1)
            ->assertJsonPath('data.resumen.problemas.0.motivo', 'Unidad no reconocida: galón');

        $this->importar($r->json('data.token'))->assertOk();

        $this->assertSame('litro', CrmProducto::where('clave', '1')->value('unidad_medida'));
        $this->assertSame('kg', CrmProducto::where('clave', '2')->value('unidad_medida'));
        $this->assertSame('pieza', CrmProducto::where('clave', '4')->value('unidad_medida'));
        $this->assertNull(CrmProducto::where('clave', '3')->first());
    }

    public function test_sublinea_mismo_nombre_en_dos_lineas(): void
    {
        $ruta = $this->xlsx([
            ['1', 'A', 'M', 'Fungicidas', 'Otros'],
            ['2', 'B', 'M', 'Insecticidas', 'otros'],
        ]);
        $r = $this->subir($ruta)->assertOk();
        $r->assertJsonPath('data.resumen.sublineasNuevas', 2);

        $this->importar($r->json('data.token'))->assertOk();

        $this->assertDatabaseCount('crm_sublineas', 2);
        $a = CrmProducto::where('clave', '1')->first();
        $b = CrmProducto::where('clave', '2')->first();
        $this->assertNotSame($a->sublinea_id, $b->sublinea_id);
        $this->assertSame($a->linea_id, $a->sublinea->linea_id);
        $this->assertSame($b->linea_id, $b->sublinea->linea_id);
    }

    public function test_clave_con_espacios_y_mayusculas_actualiza_la_existente(): void
    {
        $existente = $this->producto(['clave' => 'ABC-1', 'nombre' => 'Viejo']);
        $ruta = $this->xlsx([[' abc-1 ', 'Nuevo nombre', 'M', 'L', null]]);
        $r = $this->subir($ruta)->assertOk();
        $r->assertJsonPath('data.resumen.actualizados', 1)->assertJsonPath('data.resumen.nuevos', 0);

        $this->importar($r->json('data.token'))->assertOk()->assertJsonPath('data.actualizados', 1);

        $this->assertDatabaseCount('crm_productos', 1);
        $this->assertSame('Nuevo nombre', $existente->fresh()->nombre);
        $this->assertSame('litro', $existente->fresh()->unidad_medida);
    }

    public function test_sin_cambio_cuando_el_excel_coincide(): void
    {
        $marca = CrmMarca::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Bayer']);
        $linea = CrmLinea::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Fungicidas']);
        $this->producto(['clave' => '9', 'nombre' => 'Igual', 'marca_id' => $marca->id, 'linea_id' => $linea->id]);
        $ruta = $this->xlsx([['9', 'Igual', ' BAYER', 'fungicidas', null]]);

        $this->subir($ruta)->assertOk()->assertJsonPath('data.resumen.sinCambio', 1)
            ->assertJsonPath('data.resumen.actualizados', 0)->assertJsonPath('data.resumen.marcasNuevas', 0);
    }

    public function test_el_excel_manda_sobre_lo_editado(): void
    {
        $bayer = CrmMarca::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Bayer']);
        $fung = CrmLinea::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Fungicidas']);
        $herb = CrmLinea::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Herbicidas']);
        $p = $this->producto(['clave' => '7', 'nombre' => 'X', 'marca_id' => $bayer->id, 'linea_id' => $herb->id]);

        $r = $this->subir($this->xlsx([['7', 'X', 'Bayer', 'Fungicidas', null]]))->assertOk();
        $r->assertJsonPath('data.resumen.actualizados', 1);
        $this->importar($r->json('data.token'))->assertOk();

        $this->assertSame($fung->id, $p->fresh()->linea_id);
    }

    public function test_elegir_fila_de_clave_repetida(): void
    {
        $ruta = $this->xlsx([
            ['110104', 'Primera', 'Bayer', 'FUNG', null],
            ['110104', 'Segunda', 'Basf', 'FUNG', null],
        ]);
        $token = $this->subir($ruta)->json('data.token');

        $this->importar($token, ['elegidas' => ['110104' => 3]])->assertOk()->assertJsonPath('data.nuevos', 1);

        $this->assertDatabaseCount('crm_productos', 1);
        $this->assertSame('Segunda', CrmProducto::first()->nombre);
        $this->assertSame('Basf', CrmProducto::first()->marca->nombre);
        $this->assertDatabaseCount('crm_marcas', 1);
    }

    public function test_elegida_se_compara_normalizada(): void
    {
        $ruta = $this->xlsx([
            ['ABC-1', 'Primera', 'Bayer', 'FUNG', null],
            [' abc-1', 'Segunda', 'Bayer', 'FUNG', null],
        ]);
        $token = $this->subir($ruta)->json('data.token');

        $this->importar($token, ['elegidas' => ['ABC-1' => 3]])->assertOk();

        $this->assertSame('Segunda', CrmProducto::first()->nombre);
    }

    public function test_por_defecto_se_queda_la_primera_fila_repetida(): void
    {
        $token = $this->subir($this->xlsx([
            ['5', 'Primera', 'B', 'L', null],
            ['5', 'Segunda', 'B', 'L', null],
        ]))->json('data.token');

        $this->importar($token)->assertOk();

        $this->assertSame('Primera', CrmProducto::first()->nombre);
    }

    public function test_faltantes_solo_se_desactivan_si_se_pide(): void
    {
        $falta = $this->producto(['clave' => 'F1']);
        $sinClave = $this->producto(['nombre' => 'manual']);
        $viene = $this->producto(['clave' => 'V1', 'nombre' => 'V']);
        $ruta = $this->xlsx([['V1', 'V', 'M', 'L', null]]);

        $r = $this->subir($ruta)->assertOk();
        $r->assertJsonPath('data.resumen.faltantes', 1);
        $this->importar($r->json('data.token'))->assertOk();
        $this->assertTrue($falta->fresh()->activo);

        $r = $this->subir($ruta)->assertOk();
        $this->importar($r->json('data.token'), ['desactivar_faltantes' => true])->assertOk()->assertJsonPath('data.desactivados', 1);
        $this->assertFalse($falta->fresh()->activo);
        $this->assertTrue($sinClave->fresh()->activo);
        $this->assertTrue($viene->fresh()->activo);
        $this->assertDatabaseCount('crm_productos', 3);
    }

    public function test_token_de_otro_usuario_da_422(): void
    {
        $token = $this->subir($this->xlsx([['1', 'A', 'M', 'L', null]]))->json('data.token');

        $this->usuarioConPermisos(['crear', 'editar']);

        $this->importar($token)->assertStatus(422)
            ->assertJsonPath('message', 'La revisión venció; vuelve a subir el archivo.');
        $this->assertDatabaseCount('crm_productos', 0);
    }

    public function test_token_inexistente_da_422(): void
    {
        $this->importar('no-existe')->assertStatus(422)
            ->assertJsonPath('message', 'La revisión venció; vuelve a subir el archivo.');
    }

    public function test_huella_cambiada_da_409_con_resumen_nuevo(): void
    {
        $token = $this->subir($this->xlsx([['1', 'A', 'M', 'L', null]]))->assertOk()->json('data.token');

        // Alguien da de alta un producto entre la revisión y la importación.
        $otro = $this->producto(['clave' => '1', 'nombre' => 'A']);
        DB::table('crm_productos')->where('id', $otro->id)->update(['updated_at' => now()->addHour()]);

        $r = $this->importar($token)->assertStatus(409);
        $r->assertJsonPath('message', 'El catálogo cambió desde la revisión; revisa de nuevo.')
            ->assertJsonPath('data.resumen.nuevos', 0)
            ->assertJsonPath('data.resumen.actualizados', 1);
        $nuevo = $r->json('data.token');
        $this->assertNotEmpty($nuevo);
        $this->assertDatabaseCount('crm_productos', 1);

        // Con el token nuevo ya se puede importar.
        $this->importar($nuevo)->assertOk()->assertJsonPath('data.actualizados', 1);
    }

    public function test_sin_bandera_da_422(): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['catalogo_por_lineas' => false]);

        $this->subir($this->xlsx([['1', 'A', 'M', 'L', null]]))->assertStatus(422)
            ->assertJsonPath('message', 'Esta empresa no usa el catálogo por líneas.');
        $this->importar('x')->assertStatus(422)
            ->assertJsonPath('message', 'Esta empresa no usa el catálogo por líneas.');
    }

    public function test_sin_permiso_crear_da_403(): void
    {
        $this->usuarioConPermisos(['ver']);

        $this->subir($this->xlsx([['1', 'A', 'M', 'L', null]]))->assertForbidden();
        $this->importar('x')->assertForbidden();
    }

    public function test_actualizar_exige_permiso_editar(): void
    {
        $this->producto(['clave' => '1', 'nombre' => 'Viejo']);
        $this->usuarioConPermisos(['crear']);

        $r = $this->subir($this->xlsx([['1', 'Nuevo', 'M', 'L', null]]))->assertOk();
        $this->importar($r->json('data.token'))->assertForbidden();
        $this->assertSame('Viejo', CrmProducto::first()->nombre);
        $this->assertDatabaseCount('crm_marcas', 0);
    }

    public function test_importa_muchas_filas_en_lotes(): void
    {
        $filas = [];
        for ($i = 1; $i <= 1200; $i++) {
            $filas[] = [(string) (100000 + $i), 'Producto '.$i, 'Marca '.($i % 7), 'Linea '.($i % 3), null];
        }
        $r = $this->subir($this->xlsx($filas))->assertOk()->assertJsonPath('data.resumen.nuevos', 1200);

        $this->importar($r->json('data.token'))->assertOk()->assertJsonPath('data.nuevos', 1200);

        $this->assertDatabaseCount('crm_productos', 1200);
        $this->assertDatabaseCount('crm_marcas', 7);
    }
}
