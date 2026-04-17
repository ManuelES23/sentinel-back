<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== VALIDACIÓN ESTRICTA ===\n\n";

// Convenio
$conv = App\Models\ConvenioCompra::where('status', 'activo')->with('precios')->first();
echo "Convenio: id={$conv->id} prod={$conv->productor_id} temp={$conv->temporada_id} cultivo={$conv->cultivo_id} var={$conv->variedad_id}\n";
echo "  Período: {$conv->fecha_inicio} a {$conv->fecha_fin}\n";
foreach ($conv->precios as $p) {
    echo "  Precio: tc={$p->tipo_carga_id} unit={$p->precio_unitario} vigencia={$p->vigencia_inicio} a {$p->vigencia_fin} active={$p->is_active}\n";
}

// Salidas del productor 4 en temporada 2
$salidas = App\Models\SalidaCampoCosecha::where('eliminado', false)
    ->where('productor_id', $conv->productor_id)
    ->where('temporada_id', $conv->temporada_id)
    ->get();

echo "\nSalidas del productor {$conv->productor_id} en temporada {$conv->temporada_id}:\n";
foreach ($salidas as $s) {
    $dentroFechas = ($s->fecha >= $conv->fecha_inicio && $s->fecha <= $conv->fecha_fin) ? 'SI' : 'NO';
    $varMatch = ($s->variedad_id == $conv->variedad_id) ? 'SI' : 'NO';
    echo "  id={$s->id} folio={$s->folio_salida} fecha={$s->fecha} var={$s->variedad_id}(match={$varMatch}) tc={$s->tipo_carga_id} dentro_fechas={$dentroFechas}\n";
}

// Test controller
echo "\n=== TEST INDEX con temporada_id=2 ===\n";
$request = Illuminate\Http\Request::create('/test', 'GET', ['temporada_id' => 2]);
$controller = new App\Http\Controllers\Api\SplendidFarms\Administration\TableroProductoresController();
$response = $controller->index($request);
$data = json_decode($response->getContent(), true);

echo "Productores: " . count($data['data']['productores'] ?? []) . "\n";
$resumen = $data['data']['resumen'] ?? [];
echo "total_salidas: {$resumen['total_salidas']}\n";
echo "monto_bruto: {$resumen['monto_total_bruto']}\n";

foreach ($data['data']['productores'] ?? [] as $p) {
    echo "\nProductor: {$p['productor']['nombre']} {$p['productor']['apellido']}\n";
    foreach ($p['convenios'] as $c) {
        echo "  Conv: {$c['convenio']['folio_convenio']} salidas={$c['total_salidas']}\n";
        foreach ($c['salidas'] as $s) {
            echo "    {$s['folio_salida']} fecha={$s['fecha']} tc={$s['tipo_carga']} cant={$s['cantidad']} precio={$s['precio_unitario']} sub={$s['subtotal']}\n";
        }
    }
}
