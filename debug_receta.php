<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$cola1 = App\Models\ProduccionEmpaque::find(149);
$cola2 = App\Models\ProduccionEmpaque::where('numero_pallet', 'COLA-0015')->first();

if ($cola1 && $cola2) {
  echo 'COLA-0013 recipe_id: ' . $cola1->recipe_id . PHP_EOL;
  echo 'COLA-0015 recipe_id: ' . $cola2->recipe_id . PHP_EOL;
  echo 'Mismo recipe: ' . ($cola1->recipe_id === $cola2->recipe_id ? 'SÍ' : 'NO') . PHP_EOL;
  echo PHP_EOL;
  
  $cola1->load('recipe.items');
  $cola2->load('recipe.items');
  
  echo 'Items COLA-0013:' . PHP_EOL;
  if ($cola1->recipe && $cola1->recipe->items) {
    foreach ($cola1->recipe->items as $item) {
      echo '  ' . $item->group_key . ' = ' . $item->quantity . PHP_EOL;
    }
  } else {
    echo '  (sin receta o items)' . PHP_EOL;
  }
  echo PHP_EOL;
  echo 'Items COLA-0015:' . PHP_EOL;
  if ($cola2->recipe && $cola2->recipe->items) {
    foreach ($cola2->recipe->items as $item) {
      echo '  ' . $item->group_key . ' = ' . $item->quantity . PHP_EOL;
    }
  } else {
    echo '  (sin receta o items)' . PHP_EOL;
  }
}
