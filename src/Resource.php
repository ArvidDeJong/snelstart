<?php

declare(strict_types=1);

namespace Darvis\Snelstart;

use Darvis\Snelstart\Exceptions\SnelstartException;

/**
 * One collection of the API, such as "relaties". Every method is a single call, except all().
 * Not every collection supports every method; SnelStart answers the ones it does not have with
 * a 404 or a 405, which arrives as a SnelstartException.
 */
final class Resource
{
    /**
     * What SnelStart returns at most for one call on a collection.
     */
    public const PAGE_SIZE = 500;

    /**
     * @param  bool  $paged  whether the collection takes $skip and $top
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $path,
        private readonly bool $paged = true,
    ) {}

    /**
     * GET the collection: one call, at most 500 rows. The query takes the OData options of the
     * endpoint: '$filter', '$top' and '$skip'.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, mixed>
     */
    public function list(array $query = []): array
    {
        $rows = $this->client->get($this->path, $query);

        return is_array($rows) && array_is_list($rows) ? $rows : [];
    }

    /**
     * Every row of the collection, fetched page by page while you loop. Stop looping and no
     * further page is fetched.
     *
     * @param  array<string, mixed>  $query  '$filter' and any other option, '$skip' to start further on
     * @return \Generator<int, mixed>
     *
     * @throws SnelstartException also when a collection returns the same page twice
     */
    public function all(array $query = [], int $pageSize = self::PAGE_SIZE): \Generator
    {
        if (! $this->paged) {
            yield from $this->list($query);

            return;
        }

        $pageSize = max(1, min($pageSize, self::PAGE_SIZE));
        $skip = max(0, (int) ($query['$skip'] ?? 0));
        $rows = null;

        do {
            $previous = $rows;
            $rows = $this->list(['$skip' => $skip, '$top' => $pageSize] + $query);

            // The same full page twice: this collection ignores $skip, and the loop would never end.
            if ($rows !== [] && $rows === $previous) {
                throw new SnelstartException('Snelstart paging does not advance: "'.$this->path.'" returned the same page twice. Use list(), or resource($path, false) for a collection without paging.');
            }

            foreach ($rows as $row) {
                yield $row;
            }

            $skip += count($rows);
        } while (count($rows) >= $pageSize);
    }

    /**
     * GET one row by its id.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function find(string $id, array $query = []): array
    {
        return $this->row($this->client->get($this->path.'/'.rawurlencode($id), $query));
    }

    /**
     * POST a new row. Returns the row as SnelStart stored it.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function create(array $data): array
    {
        return $this->row($this->client->post($this->path, $data));
    }

    /**
     * PUT a row. The API documents a whole model for this call, so send the whole row.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function update(string $id, array $data): array
    {
        return $this->row($this->client->put($this->path.'/'.rawurlencode($id), $data));
    }

    /**
     * DELETE a row.
     */
    public function delete(string $id): void
    {
        $this->client->delete($this->path.'/'.rawurlencode($id));
    }

    /**
     * @return array<mixed>
     */
    private function row(mixed $data): array
    {
        return is_array($data) ? $data : [];
    }
}
