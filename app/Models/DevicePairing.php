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
