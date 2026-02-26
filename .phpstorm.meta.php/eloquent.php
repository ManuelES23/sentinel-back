<?php

namespace Illuminate\Database\Eloquent;

/**
 * @method static Builder where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static Builder whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false)
 * @method static Model|null first(array|string $columns = ['*'])
 * @method static Model|null find(mixed $id, array $columns = ['*'])
 * @method bool delete()
 */
class Model {}

/**
 * @method Builder where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method Builder whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false)
 * @method Model|null first(array|string $columns = ['*'])
 */
class Builder {}
