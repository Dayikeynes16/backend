<?php

namespace App\Services\Ai\Assistant\Drafts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resultado de confirmar un borrador: el registro real creado, un mensaje para
 * el usuario y los datos con que la tarjeta del chat se repinta en estado
 * "confirmado".
 */
final class DraftConfirmationResult
{
    /**
     * @param  array<string, mixed>  $card
     */
    public function __construct(
        public readonly Model $record,
        public readonly string $message,
        public readonly array $card = [],
    ) {}
}
