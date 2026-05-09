<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$cola = App\Models\ProduccionEmpaque::where('numero_pallet', 'COLA-0013')
    ->with([
        'recipe:id,name,code',
        'recipe.items:id,recipe_id,group_key,quantity',
    ])
    ->first();

if ($cola) {
    echo json_encode([
        'id' => $cola->id,
        'numero_pallet' => $cola->numero_pallet,
        'recipe_id' => $cola->recipe_id,
        'recipe' => $cola->recipe ? [
            'id' => $cola->recipe->id,
            'name' => $cola->recipe->name,
            'items' => $cola->recipe->items->toArray(),
        ] : null,
    ], JSON_PRETTY_PRINT);
} else {
    echo "Cola no encontrada\n";
}
