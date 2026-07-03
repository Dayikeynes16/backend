<?php

namespace App\Services\Ai\Assistant\Drafts;

use App\Enums\AssistantDraftType;
use RuntimeException;

/**
 * Despacha un borrador a su confirmador según el `type`. Whitelist explícita: si
 * un tipo no tiene confirmador registrado, no se puede confirmar.
 */
final class DraftConfirmerRegistry
{
    /**
     * @var array<string, DraftConfirmer>
     */
    private array $byType = [];

    /**
     * @param  iterable<DraftConfirmer>  $confirmers
     */
    public function __construct(iterable $confirmers)
    {
        foreach ($confirmers as $confirmer) {
            $this->byType[$confirmer->type()->value] = $confirmer;
        }
    }

    public function for(AssistantDraftType $type): DraftConfirmer
    {
        return $this->byType[$type->value]
            ?? throw new RuntimeException("Sin confirmador para el tipo de borrador: {$type->value}");
    }
}
