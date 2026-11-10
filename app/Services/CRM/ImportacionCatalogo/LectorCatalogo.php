<?php

namespace App\Services\CRM\ImportacionCatalogo;

use App\Support\CRM\NombreCatalogo;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Lee el Excel del catálogo de productos (encabezados en la fila 1). Solo lee: no toca la BD.
 * Todo valor sale como el texto que muestra la celda (una clave numérica 10240 queda "10240").
 */
class LectorCatalogo
{
    public const MAX_FILAS = 10000;

    /** Campo => encabezados aceptados (ya normalizados con NombreCatalogo::clave). */
    private const COLUMNAS = [
        'clave' => ['clave'],
        'producto' => ['producto'],
        'marca' => ['marca'],
        'linea' => ['linea'],
        'sublinea' => ['sublinea'],
        'unidad' => ['unidad'],
    ];

    /** Texto del Excel (normalizado) => código del enum de unidades. */
    private const UNIDADES = [
        'pza' => 'pieza', 'pz' => 'pieza', 'pieza' => 'pieza', 'piezas' => 'pieza',
        'lt' => 'litro', 'lts' => 'litro', 'l' => 'litro', 'litro' => 'litro', 'litros' => 'litro',
        'kg' => 'kg', 'kgs' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg',
    ];

    /**
     * @return array{filas: list<array{fila:int,clave:string,producto:string,marca:string,linea:string,sublinea:?string,unidad:?string}>, tieneUnidad: bool, problemas: list<array{fila:int,motivo:string}>, clavesConProblema: list<string>}
     *         Las filas con problema no van en `filas`; `clavesConProblema` son las claves (normalizadas)
     *         que sí traían esas filas, para no tratarlas como «faltantes».
     */
    public function leer(string $ruta): array
    {
        try {
            $lector = IOFactory::createReader('Xlsx');
            $lector->setReadDataOnly(false);
            $hoja = $lector->load($ruta)->getSheet(0);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo leer el archivo; verifica que sea un Excel (.xlsx).', 0, $e);
        }

        // Solo las celdas que existen, agrupadas por fila.
        $encabezados = [];
        $porFila = [];
        foreach ($hoja->getCoordinates() as $coordenada) {
            [$col, $fila] = Coordinate::coordinateFromString($coordenada);
            $fila = (int) $fila;
            $texto = NombreCatalogo::limpio($hoja->getCell($coordenada)->getFormattedValue());
            if ($fila === 1) {
                $encabezados[Coordinate::columnIndexFromString($col)] = NombreCatalogo::clave($texto);
            } elseif ($texto !== '') {
                $porFila[$fila][Coordinate::columnIndexFromString($col)] = $texto;
            }
        }

        $columnas = [];
        foreach (self::COLUMNAS as $campo => $alias) {
            foreach ($encabezados as $indice => $encabezado) {
                if (in_array($encabezado, $alias, true) && ! in_array($indice, $columnas, true) && ! isset($columnas[$campo])) {
                    $columnas[$campo] = $indice;
                }
            }
        }
        if (! isset($columnas['clave'], $columnas['producto'])) {
            throw new RuntimeException('El archivo no tiene las columnas CLAVE y PRODUCTO.');
        }

        ksort($porFila);
        $filas = [];
        $problemas = [];
        $clavesConProblema = [];
        $vistas = 0;
        foreach ($porFila as $numero => $celdas) {
            $dato = [];
            foreach ($columnas as $campo => $indice) {
                $dato[$campo] = $celdas[$indice] ?? '';
            }
            if (implode('', $dato) === '') {
                continue; // fila vacía (o solo con columnas ajenas)
            }
            if (++$vistas > self::MAX_FILAS) {
                throw new RuntimeException('El archivo tiene más de '.self::MAX_FILAS.' filas.');
            }

            $unidad = null;
            $motivo = null;
            if (($dato['unidad'] ?? '') !== '') {
                $unidad = self::UNIDADES[NombreCatalogo::clave($dato['unidad'])] ?? null;
                if ($unidad === null) {
                    $motivo = 'Unidad no reconocida: '.$dato['unidad'];
                }
            }
            $motivo = match (true) {
                $dato['clave'] === '' => 'Falta la clave',
                $dato['producto'] === '' => 'Falta el producto',
                ! isset($columnas['marca']) || $dato['marca'] === '' => 'Falta la marca',
                ! isset($columnas['linea']) || $dato['linea'] === '' => 'Falta la línea',
                default => $motivo,
            };
            $motivo ??= $this->largo($dato);

            if ($motivo !== null) {
                $problemas[] = ['fila' => $numero, 'motivo' => $motivo];
                if ($dato['clave'] !== '') {
                    $clavesConProblema[] = NombreCatalogo::clave($dato['clave']);
                }

                continue;
            }

            $filas[] = [
                'fila' => $numero,
                'clave' => $dato['clave'],
                'producto' => $dato['producto'],
                'marca' => $dato['marca'],
                'linea' => $dato['linea'],
                'sublinea' => ($dato['sublinea'] ?? '') === '' ? null : $dato['sublinea'],
                'unidad' => $unidad,
            ];
        }

        return [
            'filas' => $filas,
            'tieneUnidad' => isset($columnas['unidad']),
            'problemas' => $problemas,
            'clavesConProblema' => array_values(array_unique($clavesConProblema)),
        ];
    }

    /** Textos que no caben en las columnas de la BD: se reportan como problema en vez de fallar al importar. */
    private function largo(array $dato): ?string
    {
        if (mb_strlen($dato['clave']) > 60) {
            return 'La clave tiene más de 60 caracteres';
        }
        if (mb_strlen($dato['producto']) > 255) {
            return 'El producto tiene más de 255 caracteres';
        }
        foreach (['marca' => 'La marca', 'linea' => 'La línea', 'sublinea' => 'La sublínea'] as $campo => $titulo) {
            if (mb_strlen($dato[$campo] ?? '') > 150) {
                return $titulo.' tiene más de 150 caracteres';
            }
        }

        return null;
    }
}
