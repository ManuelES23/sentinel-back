<?php

namespace App\Services\Planeacion;

use App\Models\CRM\CrmCliente;
use App\Models\Enterprise;
use Illuminate\Support\Str;

/**
 * Clientes del CRM de Splendid by Porvenir, leídos una sola vez por petición
 * y solo si alguna fila los necesita.
 *
 * @internal
 */
final class ClientesCrmSbp
{
    /** @var array<int, string>|null id => nombre */
    private ?array $porId = null;

    /** @var array<string, array{id: int, nombre: string}>|null nombre normalizado => cliente (el de menor id) */
    private ?array $porNombre = null;

    public function nombreDe(int $id): ?string
    {
        $this->cargar();

        return $this->porId[$id] ?? null;
    }

    /** @return array{id: int, nombre: string}|null */
    public function buscarPorNombre(string $nombre): ?array
    {
        $this->cargar();

        return $this->porNombre[self::normalizar($nombre)] ?? null;
    }

    /** Sin mayúsculas, acentos ni espacios de más. */
    private static function normalizar(string $nombre): string
    {
        return Str::lower(Str::ascii(preg_replace('/\s+/u', ' ', trim($nombre))));
    }

    private function cargar(): void
    {
        if ($this->porId !== null) {
            return;
        }

        $this->porId = [];
        $this->porNombre = [];

        $empresaId = Enterprise::where('slug', 'splendidbyporvenir')->value('id');
        if (! $empresaId) {
            return;
        }

        foreach (CrmCliente::where('empresa_id', $empresaId)->orderBy('id')->get(['id', 'nombre']) as $cliente) {
            $this->porId[(int) $cliente->id] = $cliente->nombre;
            $this->porNombre[self::normalizar($cliente->nombre)] ??= ['id' => (int) $cliente->id, 'nombre' => $cliente->nombre];
        }
    }
}
