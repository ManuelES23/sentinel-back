<?php

namespace App\Services\Planeacion;

use GdImage;
use RuntimeException;

/**
 * Dibuja en PNG la hoja resumen de una semana (ResumenProgramaSemanal::armar)
 * con GD y la fuente DejaVu Sans de `resources/fonts`. Se dibuja al doble de
 * resolución (ESCALA) para que se lea al ampliarla en el celular.
 *
 * La imagen es siempre clara: va adjunta al correo y se reenvía tal cual.
 */
final class ImagenResumenPrograma
{
    private const ESCALA = 2;

    // Medidas en px a escala 1.
    private const MARGEN = 16;

    private const ANCHO_ETIQUETA = 176;

    private const ANCHO_DIA = 62;

    private const ANCHO_TOTAL = 80;

    private const ALTO_BANDA = 66;

    private const ALTO_CIFRAS = 58;

    private const ALTO_GRUPO = 20;

    private const ALTO_DIAS = 32;

    private const ALTO_SECCION = 21;

    private const ALTO_FILA = 22;

    private const COLORES = [
        'blanco' => [255, 255, 255],
        'tinta' => [23, 32, 28],
        'tenue' => [102, 114, 108],
        'cero' => [190, 198, 194],
        'linea' => [217, 225, 220],
        'linea_suave' => [236, 240, 238],
        'banda' => [31, 78, 61],
        'banda_tenue' => [196, 222, 208],
        'seccion' => [232, 240, 235],
        'seccion_tinta' => [42, 74, 60],
        'previa' => [240, 242, 241],
        'previa_seccion' => [226, 231, 228],
        'previa_tinta' => [89, 100, 94],
        // Encabezados marcados: banda de sección verde fuerte, cabecera de días verde claro y líneas gruesas.
        'seccion_fuerte' => [47, 107, 82],
        'seccion_fuerte_previa' => [36, 86, 66],
        'cabecera' => [214, 232, 221],
        'cabecera_previa' => [205, 214, 209],
        'linea_fuerte' => [31, 78, 61],
        'linea_total' => [150, 172, 160],
        'negativo' => [180, 35, 24],
        'positivo' => [26, 115, 67],
    ];

    private GdImage $img;

    /** @var array<string, int> */
    private array $color = [];

    private string $fuente;

    private string $fuenteNegrita;

    public function __construct()
    {
        $this->fuente = resource_path('fonts/DejaVuSans.ttf');
        $this->fuenteNegrita = resource_path('fonts/DejaVuSans-Bold.ttf');
    }

    /** Nombre del archivo: `Programa_WK48_2026-11-23.png`. */
    public function nombreArchivo(array $resumen): string
    {
        return sprintf('Programa_WK%02d_%s.png', $resumen['semana'], $resumen['lunes']);
    }

    /**
     * @param  array  $resumen  Salida de ResumenProgramaSemanal::armar().
     * @param  string  $generado  Texto de la proyección, p. ej. «Proyección al 24 nov 2026, 13:20».
     * @return string Bytes del PNG.
     */
    public function png(array $resumen, string $generado): string
    {
        if (! function_exists('imagettftext') || ! is_file($this->fuente)) {
            throw new RuntimeException('El servidor no puede dibujar la imagen del programa (falta GD con FreeType o la fuente).');
        }

        $columnas = $resumen['columnas'];
        $anchoTabla = self::ANCHO_ETIQUETA + count($columnas) * self::ANCHO_DIA + self::ANCHO_TOTAL;
        $ancho = $anchoTabla + 2 * self::MARGEN;

        $lineasNotas = $resumen['notas'] !== null ? $this->partir($resumen['notas'], 11.5, false, $anchoTabla - 20, 8) : [];
        [$altoPie, $leyenda] = $this->medirPie($resumen, $anchoTabla);
        $alto = self::ALTO_BANDA + self::ALTO_CIFRAS + 8 + self::ALTO_GRUPO + self::ALTO_DIAS
            + $this->altoSecciones($resumen['secciones'])
            + $altoPie
            + ($lineasNotas !== [] ? 30 + count($lineasNotas) * 16 : 0)
            + 12;

        $this->img = imagecreatetruecolor($ancho * self::ESCALA, $alto * self::ESCALA);
        imagealphablending($this->img, true);
        foreach (self::COLORES as $nombre => [$r, $g, $b]) {
            $this->color[$nombre] = imagecolorallocate($this->img, $r, $g, $b);
        }
        $this->rect(0, 0, $ancho, $alto, 'blanco');

        $y = $this->banda($resumen, $ancho, $generado);
        $y = $this->cifras($resumen, $ancho, $y);
        $y += 8;
        $y = $this->tabla($resumen, $y);
        $y = $this->pie($resumen, $y, $anchoTabla, $leyenda);
        if ($lineasNotas !== []) {
            $this->notas($lineasNotas, $y, $anchoTabla);
        }

        ob_start();
        imagepng($this->img, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($this->img);

        return $png;
    }

    // ── Partes ──────────────────────────────────────────

    private function banda(array $r, int $ancho, string $generado): int
    {
        $this->rect(0, 0, $ancho, self::ALTO_BANDA, 'banda');
        $cultivo = $r['cultivo'] !== null ? ' · '.mb_strtoupper($r['cultivo']) : '';
        $this->texto(self::MARGEN, 24, 'PROGRAMA SEMANAL'.$cultivo, 10, 'banda_tenue', true);
        $this->texto(self::MARGEN, 52, 'SEMANA '.$r['semana'], 22, 'blanco', true);
        $this->texto($ancho - self::MARGEN, 30, $r['rango'], 14, 'blanco', true, 'der');
        $this->texto($ancho - self::MARGEN, 50, $generado, 10.5, 'banda_tenue', false, 'der');

        return self::ALTO_BANDA;
    }

    private function cifras(array $r, int $ancho, int $y): int
    {
        $k = $r['kpis'];
        $celdas = [
            ['PRODUCCIÓN', $this->cajas($k['produccion']).' cj', 'tinta'],
            ['NECESIDAD', $this->cajas($k['necesidad']).' cj', 'tinta'],
            ['DISPONIBLE', $this->signo($k['disponible']).' cj', $k['disponible'] < 0 ? 'negativo' : ($k['disponible'] > 0 ? 'positivo' : 'tinta')],
            ['EMBARQUES', $k['full'].' F · '.$k['sencillo'].' S', 'tinta'],
        ];
        $anchoCelda = ($ancho - 2 * self::MARGEN) / 4;
        foreach ($celdas as $i => [$titulo, $valor, $color]) {
            $x = (int) round(self::MARGEN + $i * $anchoCelda);
            if ($i > 0) {
                $this->linea($x - 8, $y + 10, $x - 8, $y + self::ALTO_CIFRAS - 10, 'linea');
            }
            $this->texto($x, $y + 22, $titulo, 9.5, 'tenue', true);
            $this->texto($x, $y + 46, $valor, 18, $color, true);
        }
        $this->linea(0, $y + self::ALTO_CIFRAS, $ancho, $y + self::ALTO_CIFRAS, 'linea');

        return $y + self::ALTO_CIFRAS;
    }

    private function tabla(array $r, int $y): int
    {
        $columnas = $r['columnas'];
        $xDia = fn (int $i) => self::MARGEN + self::ANCHO_ETIQUETA + $i * self::ANCHO_DIA;
        $xTotal = $xDia(count($columnas));
        $previas = array_keys(array_filter($columnas, fn ($c) => $c['previa']));
        $primeraSemana = count($previas);
        $altoCuerpo = self::ALTO_GRUPO + self::ALTO_DIAS + $this->altoSecciones($r['secciones']);

        $yInicio = $y;
        $xFin = $xTotal + self::ANCHO_TOTAL;

        // Fondo gris de las columnas de la semana anterior, de la cabecera al final de la tabla.
        foreach ($previas as $i) {
            $this->rect($xDia($i), $y, self::ANCHO_DIA, $altoCuerpo, 'previa');
        }

        // Cabecera de grupos y de días: fondo verde claro (gris verdoso en la semana anterior).
        $altoCabecera = self::ALTO_GRUPO + self::ALTO_DIAS;
        $this->rect(self::MARGEN, $y, self::ANCHO_ETIQUETA, $altoCabecera, 'cabecera');
        foreach ($columnas as $i => $c) {
            $this->rect($xDia($i), $y, self::ANCHO_DIA, $altoCabecera, $c['previa'] ? 'cabecera_previa' : 'cabecera');
        }
        $this->rect($xTotal, $y, self::ANCHO_TOTAL, $altoCabecera, 'cabecera');
        $this->linea(self::MARGEN, $y, $xFin, $y, 'linea_fuerte');

        $semanaAnterior = $r['semana'] > 1 ? $r['semana'] - 1 : null;
        $tituloPrevias = $r['con_previas']
            ? 'CIERRE SEM '.($semanaAnterior ?? '')
            : 'SIN PROGRAMA';
        if ($previas !== []) {
            $this->texto($xDia(0) + $primeraSemana * self::ANCHO_DIA / 2, $y + 14, $tituloPrevias, 8.5, 'previa_tinta', true, 'centro');
        }
        $this->texto(($xDia($primeraSemana) + $xTotal) / 2, $y + 14, 'SEMANA '.$r['semana'], 8.5, 'seccion_tinta', true, 'centro');
        $y += self::ALTO_GRUPO;
        foreach ($columnas as $i => $c) {
            $centro = $xDia($i) + self::ANCHO_DIA / 2;
            $this->texto($centro, $y + 13, $c['letra'], 11, $c['previa'] ? 'previa_tinta' : 'tinta', true, 'centro');
            $this->texto($centro, $y + 27, $c['dia'], 9.5, $c['previa'] ? 'previa_tinta' : 'seccion_tinta', false, 'centro');
        }
        $this->texto($xTotal + self::ANCHO_TOTAL - 8, $y + 20, 'Total', 10, 'tinta', true, 'der');
        $y += self::ALTO_DIAS;
        $this->lineaGruesa(self::MARGEN, $y, $xFin, 'linea_fuerte');

        foreach ($r['secciones'] as $seccion) {
            if ($seccion['titulo'] !== null) {
                // Banda de sección en verde fuerte con el título en blanco.
                $this->rect(self::MARGEN, $y, self::ANCHO_ETIQUETA, self::ALTO_SECCION, 'seccion_fuerte');
                foreach ($columnas as $i => $c) {
                    $this->rect($xDia($i), $y, self::ANCHO_DIA, self::ALTO_SECCION, $c['previa'] ? 'seccion_fuerte_previa' : 'seccion_fuerte');
                }
                $this->rect($xTotal, $y, self::ANCHO_TOTAL, self::ALTO_SECCION, 'seccion_fuerte');
                $this->texto(self::MARGEN + 8, $y + 14.5, mb_strtoupper($seccion['titulo']), 9.5, 'blanco', true);
                $y += self::ALTO_SECCION;
            }
            foreach ($seccion['filas'] as $fila) {
                $y = $this->fila($fila, $columnas, $y, $xDia, $xTotal);
            }
        }

        // Separadores verticales: entre la semana anterior y la actual, y antes de la columna del total.
        if ($previas !== []) {
            $this->linea($xDia($primeraSemana), $yInicio, $xDia($primeraSemana), $y, 'linea_fuerte');
        }
        $this->linea($xTotal, $yInicio, $xTotal, $y, 'linea_fuerte');
        $this->lineaGruesa(self::MARGEN, $y, $xFin, 'linea_fuerte');

        return $y;
    }

    private function fila(array $fila, array $columnas, int $y, \Closure $xDia, int $xTotal): int
    {
        $negrita = ! empty($fila['total_fila']);
        if ($negrita) {
            $this->linea(self::MARGEN, $y, $xTotal + self::ANCHO_TOTAL, $y, 'linea_total');
        }
        $base = $y + 15;
        $this->texto(self::MARGEN + 8, $base, $fila['etiqueta'], 11, 'tinta', $negrita);
        if ($fila['nota'] !== null) {
            $anchoEtiqueta = $this->anchoTexto($fila['etiqueta'], 11, $negrita);
            $this->texto(self::MARGEN + 8 + $anchoEtiqueta + 6, $base, $fila['nota'], 9, 'tenue');
        }

        foreach ($fila['valores'] as $i => $v) {
            [$texto, $color] = $this->celda($v, $fila['tipo']);
            if ($texto === '') {
                continue;
            }
            if ($columnas[$i]['previa'] && $color === 'tinta') {
                $color = 'previa_tinta';
            }
            $this->texto($xDia($i) + self::ANCHO_DIA - 7, $base, $texto, 10.5, $color, $negrita || $fila['tipo'] === 'signo', 'der');
        }

        [$texto, $color] = $this->celda($fila['total'], $fila['tipo']);
        $this->texto($xTotal + self::ANCHO_TOTAL - 8, $base, $texto, 10.5, $color === 'cero' ? 'tenue' : $color, true, 'der');

        $this->linea(self::MARGEN, $y + self::ALTO_FILA, $xTotal + self::ANCHO_TOTAL, $y + self::ALTO_FILA, 'linea_suave');

        return $y + self::ALTO_FILA;
    }

    /** @return array{0: int, 1: list<string>} alto del pie y líneas de la leyenda */
    private function medirPie(array $r, int $anchoTabla): array
    {
        $conSobrante = $this->conSobrante($r);
        $anchoColor = $this->anchoColor($conSobrante);
        $anchoLeyenda = max(160, $anchoTabla - $anchoColor - 24);
        $leyenda = [];
        foreach ($this->textosLeyenda($r) as $parrafo) {
            array_push($leyenda, ...$this->partir($parrafo, 9.5, false, $anchoLeyenda, 4));
        }
        $altoColor = 22 + 20 + count($r['por_color']) * 20;
        $altoLeyenda = 22 + count($leyenda) * 14;

        return [max($altoColor, $altoLeyenda) + 22, $leyenda];
    }

    private function pie(array $r, int $y, int $anchoTabla, array $leyenda): int
    {
        $y += 12;
        $x0 = self::MARGEN;
        $conSobrante = $this->conSobrante($r);
        $columnas = $conSobrante
            ? [['Color', 104], ['Producción', 82], ['Necesidad', 80], ['Sobrante', 72], ['Disponible', 82], ['Cargas eq.', 70]]
            : [['Color', 104], ['Producción', 86], ['Necesidad', 86], ['Disponible', 86], ['Cargas eq.', 74]];

        $this->texto($x0, $y + 12, 'BALANCE POR COLOR · SEMANA', 9, 'seccion_tinta', true);
        $yt = $y + 22;
        $this->rect($x0, $yt, $this->anchoColor($conSobrante), 19, 'cabecera');
        $x = $x0;
        foreach ($columnas as $i => [$titulo, $anchoCol]) {
            $i === 0
                ? $this->texto($x + 6, $yt + 13, $titulo, 9, 'seccion_tinta', true)
                : $this->texto($x + $anchoCol - 4, $yt + 13, $titulo, 9, 'seccion_tinta', true, 'der');
            $x += $anchoCol;
        }
        $this->linea($x0, $yt + 19, $x0 + $this->anchoColor($conSobrante), $yt + 19, 'linea_fuerte');
        $yt += 20;
        foreach ($r['por_color'] as $c) {
            $total = ! empty($c['total']);
            if ($total) {
                $this->linea($x0, $yt, $x0 + $this->anchoColor($conSobrante), $yt, 'linea');
            }
            $signo = $c['disponible'] < 0 ? 'negativo' : ($c['disponible'] > 0 ? 'positivo' : 'tinta');
            $valores = [
                [$c['nombre'], 'tinta'],
                [$this->cajas($c['produccion']), 'tinta'],
                [$this->cajas($c['necesidad']), 'tinta'],
            ];
            if ($conSobrante) {
                $valores[] = [$this->cajas($c['sobrante']), 'tinta'];
            }
            $valores[] = [$this->signo($c['disponible']), $signo];
            $valores[] = [$c['cargas'] === null ? '–' : $this->cargasEq($c['cargas']), $signo];
            $x = $x0;
            foreach ($columnas as $i => [, $anchoCol]) {
                [$texto, $color] = $valores[$i];
                $i === 0
                    ? $this->texto($x + 6, $yt + 14, $texto, 10, $color, $total)
                    : $this->texto($x + $anchoCol - 4, $yt + 14, $texto, 10, $color, $total || $i >= count($columnas) - 2, 'der');
                $x += $anchoCol;
            }
            $yt += 20;
        }

        // Leyenda a la derecha.
        $xl = $x0 + $this->anchoColor($conSobrante) + 24;
        $this->texto($xl, $y + 12, 'CÓMO LEERLA', 9, 'seccion_tinta', true);
        $yl = $y + 22;
        foreach ($leyenda as $linea) {
            $this->texto($xl, $yl + 11, $linea, 9.5, 'tenue');
            $yl += 14;
        }

        return $y + max($yt - $y, $yl - $y) + 10;
    }

    private function notas(array $lineas, int $y, int $anchoTabla): void
    {
        $this->linea(self::MARGEN, $y, self::MARGEN + $anchoTabla, $y, 'linea');
        $this->texto(self::MARGEN, $y + 18, 'NOTAS DE LA SEMANA', 9, 'seccion_tinta', true);
        $y += 26;
        foreach ($lineas as $linea) {
            $this->texto(self::MARGEN, $y + 11, $linea, 11.5, 'tinta');
            $y += 16;
        }
    }

    // ── Textos ──────────────────────────────────────────

    /** @return list<string> */
    private function textosLeyenda(array $r): array
    {
        $previas = $r['con_previas']
            ? 'Las columnas grises son el viernes y el sábado de la semana anterior (lo programado) y no suman al total.'
            : 'La semana anterior no tiene programa: sus columnas van vacías.';

        return [
            $previas,
            'Disponible = cajas producidas − necesidad de empaque (ventas y sobrante asignado).',
            'F = carga full · S = carga sencilla. Cargas eq. = disponible ÷ '.number_format($r['cajas_por_carga']).' cajas.',
            'Las filas en cero no se muestran.',
        ];
    }

    /** @return array{0: string, 1: string} texto y color de una celda */
    private function celda(mixed $v, string $tipo): array
    {
        if ($v === null) {
            return ['', 'tinta'];
        }
        if ($tipo === 'cargas') {
            $partes = [];
            if ($v[0]) {
                $partes[] = $v[0].'F';
            }
            if ($v[1]) {
                $partes[] = $v[1].'S';
            }

            return $partes === [] ? ['–', 'cero'] : [implode(' ', $partes), 'tinta'];
        }
        if ($tipo === 'signo') {
            return [$this->signo($v), $v < 0 ? 'negativo' : ($v > 0 ? 'positivo' : 'tinta')];
        }
        if ($v == 0) {
            return ['–', 'cero'];
        }

        return [$tipo === 'ha' ? $this->hectareas($v) : $this->cajas($v), 'tinta'];
    }

    private function cajas(int|float $n): string
    {
        return number_format((float) $n);
    }

    private function signo(int|float $n): string
    {
        $n = (int) round($n);

        return $n > 0 ? '+'.number_format($n) : ($n < 0 ? '−'.number_format(-$n) : '0');
    }

    private function hectareas(int|float $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    }

    private function cargasEq(float $n): string
    {
        return ($n < 0 ? '−' : ($n > 0 ? '+' : '')).number_format(abs($n), 2);
    }

    private function conSobrante(array $r): bool
    {
        foreach ($r['por_color'] as $c) {
            if ($c['sobrante'] != 0) {
                return true;
            }
        }

        return false;
    }

    private function anchoColor(bool $conSobrante): int
    {
        return $conSobrante ? 490 : 436;
    }

    private function altoSecciones(array $secciones): int
    {
        $alto = 0;
        foreach ($secciones as $s) {
            $alto += ($s['titulo'] !== null ? self::ALTO_SECCION : 0) + count($s['filas']) * self::ALTO_FILA;
        }

        return $alto;
    }

    /**
     * Parte un texto en líneas que caben en $ancho (px a escala 1), hasta $maximo líneas.
     *
     * @return list<string>
     */
    private function partir(string $texto, float $px, bool $negrita, int $ancho, int $maximo): array
    {
        $lineas = [];
        foreach (preg_split('/\R/u', $texto) as $parrafo) {
            $actual = '';
            foreach (preg_split('/\s+/u', trim($parrafo)) as $palabra) {
                $prueba = $actual === '' ? $palabra : $actual.' '.$palabra;
                if ($actual !== '' && $this->anchoTexto($prueba, $px, $negrita) > $ancho) {
                    $lineas[] = $actual;
                    $actual = $palabra;
                } else {
                    $actual = $prueba;
                }
            }
            $lineas[] = $actual;
        }
        if (count($lineas) > $maximo) {
            $lineas = array_slice($lineas, 0, $maximo);
            $lineas[$maximo - 1] = rtrim($lineas[$maximo - 1]).' …';
        }

        return $lineas;
    }

    // ── Dibujo (coordenadas a escala 1) ─────────────────

    private function rect(float $x, float $y, float $ancho, float $alto, string $color): void
    {
        $k = self::ESCALA;
        imagefilledrectangle($this->img, (int) round($x * $k), (int) round($y * $k), (int) round(($x + $ancho) * $k) - 1, (int) round(($y + $alto) * $k) - 1, $this->color[$color]);
    }

    private function linea(float $x1, float $y1, float $x2, float $y2, string $color): void
    {
        $k = self::ESCALA;
        imagesetthickness($this->img, $k);
        imageline($this->img, (int) round($x1 * $k), (int) round($y1 * $k), (int) round($x2 * $k), (int) round($y2 * $k), $this->color[$color]);
    }

    /** Línea horizontal del doble de grosor (cabecera y cierre de la tabla). */
    private function lineaGruesa(float $x1, float $y, float $x2, string $color): void
    {
        $this->rect($x1, $y - 1, $x2 - $x1, 2, $color);
    }

    /** Texto con la línea base en $y. $alinear: izq | der | centro respecto de $x. */
    private function texto(float $x, float $y, string $texto, float $px, string $color, bool $negrita = false, string $alinear = 'izq'): void
    {
        if ($texto === '') {
            return;
        }
        $k = self::ESCALA;
        $puntos = $this->puntos($px);
        $fuente = $negrita ? $this->fuenteNegrita : $this->fuente;
        $ancho = $this->anchoTexto($texto, $px, $negrita) * $k;
        $x0 = match ($alinear) {
            'der' => $x * $k - $ancho,
            'centro' => $x * $k - $ancho / 2,
            default => $x * $k,
        };
        imagettftext($this->img, $puntos, 0, (int) round($x0), (int) round($y * $k), $this->color[$color], $fuente, $texto);
    }

    /** Ancho del texto en px a escala 1. */
    private function anchoTexto(string $texto, float $px, bool $negrita = false): float
    {
        $caja = imagettfbbox($this->puntos($px), 0, $negrita ? $this->fuenteNegrita : $this->fuente, $texto);

        return ($caja[2] - $caja[0]) / self::ESCALA;
    }

    /** GD mide la fuente en puntos a 96 ppp: px = pt × 96 / 72. */
    private function puntos(float $px): float
    {
        return $px * self::ESCALA * 0.75;
    }
}
