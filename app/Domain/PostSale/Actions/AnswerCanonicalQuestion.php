<?php

namespace App\Domain\PostSale\Actions;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\Dto\FetchRequest;
use App\Integrations\Contracts\Dto\PushRequest;
use App\Models\Connection;
use App\Models\Question;
use App\Models\RawResourceSnapshot;
use App\Models\Workspace;
use RuntimeException;

final class AnswerCanonicalQuestion
{
    public function __construct(
        private readonly UpsertCanonicalQuestion $upsert,
        private readonly ConnectorRegistry $registry,
    ) {}

    public function execute(Question $question, string $text): Question
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('La respuesta no puede estar vacía.');
        }

        if ($question->connection_id === null) {
            throw new RuntimeException('La pregunta no tiene conexión asociada.');
        }

        $connection = Connection::query()->findOrFail($question->connection_id);
        if ($connection->provider !== 'mercadolibre') {
            throw new RuntimeException('Solo se pueden responder preguntas de Mercado Libre.');
        }

        $token = $this->accessToken($connection);
        if ($token === null) {
            throw new RuntimeException('La conexión no tiene access_token.');
        }

        $workspace = Workspace::query()->find($question->workspace_id);
        $dryRun = (bool) ($workspace?->outbound_dry_run ?? true);

        $connector = $this->registry->get('mercadolibre');
        $connector->push(new PushRequest(
            resource: 'question_answer',
            payload: [
                'question_id' => $question->external_question_id,
                'text' => $text,
                'access_token' => $token,
                'dry_run' => $dryRun,
            ],
        ));

        if ($dryRun) {
            $question->forceFill([
                'answer_text' => $text,
                'status' => 'answered',
                'answered_at' => now(),
            ])->save();

            return $question->fresh() ?? $question;
        }

        $result = $connector->fetch(new FetchRequest(
            resource: 'question',
            externalId: (string) $question->external_question_id,
            options: ['access_token' => $token],
        ));

        $payload = $result->payload;
        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $question->workspace_id,
            'connection_id' => $connection->id,
            'resource_type' => 'question',
            'external_id' => (string) $question->external_question_id,
            'payload' => $payload,
            'checksum' => hash('sha256', json_encode($payload) ?: ''),
            'fetched_at' => now(),
        ]);

        $mapped = $this->upsert->mapFromProviderPayload(
            $payload,
            (string) $question->external_question_id,
        );

        return $this->upsert->execute((int) $question->workspace_id, (int) $connection->id, [
            ...$mapped,
            'raw_snapshot_id' => $snapshot->id,
        ]);
    }

    private function accessToken(Connection $connection): ?string
    {
        try {
            return app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                ->execute($connection);
        } catch (\Throwable) {
            return null;
        }
    }
}
