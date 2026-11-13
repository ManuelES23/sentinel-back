<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmVendedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class ImportacionClientesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const REVISAR = '/api/crm/clientes/importar/revisar';
    private const IMPORTAR = '/api/crm/clientes/importar';
    private const ENCABEZADOS = ['Clave', 'Razón Social', 'RFC', 'E-Mail', 'Vendedor'];

    private CrmVendedor $jose;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();

        $this->jose = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => null,
            'nombre' => 'José Ávalos',
            'activo' => true,
        ]);
    }

    /** Arma un .xlsx con encabezados y filas; los números se escriben como números. */
    private function archivo(array $filas, ?array $encabezados = null): UploadedFile
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->fromArray($encabezados ?? self::ENCABEZADOS, null, 'A1');
        $hoja->fromArray($filas, null, 'A2', true);
        $ruta = tempnam(sys_get_temp_dir(), 'cli').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'CLIENTES 2026.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function filasBase(): array
    {
        $int = 'jflores@canesagro.com; gpaz@canesagro.com; kvalenzuela@canesagro.com';

        return [
            [38, 'ZAPAGRO', 'ZAP0609112AA', "{$int}; zapagro@hotmail.com; otro@zapagro.mx", '002 Jose Alberto Avalos Lopez'],
            [348, 'YUNISE LIZETH SANCHEZ AYALA', 'SAAY020826QP0', 'yunise@gmail.com', '002 Jose Alberto Avalos Lopez'],
            [184, 'VITANOVA FRESH PRODUCE', 'VFP0909046Z7', "{$int}; compras@vitanovafresh.net", '0'],
            [36, 'TEO DE JESUS HIGUERA MATA', 'HIMT9109278JA', "{$int}", '1'],
            [81, 'SPLENDID BY PORVENIR, LLC', 'XEXX010101000', "{$int}; avalenzuela@canesagro.com.mx", '8'],
            [500, 'CLIENTE STAFF', 'CST010101AAA', 'staff@cliente.com', '006 Vendedor Staff'],
            [202, 'XICOTENCATL MURRIETA EZRRE', 'MUEX780930D42', 'adm@hotmail.com', '009 GERMAN RAMIREZ BOON'],
        ];
    }

    private function revisar(UploadedFile $archivo)
    {
        return $this->withHeaders($this->crmHeaders())->post(self::REVISAR, ['archivo' => $archivo], ['Accept' => 'application/json']);
    }

    private function importar(string $token, array $vendedores = [])
    {
        $pares = [];
        foreach ($vendedores as $texto => $id) {
            $pares[] = ['texto' => $texto, 'vendedor_id' => $id];
        }

        return $this->withHeaders($this->crmHeaders())->postJson(self::IMPORTAR, ['token' => $token, 'vendedores' => $pares]);
    }

    public function test_revisar_resume_y_no_escribe_clientes(): void
    {
        $res = $this->revisar($this->archivo($this->filasBase()));

        $res->assertOk()
            ->assertJsonPath('data.resumen.nuevos', 7)
            ->assertJsonPath('data.resumen.actualizados', 0)
            ->assertJsonPath('data.resumen.con_problema', 0)
            // 3 sin nombre (0, 1, 8); Staff y German sin sugerencia; los 2 de José sugeridos
            ->assertJsonPath('data.resumen.con_vendedor', 2)
            ->assertJsonPath('data.resumen.pendientes', 5);
        $this->assertNotEmpty($res->json('data.token'));
        $this->assertSame(0, CrmCliente::count());

        $vendedores = collect($res->json('data.resumen.vendedores'))->keyBy('texto');
        $this->assertCount(3, $vendedores);
        $this->assertSame($this->jose->id, $vendedores['002 Jose Alberto Avalos Lopez']['sugerido_id']);
        $this->assertSame(2, $vendedores['002 Jose Alberto Avalos Lopez']['clientes']);
        $this->assertNull($vendedores['006 Vendedor Staff']['sugerido_id']);
        $this->assertNull($vendedores['009 GERMAN RAMIREZ BOON']['sugerido_id']);

        $dominios = collect($res->json('data.resumen.dominios'))->keyBy('dominio');
        $this->assertSame(4, $dominios['canesagro.com']['clientes']);
        $this->assertTrue($dominios['canesagro.com']['interno']);
        $this->assertTrue($dominios['canesagro.com.mx']['interno'], 'variante .mx del dominio interno');
        $this->assertFalse($dominios['hotmail.com']['interno'], 'correo público nunca se sugiere como interno');
        $this->assertSame(2, $res->json('data.resumen.sin_correo'));
        $this->assertContains(['id' => $this->jose->id, 'nombre' => 'José Ávalos'], $res->json('data.resumen.vendedores_crm'));
    }

    public function test_importa_con_vendedores_emparejados_y_deja_pendientes(): void
    {
        $token = $this->revisar($this->archivo($this->filasBase()))->json('data.token');

        $res = $this->importar($token, [
            '002 Jose Alberto Avalos Lopez' => $this->jose->id,
            '009 GERMAN RAMIREZ BOON' => $this->vendedor->id,
            '006 Vendedor Staff' => null,
        ]);

        $res->assertOk()->assertJsonPath('data.nuevos', 7)->assertJsonPath('data.pendientes', 4)
            ->assertJsonPath('data.con_vendedor', 3);
        $this->assertSame(7, CrmCliente::where('empresa_id', $this->enterprise->id)->count());

        $zap = CrmCliente::where('clave', '38')->firstOrFail();
        $this->assertSame('ZAPAGRO', $zap->nombre);
        $this->assertSame('ZAP0609112AA', $zap->rfc);
        $this->assertSame('zapagro@hotmail.com', $zap->email);
        $this->assertSame('Otros correos: otro@zapagro.mx', $zap->notas);
        $this->assertSame($this->jose->id, $zap->vendedor_id);
        $this->assertSame('activo', $zap->estatus);

        $this->assertSame($this->vendedor->id, CrmCliente::where('clave', '202')->value('vendedor_id'));
        foreach (['184', '36', '81', '500'] as $clave) {
            $this->assertNull(CrmCliente::where('clave', $clave)->value('vendedor_id'), "clave {$clave}");
        }
        // Solo correos internos: queda sin correo
        $this->assertNull(CrmCliente::where('clave', '36')->value('email'));
    }

    public function test_vendedor_sin_emparejar_queda_pendiente(): void
    {
        $token = $this->revisar($this->archivo($this->filasBase()))->json('data.token');

        $this->importar($token, [])->assertOk();

        $this->assertSame(0, CrmCliente::whereNotNull('vendedor_id')->count());
    }

    public function test_reimportar_actualiza_sin_quitar_vendedor(): void
    {
        $otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Ana Gallegos', 'activo' => true]);
        CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'clave' => '38', 'nombre' => 'Zapagro viejo',
            'rfc' => null, 'email' => 'viejo@zapagro.mx', 'vendedor_id' => $otro->id, 'notas' => 'Nota a mano',
        ]);
        CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'clave' => '348', 'nombre' => 'YUNISE LIZETH SANCHEZ AYALA',
            'rfc' => 'SAAY020826QP0', 'email' => 'yunise@gmail.com', 'vendedor_id' => null,
        ]);

        $rev = $this->revisar($this->archivo(array_slice($this->filasBase(), 0, 3)));
        $rev->assertJsonPath('data.resumen.nuevos', 1)->assertJsonPath('data.resumen.actualizados', 2);

        $this->importar($rev->json('data.token'), ['002 Jose Alberto Avalos Lopez' => $this->jose->id])->assertOk();

        $zap = CrmCliente::where('clave', '38')->firstOrFail();
        $this->assertSame('ZAPAGRO', $zap->nombre);
        $this->assertSame('zapagro@hotmail.com', $zap->email);
        $this->assertSame($otro->id, $zap->vendedor_id, 'no se quita el vendedor asignado a mano');
        $this->assertSame('Nota a mano', $zap->notas, 'las notas no se tocan al actualizar');
        $this->assertSame($this->jose->id, CrmCliente::where('clave', '348')->value('vendedor_id'), 'sin vendedor sí lo toma');
        $this->assertSame(3, CrmCliente::count());
    }

    public function test_liga_cliente_existente_sin_clave_por_rfc_o_nombre(): void
    {
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Zapagro SA', 'rfc' => 'zap0609112aa']);
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Vitanova  Fresh Produce ']);
        // RFC genérico no liga
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro extranjero', 'rfc' => 'XEXX010101000']);

        $rev = $this->revisar($this->archivo($this->filasBase()));
        $rev->assertJsonPath('data.resumen.nuevos', 5)->assertJsonPath('data.resumen.actualizados', 2);

        $this->importar($rev->json('data.token'))->assertOk();

        $this->assertSame(8, CrmCliente::count());
        $this->assertSame('38', CrmCliente::where('rfc', 'ZAP0609112AA')->value('clave'));
        $this->assertSame('184', CrmCliente::where('nombre', 'VITANOVA FRESH PRODUCE')->value('clave'));
        $this->assertNull(CrmCliente::where('nombre', 'Otro extranjero')->value('clave'));
    }

    public function test_no_toca_clientes_de_otra_empresa(): void
    {
        $otra = $this->crearOtraEmpresa();
        CrmCliente::create(['empresa_id' => $otra->id, 'clave' => '38', 'nombre' => 'Ajeno']);

        $rev = $this->revisar($this->archivo($this->filasBase()));
        $rev->assertJsonPath('data.resumen.nuevos', 7);
        $this->importar($rev->json('data.token'))->assertOk();

        $this->assertSame('Ajeno', CrmCliente::where('empresa_id', $otra->id)->value('nombre'));
    }

    public function test_problemas_por_fila(): void
    {
        $filas = [
            [38, 'ZAPAGRO', 'ZAP0609112AA', 'a@b.com', '0'],
            [null, 'SIN CLAVE', '', '', '0'],
            [77, '', '', '', '0'],
            [38, 'ZAPAGRO REPETIDO', '', '', '0'],
            [90, 'RFC LARGO', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', '', '0'],
            [null, null, null, null, null],
        ];

        $res = $this->revisar($this->archivo($filas));

        $res->assertOk()->assertJsonPath('data.resumen.nuevos', 1)->assertJsonPath('data.resumen.con_problema', 4);
        $this->assertSame([
            ['fila' => 3, 'motivo' => 'Falta la clave'],
            ['fila' => 4, 'motivo' => 'Falta la razón social'],
            ['fila' => 5, 'motivo' => 'Clave repetida en el archivo (fila 2)'],
            ['fila' => 6, 'motivo' => 'RFC demasiado largo'],
        ], $res->json('data.resumen.problemas'));
    }

    public function test_encabezados_flexibles_y_claves_numericas_como_texto(): void
    {
        $res = $this->revisar($this->archivo(
            [[12000009, 'Cliente', 'x', 'c@d.com', '0', 'ignorar']],
            ['CLAVE', 'NOMBRE', 'rfc', 'Correo', 'VENDEDOR', 'NOTAS'],
        ));
        $res->assertOk();
        $this->importar($res->json('data.token'))->assertOk();

        $this->assertSame('12000009', CrmCliente::value('clave'));
    }

    public function test_archivo_sin_columnas_obligatorias_da_422(): void
    {
        $this->revisar($this->archivo([[1, 'x']], ['Codigo', 'Otra']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'El archivo no tiene las columnas Clave y Razón Social.');
    }

    public function test_vendedor_de_otra_empresa_da_422(): void
    {
        $otra = $this->crearOtraEmpresa();
        $ajeno = CrmVendedor::create(['empresa_id' => $otra->id, 'nombre' => 'Ajeno', 'activo' => true]);
        $token = $this->revisar($this->archivo($this->filasBase()))->json('data.token');

        $this->importar($token, ['002 Jose Alberto Avalos Lopez' => $ajeno->id])->assertStatus(422);
        $this->assertSame(0, CrmCliente::count());
    }

    public function test_token_de_otro_usuario_o_usado_da_422(): void
    {
        $token = $this->revisar($this->archivo($this->filasBase()))->json('data.token');

        $otro = User::factory()->create();
        \App\Models\UserEnterpriseAccess::create([
            'user_id' => $otro->id, 'enterprise_id' => $this->enterprise->id, 'is_active' => true, 'granted_at' => now(),
        ]);
        $original = $this->actingUser;
        $this->actingUser = $otro;
        $this->otorgarTodosLosPermisosCrm(); // con permisos: el rechazo es por el token
        $this->actingUser = $original;
        Sanctum::actingAs($otro);
        $this->importar($token)->assertStatus(422)
            ->assertJsonPath('message', 'La revisión venció; vuelve a subir el archivo.');

        Sanctum::actingAs($this->actingUser);
        $this->importar($token)->assertOk();
        $this->importar($token)->assertStatus(422);
        $this->assertSame(7, CrmCliente::count());
    }

    public function test_sin_permiso_crear_da_403(): void
    {
        $sinPermisos = User::factory()->create();
        \App\Models\UserEnterpriseAccess::create([
            'user_id' => $sinPermisos->id, 'enterprise_id' => $this->enterprise->id, 'is_active' => true, 'granted_at' => now(),
        ]);
        Sanctum::actingAs($sinPermisos);

        $this->revisar($this->archivo($this->filasBase()))->assertStatus(403);
    }

    public function test_borra_archivos_de_revisiones_viejas(): void
    {
        Storage::disk('local')->put('crm/importaciones-clientes/viejo.xlsx', 'x');
        touch(Storage::disk('local')->path('crm/importaciones-clientes/viejo.xlsx'), now()->subHours(3)->getTimestamp());
        Storage::disk('local')->put('crm/importaciones-clientes/reciente.xlsx', 'x');

        $this->revisar($this->archivo($this->filasBase()))->assertOk();

        Storage::disk('local')->assertMissing('crm/importaciones-clientes/viejo.xlsx');
        Storage::disk('local')->assertExists('crm/importaciones-clientes/reciente.xlsx');
    }

    public function test_importar_borra_el_archivo_revisado(): void
    {
        $token = $this->revisar($this->archivo($this->filasBase()))->json('data.token');
        $this->assertCount(1, Storage::disk('local')->files('crm/importaciones-clientes'));

        $this->importar($token)->assertOk();

        $this->assertCount(0, Storage::disk('local')->files('crm/importaciones-clientes'));
    }

    public function test_dominios_internos_elegidos_y_correos_mal_escritos(): void
    {
        $filas = [
            [1, 'UNO', '', 'jflores@canes agro.com; ventas@uno.mx', '0'],
            [2, 'DOS', '', 'administracion@dos.com:cugallo@canesagro.com; gpaz@canesagro', '0'],
            [3, 'TRES', '', 'compras@canagro.mx; tres@gmail.com', '0'],
        ];
        $token = $this->revisar($this->archivo($filas))->json('data.token');

        $this->withHeaders($this->crmHeaders())->postJson(self::IMPORTAR, [
            'token' => $token, 'vendedores' => [], 'dominios_internos' => ['canesagro.com', 'canagro.mx'],
        ])->assertOk();

        $this->assertSame('ventas@uno.mx', CrmCliente::where('clave', '1')->value('email'));
        $this->assertSame('administracion@dos.com', CrmCliente::where('clave', '2')->value('email'));
        $this->assertSame('tres@gmail.com', CrmCliente::where('clave', '3')->value('email'));
        $this->assertNull(CrmCliente::where('clave', '3')->value('notas'));
    }

    public function test_listado_filtra_pendientes_por_asignar(): void
    {
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Con vendedor', 'vendedor_id' => $this->vendedor->id]);
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Pendiente']);

        $this->withHeaders($this->crmHeaders())->getJson('/api/crm/clientes?vendedor_id=sin')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Pendiente');
    }
}
