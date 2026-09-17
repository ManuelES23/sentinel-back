<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ajuste del sistema (clave-valor). Leer siempre a través de
 * App\Services\SettingsService, que aplica valores por defecto y caché.
 */
class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];
}
