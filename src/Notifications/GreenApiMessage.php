<?php

namespace Ges\LaravelGreenApi\Notifications;

class GreenApiMessage
{
    public function __construct(
        public ?string $body = null,
        public mixed $file = null,
        public ?string $caption = null,
        public ?string $fileName = null,
    ) {}

    public static function make(?string $body = null): self
    {
        return new self(body: $body);
    }

    public function body(?string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function file(mixed $file, ?string $caption = null, ?string $fileName = null): self
    {
        $this->file = $file;
        $this->caption = $caption;
        $this->fileName = $fileName;

        return $this;
    }

    public function caption(?string $caption): self
    {
        $this->caption = $caption;

        return $this;
    }

    public function fileName(?string $fileName): self
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function hasFile(): bool
    {
        return $this->file !== null;
    }
}
