# Sistema de Gestión Temporal - Guía de Implementación

## ✅ Componentes Implementados

### Backend

#### Migraciones (Ejecutadas ✓)

-   ✅ `2026_01_14_175421_create_temporada_productor_table.php`
-   ✅ `2026_01_14_175505_create_temporada_zona_cultivo_table.php`
-   ✅ `2026_01_14_175507_create_temporada_lote_table.php`

#### Modelos Actualizados

-   ✅ `Temporada.php` - Relaciones y métodos helper
-   ✅ `Productor.php` - Relación con temporadas
-   ✅ `ZonaCultivo.php` - Relación con temporadas
-   ✅ `Lote.php` - Relación con temporadas

#### Controller

-   ✅ `TemporadaController.php` - 14 nuevos endpoints para gestión temporal

#### Rutas

-   ✅ `routes/api.php` - Rutas para gestión de productores/zonas/lotes en temporadas

#### Seeders

-   ✅ `TemporadaConfiguracionSeeder.php` - Ejemplo de configuración temporal

### Frontend

#### Hooks

-   ✅ `useTemporadas.js` - 9 nuevos métodos para gestión temporal

#### Componentes (Ejemplo)

-   ✅ `TemporadaConfiguracion.jsx` - Componente de ejemplo

### Documentación

-   ✅ `GESTION_TEMPORAL.md` - Ejemplos de uso del modelo
-   ✅ `API_GESTION_TEMPORAL.md` - Documentación de endpoints

---

## 🚀 Cómo Usar

### 1. Ejecutar Migraciones (Ya hecho)

```bash
php artisan migrate
```

### 2. Poblar con Datos de Ejemplo

```bash
php artisan db:seed --class=TemporadaConfiguracionSeeder
```

Esto creará 3 temporadas de ejemplo:

-   2024 (cerrada) - 3 productores, 5 lotes
-   2025 (cerrada) - 4 productores, 7 lotes
-   2026 (programada) - 2 productores, 3 zonas

### 3. Probar Endpoints

#### Obtener resumen de temporada

```bash
curl -X GET "http://localhost:8000/api/splendidfarms/administration/agricola/temporadas/1/resumen" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

#### Asignar productor

```bash
curl -X POST "http://localhost:8000/api/splendidfarms/administration/agricola/temporadas/1/productores" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "productor_id": 5,
    "notas": "Contrato renovado"
  }'
```

#### Asignar lote con cultivo

```bash
curl -X POST "http://localhost:8000/api/splendidfarms/administration/agricola/temporadas/1/lotes" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "lote_id": 23,
    "cultivo_id": 3,
    "superficie_sembrada": 5.2,
    "fecha_siembra": "2024-03-15",
    "fecha_cosecha_estimada": "2024-08-15",
    "notas": "Siembra temprana"
  }'
```

### 4. Usar en el Frontend

```javascript
import { useTemporadas } from "../hooks";

function TemporadaDetalle() {
  const {
    obtenerResumen,
    asignarProductor,
    obtenerLotes
  } = useTemporadas();

  // Obtener resumen
  const resumen = await obtenerResumen(temporadaId);
  console.log(`Productores: ${resumen.productoresActivos}`);

  // Asignar productor
  await asignarProductor(temporadaId, {
    productorId: 5,
    notas: "Contrato renovado"
  });

  // Ver lotes asignados
  const lotes = await obtenerLotes(temporadaId);
}
```

---

## 📋 Endpoints Disponibles

### Resumen

-   `GET /temporadas/{id}/resumen` - Estadísticas de temporada

### Productores

-   `GET /temporadas/{id}/productores` - Listar asignados
-   `POST /temporadas/{id}/productores` - Asignar
-   `DELETE /temporadas/{id}/productores/{productorId}` - Desasignar
-   `PATCH /temporadas/{id}/productores/{productorId}` - Activar/desactivar

### Zonas de Cultivo

-   `GET /temporadas/{id}/zonas-cultivo` - Listar asignadas
-   `POST /temporadas/{id}/zonas-cultivo` - Asignar
-   `DELETE /temporadas/{id}/zonas-cultivo/{zonaId}` - Desasignar

### Lotes

-   `GET /temporadas/{id}/lotes` - Listar asignados
-   `POST /temporadas/{id}/lotes` - Asignar (con cultivo)
-   `DELETE /temporadas/{id}/lotes/{loteId}` - Desasignar

---

## 💡 Casos de Uso

### Configurar Nueva Temporada

```php
// Backend
$temporada = Temporada::create([...]);

// Asignar productores que trabajarán
$temporada->asignarProductor(1, 'SplendidFarms - interno');
$temporada->asignarProductor(5, 'Proveedor principal');

// Asignar zonas específicas
$temporada->asignarZonaCultivo(10, 15.5, 'Zona prioritaria');

// Asignar lotes con cultivos
$temporada->asignarLote(23, cultivoId: 3, superficieSembrada: 5.2,
  fechaSiembra: '2024-03-15', notas: 'Mango variedad Kent');
```

### Consultar Historial de un Productor

```php
$productor = Productor::find(5);
$temporadas = $productor->temporadas()
  ->orderBy('año_inicio', 'desc')
  ->get();

foreach ($temporadas as $temp) {
  echo "Temporada {$temp->nombre} - {$temp->pivot->notas}";
}
```

### Reportes

```php
// Superficie total por productor en temporada
$temporada = Temporada::find(1);
$superficie = $temporada->lotes()
  ->join('zonas_cultivo', 'lotes.zona_cultivo_id', '=', 'zonas_cultivo.id')
  ->where('zonas_cultivo.productor_id', 5)
  ->sum('temporada_lote.superficie_sembrada');

echo "Productor 5 sembró {$superficie} hectáreas";
```

---

## 🔄 Siguiente Paso: UI Frontend

Para completar la implementación, crea los componentes en el frontend:

1. **TemporadaConfiguracion.jsx** - Vista principal de configuración (ejemplo creado)
2. **ProductorSelectionModal.jsx** - Modal para seleccionar productor
3. **ZonaSelectionModal.jsx** - Modal para asignar zona con superficie
4. **LoteSelectionModal.jsx** - Modal para asignar lote con cultivo

### Estructura Sugerida

```
src/views/splendidfarms/administration/agricola/temporadas/
├── TemporadaView.jsx (existente)
├── TemporadaConfiguracion.jsx (nuevo)
└── components/
    ├── ProductorSelectionModal.jsx
    ├── ZonaSelectionModal.jsx
    └── LoteSelectionModal.jsx
```

---

## ✨ Ventajas del Sistema

1. **Catálogos permanentes**: Productores/Zonas/Lotes persisten, solo cambian asignaciones
2. **Flexibilidad**: Algunos productores se repiten, otros no
3. **Trazabilidad**: Historial completo de qué se sembró, cuándo y dónde
4. **Reportes**: Estadísticas por temporada, productor, cultivo
5. **Validación**: Superficies asignadas no pueden exceder totales

---

## 📚 Documentos de Referencia

-   `documentacion/GESTION_TEMPORAL.md` - Ejemplos de código
-   `documentacion/API_GESTION_TEMPORAL.md` - Documentación de API
-   `database/seeders/TemporadaConfiguracionSeeder.php` - Datos de ejemplo
