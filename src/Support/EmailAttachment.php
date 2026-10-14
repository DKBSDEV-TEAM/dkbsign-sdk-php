<?php

declare(strict_types=1);

namespace DKBSign\Support;

final class EmailAttachment
{
    public function __construct(
        public string $filename,
        public string $contentBase64,
        public ?string $mimeType = null,
    ) {}

    public static function fromPath(string $path, ?string $mimeType = null): self
    {
        if (! is_readable($path)) {
            throw new \InvalidArgumentException('Attachment file is not readable.');
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('Could not read the attachment.');
        }

        $mime = $mimeType;
        if ($mime === null && function_exists('mime_content_type')) {
            $detected = mime_content_type($path);
            $mime = is_string($detected) && $detected !== '' ? $detected : null;
        }

        return new self(basename($path), base64_encode($bytes), $mime);
    }

    /**
     * @return array{filename: string, content_base64: string, mime_type?: string}
     */
    public function toArray(): array
    {
        $payload = [
            'filename' => $this->filename,
            'content_base64' => $this->contentBase64,
        ];

        if ($this->mimeType !== null && $this->mimeType !== '') {
            $payload['mime_type'] = $this->mimeType;
        }

        return $payload;
    }
}
