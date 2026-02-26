# Gestión Temporal de Productores, Zonas y Lotes

## Estructura

Las tablas pivot conectan **temporadas** con **productores**, **zonas de cultivo** y **lotes**:

```
temporada_productor
├── temporada_id
├── productor_id
├── notas
└── is_active

temporada_zona_cultivo
├── temporada_id
├── zona_cultivo_id
├── superficie_asignada
├── notas
└── is_active

temporada_lote
├── temporada_id
├── lote_id
├── cultivo_id (qué se sembró)
├── superficie_sembrada
├── fecha_siembra
├── fecha_cosecha_estimada
├── notas
└── is_active
```

## Ejemplos de Uso

### 1. Asignar productores a una temporada

```php
$temporada = Temporada::find(1);

// Asignar productor individual
$temporada->asignarProductor(5, 'Contrato renovado 2024');

// Asignar múltiples
$temporada->productores()->attach([
    3 => ['notas' => 'Primer año trabajando', 'is_active' => true],
    7 => ['notas' => null, 'is_active' => true],
]);
```

### 2. Asignar zonas de cultivo

```php
// Una zona
$temporada->asignarZonaCultivo(10, 15.5, 'Zona norte asignada');

// Múltiples zonas
$temporada->zonasCultivo()->attach([
    10 => ['superficie_asignada' => 15.5, 'is_active' => true],
    11 => ['superficie_asignada' => 8.2, 'is_active' => true],
]);
```

### 3. Asignar lotes con cultivos

```php
// Asignar lote con cultivo específico
$temporada->asignarLote(
    loteId: 23,
    cultivoId: 3, // Mango
    superficieSembrada: 5.2,
    fechaSiembra: '2024-03-15',
    notas: 'Siembra temprana'
);

// Múltiples lotes
$temporada->lotes()->attach([
    23 => [
        'cultivo_id' => 3,
        'superficie_sembrada' => 5.2,
        'fecha_siembra' => '2024-03-15',
        'is_active' => true
    ],
    24 => [
        'cultivo_id' => 5, // Aguacate
        'superficie_sembrada' => 3.8,
        'fecha_siembra' => '2024-03-20',
        'is_active' => true
    ],
]);
```

### 4. Consultar productores de una temporada

```php
$temporada = Temporada::find(1);

// Todos los productores (activos e inactivos)
$productores = $temporada->productores;

// Solo activos
$activos = $temporada->productoresActivos()->get();

// Con datos del pivot
foreach ($activos as $productor) {
    echo $productor->nombre;
    echo $productor->pivot->notas;
    echo $productor->pivot->is_active;
}
```

### 5. Ver qué temporadas trabajó un productor

```php
$productor = Productor::find(5);

// Todas las temporadas
$temporadas = $productor->temporadas;

// Verificar si está activo en temporada específica
if ($productor->estaActivoEnTemporada(1)) {
    echo "Trabaja en temporada 2024";
}
```

### 6. Consultar lotes y cultivos por temporada

```php
$temporada = Temporada::find(1);

// Lotes activos con información del cultivo sembrado
$lotes = $temporada->lotesActivos()
    ->with(['zonaCultivo.productor'])
    ->get();

foreach ($lotes as $lote) {
    $cultivoId = $lote->pivot->cultivo_id;
    $cultivo = Cultivo::find($cultivoId);

    echo "{$lote->nombre}: {$cultivo->nombre}";
    echo "Superficie: {$lote->pivot->superficie_sembrada} ha";
    echo "Fecha siembra: {$lote->pivot->fecha_siembra}";
}
```

### 7. Obtener resumen de temporada

```php
$temporada = Temporada::find(1);
$resumen = $temporada->resumen();

/*
[
    'productores_activos' => 12,
    'zonas_activas' => 45,
    'lotes_activos' => 123,
    'superficie_total_sembrada' => 456.78
]
*/
```

### 8. Desactivar un productor en temporada (sin eliminarlo)

```php
$temporada = Temporada::find(1);

// Cambiar estado
$temporada->productores()->updateExistingPivot(5, [
    'is_active' => false,
    'notas' => 'Suspendido por incumplimiento'
]);
```

## Flujo Completo de Configuración

```php
// 1. Crear temporada
$temporada = Temporada::create([
    'cultivo_id' => 3,
    'nombre' => 'Temporada Mango 2024',
    'año_inicio' => 2024,
    'fecha_inicio' => '2024-03-01',
    'fecha_fin' => '2024-08-31',
    'estado' => 'programada',
]);

// 2. Asignar productores
$temporada->productores()->attach([
    1 => ['is_active' => true], // SplendidFarms (interno)
    5 => ['is_active' => true, 'notas' => 'Proveedor principal'],
    7 => ['is_active' => true],
]);

// 3. Asignar zonas de productores
$temporada->zonasCultivo()->attach([
    10 => ['superficie_asignada' => 20.5],
    11 => ['superficie_asignada' => 15.0],
    12 => ['superficie_asignada' => 8.3],
]);

// 4. Asignar lotes específicos
$temporada->lotes()->attach([
    23 => ['cultivo_id' => 3, 'superficie_sembrada' => 5.2, 'fecha_siembra' => '2024-03-15'],
    24 => ['cultivo_id' => 3, 'superficie_sembrada' => 3.8, 'fecha_siembra' => '2024-03-20'],
    25 => ['cultivo_id' => 3, 'superficie_sembrada' => 7.1, 'fecha_siembra' => '2024-03-18'],
]);

// 5. Consultar configuración
$config = [
    'temporada' => $temporada->nombre,
    'productores' => $temporada->productoresActivos()->count(),
    'superficie_total' => $temporada->lotesActivos()->sum('temporada_lote.superficie_sembrada'),
];
```

## Reportes Útiles

### Productores que trabajan año tras año

```php
$productor = Productor::find(5);
$historial = $productor->temporadas()
    ->orderBy('año_inicio', 'desc')
    ->get(['nombre', 'año_inicio', 'año_fin']);
```

### Lotes más productivos (usados en más temporadas)

```php
$lotesProductivos = Lote::withCount('temporadas')
    ->having('temporadas_count', '>', 3)
    ->orderBy('temporadas_count', 'desc')
    ->get();
```

### Superficie total por productor en una temporada

```php
$temporada = Temporada::find(1);
$superficie = $temporada->lotes()
    ->join('zonas_cultivo', 'lotes.zona_cultivo_id', '=', 'zonas_cultivo.id')
    ->where('zonas_cultivo.productor_id', 5)
    ->sum('temporada_lote.superficie_sembrada');
```
