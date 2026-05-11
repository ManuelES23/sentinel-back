<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();

$controller = new App\Http\Controllers\Api\SplendidFarms\Administration\TableroProductoresController();
$request = Illuminate\Http\Request::create('/x', 'GET', ['temporada_id' => 16]);
$response = $controller->show($request, 16);
$data = json_decode($response->getContent(), true);
$rows = $data['data']['estado_cuenta_rows'] ?? [];

echo 'rows=' . count($rows) . PHP_EOL;
foreach (array_slice($rows, 0, 12) as $r) {
    echo ($r['fecha'] ?? '') . ' | ' . ($r['convenio_folio'] ?? 'SIN') . ' | tc=' . ($r['tipo_carga'] ?? '-') . ' | precio=' . ($r['precio_unitario'] ?? 0) . ' | subtotal=' . ($r['subtotal'] ?? 0) . PHP_EOL;
}