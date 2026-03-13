<?php

declare(strict_types=1);

namespace LPhenom\Queue;

/**
 * Immutable Job value object.
 *
 * Represents a queue task with its metadata.
 *
 * KPHP-compatible:
 *   - No constructor property promotion
 *   - No readonly properties
 *   - No union types int|string|...
 *   - Explicit property declarations
 *
 * @lphenom-build shared,kphp
 */
final class Job
{
    /** @var string */
    private string $id;

    /** @var string */
    private string $name;

    /** @var string */
    private string $payloadJson;

    /** @var int */
    private int $attempts;

    /** @var int Unix timestamp when the job becomes available */
    private int $availableAt;

    /** @var int|null Unix timestamp when the job was reserved; null if not reserved */
    private ?int $reservedAt;

    public function __construct(
        string $id,
        string $name,
        string $payloadJson,
        int $attempts,
        int $availableAt,
        ?int $reservedAt
    ) {
        $this->id          = $id;
        $this->name        = $name;
        $this->payloadJson = $payloadJson;
        $this->attempts    = $attempts;
        $this->availableAt = $availableAt;
        $this->reservedAt  = $reservedAt;
    }

    /**
     * Create a new job scheduled to run immediately.
     */
    public static function create(string $name, string $payloadJson, int $availableAt = 0): self
    {
        if ($availableAt === 0) {
            $availableAt = (int) time();
        }

        return new self(
            self::generateId(),
            $name,
            $payloadJson,
            0,
            $availableAt,
            null
        );
    }

    /**
     * Generate a RFC-4122 v4 UUID without reflection or dynamic calls.
     * KPHP-compatible: uses mt_rand() and sprintf().
     */
    private static function generateId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPayloadJson(): string
    {
        return $this->payloadJson;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getAvailableAt(): int
    {
        return $this->availableAt;
    }

    public function getReservedAt(): ?int
    {
        return $this->reservedAt;
    }

    /**
     * Return a copy with updated attempts counter.
     */
    public function withAttempts(int $attempts): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->payloadJson,
            $attempts,
            $this->availableAt,
            $this->reservedAt
        );
    }

    /**
     * Return a copy with updated availableAt timestamp.
     */
    public function withAvailableAt(int $availableAt): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->payloadJson,
            $this->attempts,
            $availableAt,
            $this->reservedAt
        );
    }

    /**
     * Return a copy with updated reservedAt timestamp.
     */
    public function withReservedAt(?int $reservedAt): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->payloadJson,
            $this->attempts,
            $this->availableAt,
            $reservedAt
        );
    }
}
