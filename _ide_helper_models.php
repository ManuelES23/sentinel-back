<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $model
 * @property int|null $model_id
 * @property string|null $enterprise
 * @property string|null $application
 * @property string|null $module
 * @property string|null $submodule
 * @property array<array-key, mixed>|null $old_values
 * @property array<array-key, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog byAction($action)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog byModel($model)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog byUser($userId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog dateRange($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereApplication($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereEnterprise($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereModel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereModelId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereModule($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereNewValues($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereOldValues($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereSubmodule($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereUserId($value)
 */
	class ActivityLog extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $enterprise_id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $icon
 * @property string $path
 * @property array<array-key, mixed>|null $config
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Module> $activeModules
 * @property-read int|null $active_modules_count
 * @property-read \App\Models\Enterprise $enterprise
 * @property-read mixed $full_path
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Module> $modules
 * @property-read int|null $modules_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereEnterpriseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereUpdatedAt($value)
 */
	class Application extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $code Código único de área
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property array<array-key, mixed>|null $metadata Equipos, características, etc.
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Entity> $entities
 * @property-read int|null $entities_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Area withoutTrashed()
 */
	class Area extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $enterprise_id
 * @property string $code Código único de sucursal
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $address
 * @property string|null $city
 * @property string|null $state
 * @property string|null $country
 * @property string|null $postal_code
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $manager Nombre del responsable
 * @property bool $is_active
 * @property bool $is_main Sucursal principal
 * @property array<array-key, mixed>|null $metadata Horarios, coordenadas GPS, etc.
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Enterprise $enterprise
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Entity> $entities
 * @property-read int|null $entities_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch main()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereEnterpriseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereIsMain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereManager($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch wherePostalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch withoutTrashed()
 */
	class Branch extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $cultivo_id
 * @property string $periodo
 * @property string $nombre
 * @property string $año
 * @property \Illuminate\Support\Carbon $fecha_inicio
 * @property \Illuminate\Support\Carbon|null $fecha_fin
 * @property string $estado
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Cultivo $cultivo
 * @property-read \App\Models\User $usuario
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereAño($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereCultivoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereFechaFin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereFechaInicio($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola wherePeriodo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CicloAgricola withoutTrashed()
 */
	class CicloAgricola extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $nombre
 * @property string|null $imagen
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Productor> $productores
 * @property-read int|null $productores_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Variedad> $variedades
 * @property-read int|null $variedades_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo whereImagen($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cultivo withoutTrashed()
 */
	class Cultivo extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string|null $logo
 * @property string|null $domain
 * @property string $color
 * @property array<array-key, mixed>|null $config
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $activeApplications
 * @property-read int|null $active_applications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $applications
 * @property-read int|null $applications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereDomain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereLogo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Enterprise whereUpdatedAt($value)
 */
	class Enterprise extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $branch_id
 * @property int $entity_type_id
 * @property string $code Código único de entidad
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $location Ubicación dentro de la sucursal
 * @property string|null $responsible Responsable de la entidad
 * @property numeric|null $area_m2 Área en metros cuadrados
 * @property bool $is_active
 * @property bool $is_external Indica si es una instalación externa de terceros
 * @property string|null $owner_company Nombre de la empresa dueña de la instalación externa
 * @property string|null $contact_person Persona de contacto en la instalación externa
 * @property string|null $contact_phone Teléfono de contacto
 * @property string|null $contact_email Email de contacto
 * @property string|null $contract_number Número de contrato o convenio
 * @property \Illuminate\Support\Carbon|null $contract_start_date Fecha de inicio del contrato
 * @property \Illuminate\Support\Carbon|null $contract_end_date Fecha de finalización del contrato
 * @property string|null $contract_notes Notas adicionales sobre el contrato o acuerdo
 * @property array<array-key, mixed>|null $metadata Capacidad, equipamiento, etc.
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Area> $areas
 * @property-read int|null $areas_count
 * @property-read \App\Models\Branch $branch
 * @property-read \App\Models\EntityType $entityType
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity byBranch($branchId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity byType($typeId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity external()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity internal()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereAreaM2($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereBranchId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereContactEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereContactPerson($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereContactPhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereContractEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereContractNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereContractNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereContractStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereEntityTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereIsExternal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereLocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereOwnerCompany($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereResponsible($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity withoutTrashed()
 */
	class Entity extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $code Código único del tipo
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon Icono lucide-react
 * @property string|null $color Color hex para UI
 * @property bool $is_active
 * @property int $order Orden de visualización
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Entity> $entities
 * @property-read int|null $entities_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityType withoutTrashed()
 */
	class EntityType extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory query()
 */
	class InventoryCategory extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryItem query()
 */
	class InventoryItem extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_id
 * @property int $entity_id
 * @property string|null $entity_type
 * @property int|null $area_id
 * @property int $movement_id
 * @property \Illuminate\Support\Carbon $movement_date
 * @property string $document_number
 * @property string $transaction_type
 * @property string|null $description
 * @property numeric $quantity
 * @property numeric $balance_quantity
 * @property numeric $unit_cost
 * @property numeric $total_cost
 * @property numeric $balance_value
 * @property string|null $lot_number
 * @property string|null $serial_number
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @property-read \App\Models\InventoryMovement $movement
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex forEntity(int $entityId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex forProduct(int $productId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex inDateRange($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereAreaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereBalanceQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereBalanceValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereDocumentNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereEntityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereLotNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereMovementDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereMovementId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereSerialNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereTransactionType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryKardex whereUpdatedAt($value)
 */
	class InventoryKardex extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $document_number
 * @property int $movement_type_id
 * @property \Illuminate\Support\Carbon $movement_date
 * @property int|null $source_entity_id
 * @property string|null $source_entity_type
 * @property int|null $source_area_id
 * @property int|null $destination_entity_id
 * @property string|null $destination_entity_type
 * @property int|null $destination_area_id
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $reference_number
 * @property string|null $description
 * @property string|null $notes
 * @property string $status
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $cancelled_by
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property numeric $total_quantity
 * @property numeric $total_amount
 * @property array<array-key, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $approvedBy
 * @property-read \App\Models\User|null $approver
 * @property-read \App\Models\User|null $cancelledBy
 * @property-read \App\Models\User|null $createdBy
 * @property-read \App\Models\User|null $creator
 * @property-read \App\Models\Entity|null $destinationEntity
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryMovementDetail> $details
 * @property-read int|null $details_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryKardex> $kardexEntries
 * @property-read int|null $kardex_entries_count
 * @property-read \App\Models\MovementType $movementType
 * @property-read \App\Models\Entity|null $sourceEntity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement completed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement draft()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement forEntity(int $entityId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement inDateRange($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement ofStatus(string $status)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereApprovedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereApprovedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereCancellationReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereCancelledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereCancelledBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereDestinationAreaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereDestinationEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereDestinationEntityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereDocumentNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereMovementDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereMovementTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereReferenceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereReferenceNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereReferenceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereSourceAreaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereSourceEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereSourceEntityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereTotalQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovement withoutTrashed()
 */
	class InventoryMovement extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $movement_id
 * @property int $product_id
 * @property numeric $quantity
 * @property int|null $unit_id
 * @property numeric $conversion_factor
 * @property numeric $base_quantity
 * @property string|null $lot_number
 * @property string|null $serial_number
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property numeric $unit_cost
 * @property numeric $total_cost
 * @property int|null $source_area_id
 * @property int|null $destination_area_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\InventoryMovement $movement
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\UnitOfMeasure|null $unit
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereBaseQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereConversionFactor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereDestinationAreaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereExpiryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereLotNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereMovementId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereSerialNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereSourceAreaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementDetail whereUpdatedAt($value)
 */
	class InventoryMovementDetail extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryMovementType query()
 */
	class InventoryMovementType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_id
 * @property int $entity_id
 * @property string|null $entity_type
 * @property int|null $area_id
 * @property numeric $quantity
 * @property numeric $reserved_quantity
 * @property numeric|null $available_quantity
 * @property string|null $lot_number
 * @property string|null $serial_number
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property numeric $unit_cost
 * @property numeric $total_cost
 * @property \Illuminate\Support\Carbon|null $last_movement_at
 * @property int|null $last_movement_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @property-read \App\Models\InventoryMovement|null $lastMovement
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock expiringBefore($date)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock forArea(?int $areaId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock forEntity(int $entityId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock forProduct(int $productId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereAreaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereAvailableQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereEntityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereExpiryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereLastMovementAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereLastMovementId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereLotNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereReservedQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereSerialNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryStock withAvailable()
 */
	class InventoryStock extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $numero_lote Número secuencial del lote (autoincremental)
 * @property int|null $productor_id
 * @property int|null $zona_cultivo_id
 * @property string $nombre
 * @property string|null $codigo Código identificador del lote
 * @property numeric|null $superficie Superficie en hectáreas
 * @property array<array-key, mixed>|null $coordenadas
 * @property numeric|null $centro_lat
 * @property numeric|null $centro_lng
 * @property numeric|null $superficie_calculada
 * @property string|null $tipo_suelo
 * @property string|null $sistema_riego
 * @property string|null $descripcion
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read string $codigo_completo
 * @property-read string|null $fuente_superficie
 * @property-read string $nombre_completo
 * @property-read float|null $superficie_efectiva
 * @property-read bool $tiene_ubicacion
 * @property-read \App\Models\Productor|null $productor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Temporada> $temporadas
 * @property-read int|null $temporadas_count
 * @property-read \App\Models\ZonaCultivo|null $zonaCultivo
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote byProductor($productorId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCentroLat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCentroLng($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCodigo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCoordenadas($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereDescripcion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereNumeroLote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereProductorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereSistemaRiego($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereSuperficie($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereSuperficieCalculada($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereTipoSuelo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereZonaCultivoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote withoutTrashed()
 */
	class Lote extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $application_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $icon
 * @property string|null $path
 * @property int $order
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Submodule> $activeSubmodules
 * @property-read int|null $active_submodules_count
 * @property-read \App\Models\Application $application
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Submodule> $submodules
 * @property-read int|null $submodules_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereUpdatedAt($value)
 */
	class Module extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $direction
 * @property string $effect
 * @property bool $requires_source_entity
 * @property bool $requires_destination_entity
 * @property bool $is_system
 * @property string|null $color
 * @property string|null $icon
 * @property int $order
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryMovement> $movements
 * @property-read int|null $movements_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType adjustments()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType entries()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType exits()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType ofDirection(string $direction)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType transfers()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereDirection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereEffect($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereIsSystem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereRequiresDestinationEntity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereRequiresSourceEntity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MovementType withoutTrashed()
 */
	class MovementType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $code
 * @property string|null $sku
 * @property string|null $barcode
 * @property string $name
 * @property string|null $slug
 * @property string|null $description
 * @property int|null $category_id
 * @property int|null $unit_id
 * @property string $product_type
 * @property bool $track_inventory
 * @property bool $track_lots
 * @property bool $track_serials
 * @property bool $track_expiry
 * @property numeric $min_stock
 * @property numeric|null $max_stock
 * @property numeric|null $reorder_point
 * @property numeric|null $reorder_quantity
 * @property numeric $cost_price
 * @property numeric $sale_price
 * @property string $cost_method
 * @property string|null $image
 * @property bool $is_active
 * @property array<array-key, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\ProductCategory|null $category
 * @property-read float $available_stock
 * @property-read string|null $image_url
 * @property-read bool $is_low_stock
 * @property-read bool $needs_reorder
 * @property-read float $total_stock
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryKardex> $kardex
 * @property-read int|null $kardex_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryMovementDetail> $movementDetails
 * @property-read int|null $movement_details_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryStock> $stock
 * @property-read int|null $stock_count
 * @property-read \App\Models\UnitOfMeasure|null $unit
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product inCategory(int $categoryId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product ofType(string $type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product tracksInventory()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereBarcode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCostMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCostPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereMaxStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereMinStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereProductType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereReorderPoint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereReorderQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSalePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereTrackExpiry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereTrackInventory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereTrackLots($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereTrackSerials($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product withoutTrashed()
 */
	class Product extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $slug
 * @property string|null $description
 * @property int|null $parent_id
 * @property string|null $icon
 * @property int $order
 * @property bool $is_active
 * @property array<array-key, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProductCategory> $allChildren
 * @property-read int|null $all_children_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProductCategory> $children
 * @property-read int|null $children_count
 * @property-read string $full_path
 * @property-read ProductCategory|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory root()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory withoutTrashed()
 */
	class ProductCategory extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $tipo
 * @property string $nombre
 * @property string|null $apellido
 * @property string|null $telefono
 * @property string|null $email
 * @property string|null $direccion
 * @property string|null $rfc
 * @property string|null $notas
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Cultivo> $cultivos
 * @property-read int|null $cultivos_count
 * @property-read string $nombre_completo
 * @property-read string $tipo_label
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lote> $lotes
 * @property-read int|null $lotes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lote> $lotesActivos
 * @property-read int|null $lotes_activos_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Temporada> $temporadas
 * @property-read int|null $temporadas_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ZonaCultivo> $zonasCultivo
 * @property-read int|null $zonas_cultivo_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor externos()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor internos()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereApellido($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereDireccion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereNotas($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereRfc($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereTelefono($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereTipo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Productor withoutTrashed()
 */
	class Productor extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $module_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $icon
 * @property string|null $path
 * @property int $order
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $full_path
 * @property-read \App\Models\Module $module
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SubmodulePermissionType> $permissionTypes
 * @property-read int|null $permission_types_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule whereModuleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Submodule whereUpdatedAt($value)
 */
	class Submodule extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $submodule_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $icon
 * @property string $color
 * @property int $order
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Submodule $submodule
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserSubmodulePermission> $userPermissions
 * @property-read int|null $user_permissions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereSubmoduleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubmodulePermissionType whereUpdatedAt($value)
 */
	class SubmodulePermissionType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $cultivo_id
 * @property string $nombre
 * @property string $locacion
 * @property string $folio_temporada
 * @property int $año_inicio
 * @property int $año_fin
 * @property \Illuminate\Support\Carbon $fecha_inicio
 * @property \Illuminate\Support\Carbon $fecha_fin
 * @property string $estado
 * @property \Illuminate\Support\Carbon|null $fecha_cierre_real
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Cultivo $cultivo
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lote> $lotes
 * @property-read int|null $lotes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Productor> $productores
 * @property-read int|null $productores_count
 * @property-read \App\Models\User $usuario
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ZonaCultivo> $zonasCultivo
 * @property-read int|null $zonas_cultivo_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereAñoFin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereAñoInicio($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereCultivoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereFechaCierreReal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereFechaFin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereFechaInicio($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereFolioTemporada($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereLocacion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Temporada withoutTrashed()
 */
	class Temporada extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $variedad_id
 * @property string $nombre
 * @property string|null $descripcion
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User $usuario
 * @property-read \App\Models\Variedad $variedad
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad whereDescripcion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad whereVariedadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TipoVariedad withoutTrashed()
 */
	class TipoVariedad extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $abbreviation
 * @property string $type
 * @property numeric $conversion_factor
 * @property int|null $base_unit_id
 * @property int $precision
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read UnitOfMeasure|null $baseUnit
 * @property-read \Illuminate\Database\Eloquent\Collection<int, UnitOfMeasure> $derivedUnits
 * @property-read int|null $derived_units_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure ofType(string $type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereAbbreviation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereBaseUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereConversionFactor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure wherePrecision($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitOfMeasure withoutTrashed()
 */
	class UnitOfMeasure extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $role
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $activeApplications
 * @property-read int|null $active_applications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Enterprise> $activeEnterprises
 * @property-read int|null $active_enterprises_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $applications
 * @property-read int|null $applications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Enterprise> $enterprises
 * @property-read int|null $enterprises_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Submodule> $submodules
 * @property-read int|null $submodules_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 */
	class User extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $application_id
 * @property string|null $permissions
 * @property int $is_active
 * @property string $granted_at
 * @property string|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication whereGrantedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication wherePermissions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplication whereUserId($value)
 */
	class UserApplication extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $application_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $granted_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Application $application
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess whereGrantedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserApplicationAccess whereUserId($value)
 */
	class UserApplicationAccess extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $enterprise_id
 * @property string $role
 * @property int $is_active
 * @property string $granted_at
 * @property string|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise whereEnterpriseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise whereGrantedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterprise whereUserId($value)
 */
	class UserEnterprise extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $enterprise_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $granted_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Enterprise $enterprise
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess whereEnterpriseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess whereGrantedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserEnterpriseAccess whereUserId($value)
 */
	class UserEnterpriseAccess extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $module_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $granted_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Module $module
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess whereGrantedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess whereModuleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModuleAccess whereUserId($value)
 */
	class UserModuleAccess extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $submodule_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $granted_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Submodule $submodule
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess whereGrantedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess whereSubmoduleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmoduleAccess whereUserId($value)
 */
	class UserSubmoduleAccess extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $submodule_id
 * @property int $permission_type_id
 * @property bool $is_granted
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\SubmodulePermissionType $permissionType
 * @property-read \App\Models\Submodule $submodule
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmodulePermission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmodulePermission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmodulePermission query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmodulePermission whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmodulePermission whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmodulePermission whereIsGranted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmodulePermission wherePermissionTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmodulePermission whereSubmoduleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmodulePermission whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubmodulePermission whereUserId($value)
 */
	class UserSubmodulePermission extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $cultivo_id
 * @property string $nombre
 * @property string|null $descripcion
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Cultivo $cultivo
 * @property-read \App\Models\User $usuario
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad whereCultivoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad whereDescripcion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Variedad withoutTrashed()
 */
	class Variedad extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $nombre
 * @property string|null $ubicacion
 * @property string|null $descripcion
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read int|null $lotes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lote> $lotes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Temporada> $temporadas
 * @property-read int|null $temporadas_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo whereDescripcion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo whereUbicacion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ZonaCultivo withoutTrashed()
 */
	class ZonaCultivo extends \Eloquent {}
}

