<?php

declare(strict_types=1);

namespace DKBSign\Support;

use DKBSign\Enums\SignatureType;

final class Signature
{
    public ?Position $position = null;

    public ?Anchor $anchor = null;

    public ?string $text = null;

    public function __construct(
        public string $type = SignatureType::SIGNATURE->value
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
