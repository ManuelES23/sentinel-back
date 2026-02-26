<?php

namespace App\Traits;

use App\Models\ActivityLog;

trait Loggable
{
    /**
     * Boot del trait
     */
    public static function bootLoggable()
    {
        // Evento al crear
        static::created(function ($model) {
            ActivityLog::log(
                action: 'create',
                model: class_basename($model),
                modelId: $model->id,
                oldValues: null,
                newValues: $model->getAttributes()
            );
        });

        // Evento al actualizar
        static::updated(function ($model) {
            $dirty = $model->getDirty();
            $original = [];
            
            foreach (array_keys($dirty) as $key) {
                $original[$key] = $model->getOriginal($key);
            }

            ActivityLog::log(
                action: 'update',
                model: class_basename($model),
                modelId: $model->id,
                oldValues: $original,
                newValues: $dirty
            );
        });

        // Evento al eliminar
        static::deleted(function ($model) {
            ActivityLog::log(
                action: 'delete',
                model: class_basename($model),
                modelId: $model->id,
                oldValues: $model->getAttributes(),
                newValues: null
            );
        });
    }
}
