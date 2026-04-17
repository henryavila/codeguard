<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

enum StubPublishStatus: string
{
    case Created = 'created';
    case Unchanged = 'unchanged';
    case KeptCustom = 'kept-custom';
    case Overwritten = 'overwritten';
    case Failed = 'failed';
}

final readonly class StubPublishResult
{
    public function __construct(
        public StubDefinition $stub,
        public string $targetAbsolutePath,
        public StubPublishStatus $status,
        public ?string $message = null,
        public ?string $diff = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status !== StubPublishStatus::Failed;
    }

    public function hasChange(): bool
    {
        return in_array(
            $this->status,
            [StubPublishStatus::Created, StubPublishStatus::Overwritten],
            strict: true,
        );
    }
}
