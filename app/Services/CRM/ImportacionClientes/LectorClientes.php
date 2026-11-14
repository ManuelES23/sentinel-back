<?php

namespace App\Services\CRM\ImportacionClientes;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;
use Throwable;

/**
 * Lee el Excel de clientes (Clave, Razón Social, RFC, E-Mail, Vendedor). Solo lee:
 * todo sale como texto recortado (claves numéricas incluidas, tal como se ven en
 * Excel) y la decisión de qué hacer con cada renglón es de PlanImportacionClientes.
 */
class LectorClientes
{
    public const MAX_FILAS = 5000;

    /** Campo => alias de encabezado ya normalizados (minúsculas, sin acentos ni signos). */
    private const COLUMNAS = [
        'clave' => ['clave', 'clave cliente', 'cve', 'codigo', 'numero de cliente'],
        'nombre' => ['razon social', 'nombre', 'cliente', 'nombre del cliente'],
        'rfc' => ['rfc'],
        'correos' => ['e mail', 'email', 'correo', 'correos', 'correo electronico'],
        'vendedor' => ['vendedor', 'agente', 'vendedor asignado'],
    ];

    /**
     * @return list<array{fila: int, clave: string, nombre: string, rfc: string, correos: string, vendedor: string}>
     *
     * @throws RuntimeException con un mensaje para el usuario si el archivo no sirve.
     */
    public function leer(string $ruta): array
    {
        try {
            $lector = IOFactory::createReader('Xlsx');
            $info = $lector->listWorksheetInfo($ruta)[0] ?? null;
        } catch (Throwable) {
            throw new RuntimeException('No se pudo leer el archivo; súbelo como Excel (.xlsx).');
        }

        if ($info === null) {
            throw new RuntimeException('El archivo no tiene hojas.');
        }
        if (($info['totalRows'] ?? 0) > self::MAX_FILAS + 1) {
            throw new RuntimeException('El archivo tiene más de '.number_format(self::MAX_FILAS).' filas.');
        }

        $lector->setReadEmptyCells(false);
        $lector->setReadFilter(new class implements IReadFilter
        {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return strlen($columnAddress) === 1; // A..Z: sobra para las 5 columnas y algunas extra
            }
        });
        $hoja = $lector->load($ruta)->getSheet(0);

        $columnas = null;
        $filas = [];
        foreach ($hoja->getRowIterator() as $fila) {
            $valores = [];
            foreach ($fila->getCellIterator() as $celda) {
                $valores[$celda->getColumn()] = trim((string) $celda->getFormattedValue());
            }

            if ($columnas === null) {
                $columnas = $this->columnas($valores);
                continue;
            }

            $renglon = ['fila' => $fila->getRowIndex()];
            foreach (array_keys(self::COLUMNAS) as $campo) {
                $renglon[$campo] = isset($columnas[$campo]) ? ($valores[$columnas[$campo]] ?? '') : '';
            }
            if ($renglon['clave'] === '' && $renglon['nombre'] === '' && $renglon['rfc'] === '' && $renglon['correos'] === '') {
                continue;
            }
            $filas[] = $renglon;
        }

        if ($columnas === null || ! isset($columnas['clave'], $columnas['nombre'])) {
            throw new RuntimeException('El archivo no tiene las columnas Clave y Razón Social.');
        }

        return $filas;
    }

    /** @return array<string, string> campo => letra de columna */
    private function columnas(array $encabezados): array
    {
        $columnas = [];
        foreach ($encabezados as $letra => $texto) {
            $normal = trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texto))));
            foreach (self::COLUMNAS as $campo => $alias) {
                if (! isset($columnas[$campo]) && in_array($normal, $alias, true)) {
                    $columnas[$campo] = $letra;
                }
            }
        }

        return $columnas;
    }
}
