# API Endpoints - Gestión Temporal de Temporadas

## Base URL

```
/api/splendidfarms/administration/agricola/temporadas
```

## Autenticación

Todas las rutas requieren autenticación con Bearer Token (Sanctum).

---

## Resumen de Temporada

### GET `/temporadas/{id}/resumen`

Obtiene estadísticas de la temporada.

**Response 200:**

```json
{
    "productores_activos": 12,
    "zonas_activas": 45,
    "lotes_activos": 123,
    "superficie_total_sembrada": 456.78
}
```

---

## Productores

### GET `/temporadas/{id}/productores`

Lista todos los productores asignados a la temporada.

**Response 200:**

```json
[
    {
        "id": 5,
        "nombre": "Juan Pérez",
        "tipo": "externo",
        "ubicacion": "Colima",
        "telefono": "312-123-4567",
        "email": "juan@example.com",
        "notas": "Contrato renovado 2024",
        "is_active": true,
        "created_at": "2024-03-01T10:00:00Z"
    }
]
```

### POST `/temporadas/{id}/productores`

Asigna un productor a la temporada.

**Request Body:**

```json
{
    "productor_id": 5,
    "notas": "Contrato renovado 2024"
}
```

**Response 201:**

```json
{
    "message": "Productor asignado exitosamente"
}
```

**Errores:**

-   `400` - Productor ya asignado
-   `422` - Validación fallida

### DELETE `/temporadas/{id}/productores/{productorId}`

Desasigna un productor de la temporada.

**Response 200:**

```json
{
    "message": "Productor desasignado exitosamente"
}
```

### PATCH `/temporadas/{id}/productores/{productorId}`

Activa/desactiva un productor en la temporada.

**Request Body:**

```json
{
    "is_active": false,
    "notas": "Suspendido por incumplimiento"
}
```

**Response 200:**

```json
{
    "message": "Estado actualizado exitosamente"
}
```

---

## Zonas de Cultivo

### GET `/temporadas/{id}/zonas-cultivo`

Lista todas las zonas de cultivo asignadas a la temporada.

**Response 200:**

```json
[
    {
        "id": 10,
        "nombre": "Zona Norte A",
        "superficie_total": 25.5,
        "productor": {
            "id": 5,
            "nombre": "Juan Pérez"
        },
        "superficie_asignada": 20.0,
        "notas": "Zona prioritaria",
        "is_active": true,
        "created_at": "2024-03-01T10:00:00Z"
    }
]
```

### POST `/temporadas/{id}/zonas-cultivo`

Asigna una zona de cultivo a la temporada.

**Request Body:**

```json
{
    "zona_cultivo_id": 10,
    "superficie_asignada": 20.5,
    "notas": "Zona norte asignada"
}
```

**Validaciones:**

-   `superficie_asignada` debe ser ≤ `superficie_total` de la zona

**Response 201:**

```json
{
    "message": "Zona de cultivo asignada exitosamente"
}
```

**Errores:**

-   `400` - Zona ya asignada o superficie excede el total
-   `422` - Validación fallida

### DELETE `/temporadas/{id}/zonas-cultivo/{zonaId}`

Desasigna una zona de cultivo de la temporada.

**Response 200:**

```json
{
    "message": "Zona de cultivo desasignada exitosamente"
}
```

---

## Lotes

### GET `/temporadas/{id}/lotes`

Lista todos los lotes asignados a la temporada.

**Response 200:**

```json
[
    {
        "id": 23,
        "nombre": "Lote A-1",
        "superficie_total": 5.5,
        "zona_cultivo": {
            "id": 10,
            "nombre": "Zona Norte A",
            "productor": {
                "id": 5,
                "nombre": "Juan Pérez"
            }
        },
        "cultivo": {
            "id": 3,
            "nombre": "Mango"
        },
        "superficie_sembrada": 5.2,
        "fecha_siembra": "2024-03-15",
        "fecha_cosecha_estimada": "2024-08-15",
        "notas": "Siembra temprana",
        "is_active": true,
        "created_at": "2024-03-01T10:00:00Z"
    }
]
```

### POST `/temporadas/{id}/lotes`

Asigna un lote a la temporada con un cultivo específico.

**Request Body:**

```json
{
    "lote_id": 23,
    "cultivo_id": 3,
    "superficie_sembrada": 5.2,
    "fecha_siembra": "2024-03-15",
    "fecha_cosecha_estimada": "2024-08-15",
    "notas": "Siembra temprana"
}
```

**Validaciones:**

-   `superficie_sembrada` debe ser ≤ `superficie_total` del lote
-   `fecha_cosecha_estimada` debe ser ≥ `fecha_siembra`

**Response 201:**

```json
{
    "message": "Lote asignado exitosamente"
}
```

**Errores:**

-   `400` - Lote ya asignado o superficie excede el total
-   `422` - Validación fallida

### DELETE `/temporadas/{id}/lotes/{loteId}`

Desasigna un lote de la temporada.

**Response 200:**

```json
{
    "message": "Lote desasignado exitosamente"
}
```

---

## Ejemplos de Uso con Fetch

### Asignar productor

```javascript
const response = await fetchAPI(
    `/splendidfarms/administration/agricola/temporadas/${temporadaId}/productores`,
    {
        method: "POST",
        body: JSON.stringify({
            productorId: 5,
            notas: "Contrato renovado",
        }),
    }
);
```

### Asignar lote con cultivo

```javascript
const response = await fetchAPI(
    `/splendidfarms/administration/agricola/temporadas/${temporadaId}/lotes`,
    {
        method: "POST",
        body: JSON.stringify({
            loteId: 23,
            cultivoId: 3,
            superficieSembrada: 5.2,
            fechaSiembra: "2024-03-15",
            fechaCosechaEstimada: "2024-08-15",
            notas: "Siembra temprana",
        }),
    }
);
```

### Obtener resumen

```javascript
const resumen = await fetchAPI(
    `/splendidfarms/administration/agricola/temporadas/${temporadaId}/resumen`
);

console.log(`Productores activos: ${resumen.productoresActivos}`);
console.log(`Superficie total: ${resumen.superficieTotalSembrada} ha`);
```

---

## Notas Importantes

1. **Conversión automática de casos**: El cliente API convierte automáticamente camelCase ↔ snake_case
2. **Headers de contexto**: Los requests incluyen automáticamente headers de empresa/aplicación/módulo
3. **Validación de superficies**: El backend verifica que las superficies asignadas no excedan las totales
4. **Relaciones temporales**: Los catálogos permanentes (productores/zonas/lotes) se mantienen, solo cambian sus asignaciones por temporada
5. **Estado activo/inactivo**: Los registros pueden desactivarse sin eliminarlos usando `PATCH`
