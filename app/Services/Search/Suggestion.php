<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * A single autocomplete suggestion.
 */
final readonly class Suggestion
{
    /**
     * @param int $id Record identifier.
     * @param string $content Record content.
     * @param string $match "exact" or "corrected".
     * @param float|null $score Trigram similarity score when available.
     */
    public function __construct(
        public int $id,
        public string $content,
        public string $match,
        public ?float $score = null,
    ) {}

    /**
     * Convert the suggestion to its API representation.
     *
     * Selection tokens are added by the controller because they are signed
     * per response and never cached.
     *
     * @return array{id:int,content:string,match:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'match' => $this->match,
        ];
    }
}
