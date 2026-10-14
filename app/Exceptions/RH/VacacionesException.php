<?php

namespace App\Exceptions\RH;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Regla de vacaciones incumplida. Laravel la responde sola como JSON (422 o 403). */
class VacacionesException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage()], $this->status);
    }
}
