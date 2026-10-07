<?php

declare(strict_types=1);

namespace DKBSign\Support;

final class EmailTemplate
{
    public function __construct(
        public string $signUrl,
        public ?string $template = null
    ) {}

    public function toHtml(): string
    {
        if (! is_null($this->template)) {
            return $this->template;
        }

        return <<<HTML
            <p style="margin:0 0 16px;">Hello,</p>
            <p style="margin:0 0 16px;">You have a document awaiting for your signature.</p>
            <p style="margin:0 0 16px;"><a href="{$this->signUrl}">Sign document</a></p>
        HTML;
    }
}
