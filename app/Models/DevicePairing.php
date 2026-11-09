<?php
// sentinel-back/app/Models/DevicePairing.php
namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DevicePairing extends Model
{
    use HasFactory, Loggable;

    public const MODE_SELF = 'self';
    public const MODE_KIOSK = 'kiosk';
    public const MODE_SF_FIELD = 'sf_field';

    /**
     * Atributos que Loggable nunca debe copiar a ActivityLog.old_values /
     * new_values. El hash del token de dispositivo no es explotable por sí
     * solo (no autentica nada sin el token crudo), pero se excluye igual
     * siguiendo el mismo precedente del proyecto que
     * EmployeeFaceTemplate::$loggableExcept para su `embedding`.
     */
    protected array $loggableExcept = ['device_token_hash'];

    protected $fillable = [
        'device_token_hash',
        'mode',
        'paired_by_employee_id',
        'paired_by_user_id',
        'enterprise_id',
        'label',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['device_token_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function pairedByEmployee()
    {
        return $this->belongsTo(Employee::class, 'paired_by_employee_id');
    }

    public function pairedByUser()
    {
        return $this->belongsTo(User::class, 'paired_by_user_id');
    }

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Caduca tras N días sin uso (config biometrics.sf_field_device_idle_days),
     * medido contra last_used_at o, si nunca se usó, contra created_at.
     */
    public function isExpired(): bool
    {
        $referencia = $this->last_used_at ?? $this->created_at;
        if ($referencia === null) {
            return false;
        }

        $dias = (int) config('biometrics.sf_field_device_idle_days');

        return $referencia->lt(now()->subDays($dias));
    }

    /** No revocado y no caducado. */
    public function estaVigente(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Busca por token crudo restringido a ciertos modos; incluye revocados
     * (a diferencia de findActiveByToken) para poder distinguir el motivo.
     *
     * @param  list<string>  $modes
     */
    public static function findByToken(string $raw, array $modes): ?self
    {
        return self::where('device_token_hash', self::hashToken($raw))
            ->whereIn('mode', $modes)
            ->first();
    }

    /**
     * Token crudo de alta entropía — se le entrega al dispositivo UNA vez,
     * en la respuesta del endpoint de emparejamiento. Nunca se guarda así.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * Mismo criterio que usa Sanctum para sus propios tokens: el valor ya es
     * aleatorio de alta entropía, así que un hash rápido (no bcrypt) es
     * suficiente y evita costo computacional innecesario en cada request.
     */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public static function findActiveByToken(string $rawToken): ?self
    {
        return self::where('device_token_hash', self::hashToken($rawToken))
            ->whereNull('revoked_at')
            ->first();
    }
}
