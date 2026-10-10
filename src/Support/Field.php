<?php

declare(strict_types=1);

namespace DKBSign\Support;

use DKBSign\Enums\FieldType;

final class Field
{
    public ?Position $position = null;

    public ?Anchor $anchor = null;

    public ?string $text = null;

    public function __construct(
        public string $type = FieldType::SIGNATURE->value
    ) {}

    public function usePosition(Position $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function useAnchor(Anchor $anchor): self
    {
        $this->anchor = $anchor;

        return $this;
    }
}
