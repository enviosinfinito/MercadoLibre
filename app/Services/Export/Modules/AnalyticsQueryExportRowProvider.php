<?php

namespace App\Services\Export\Modules;

use App\Domain\Analytics\Query\QueryAst;
use App\Domain\Analytics\Query\QueryEngine;
use App\Models\User;
use App\Services\Export\Contracts\ExportColumnRegistryInterface;
use App\Services\Export\Contracts\ExportRowProviderInterface;
use Generator;

final class AnalyticsQueryExportRowProvider implements ExportRowProviderInterface
{
    public function __construct(
        private readonly ExportColumnRegistryInterface $registry,
    ) {}

    public function module(): string
    {
        return 'analytics_query';
    }

    public function count(int $workspaceId, array $filters, User $user): int
    {
        $result = $this->execute($workspaceId, $filters);

        return count($result['rows'] ?? []);
    }

    public function resolveIds(
        int $workspaceId,
        array $filters,
        ?array $ids,
        string $selectionMode,
        User $user,
    ): array {
        $count = $this->count($workspaceId, $filters, $user);

        return range(0, max(0, $count - 1));
    }

    public function iterateRows(
        int $workspaceId,
        array $ids,
        array $columnKeys,
        User $user,
    ): Generator {
        $filters = $user->getAttribute('_export_filters') ?? [];
        // filters come from export_params in the job; passed via ids sentinel + filters stored on run
        throw new \RuntimeException('Analytics rows must be iterated via iterateAnalyticsRows');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Generator<int, array<string, mixed>>
     */
    public function iterateAnalyticsRows(int $workspaceId, array $query): Generator
    {
        $result = $this->execute($workspaceId, $query);
        $columns = $result['columns'] ?? [];
        foreach ($result['rows'] ?? [] as $row) {
            $out = [];
            foreach ($columns as $col) {
                $out[(string) $col] = $row[$col] ?? null;
            }
            yield $out;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function execute(int $workspaceId, array $query): array
    {
        if (! isset($query['dataset'])) {
            return ['columns' => [], 'rows' => []];
        }

        $ast = QueryAst::fromArray($query);
        /** @var QueryEngine $engine */
        $engine = app(QueryEngine::class);

        return $engine->executeUncached($ast, $workspaceId);
    }
}
