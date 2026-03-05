<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Mapping;

final readonly class MappingIndex
{
    public const VERSION = '1.0';

    /** @var MappingEntry[] */
    public array $entries;

    public string $checksum;

    /**
     * @param MappingEntry[] $entries Already sorted by sortKey()
     */
    public function __construct(array $entries)
    {
        $sorted = $entries;
        usort($sorted, static fn (MappingEntry $a, MappingEntry $b): int => strcmp($a->sortKey(), $b->sortKey()));
        $this->entries = $sorted;
        $this->checksum = self::computeChecksum($this->entries);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'entries' => array_map(
                static fn (MappingEntry $e): array => $e->toArray(),
                $this->entries,
            ),
            'checksum' => $this->checksum,
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Checksum: sha256 of compact canonical JSON of sorted entries array.
     * No JSON_PRETTY_PRINT. Uses JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE.
     *
     * @param MappingEntry[] $entries
     */
    private static function computeChecksum(array $entries): string
    {
        $entriesArray = array_map(
            static fn (MappingEntry $e): array => $e->toArray(),
            $entries,
        );

        $canonical = json_encode(
            $entriesArray,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $canonical);
    }
}
