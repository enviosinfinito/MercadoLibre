<?php

namespace Tests\Feature;

use App\Domain\PostSale\Actions\UpsertCanonicalQuestion;
use App\Integrations\Contracts\Dto\PullRequest;
use App\Integrations\Contracts\Dto\WebhookPayload;
use App\Integrations\MercadoLibre\Connector\MercadoLibreConnector;
use App\Jobs\FetchExternalResourceJob;
use App\Jobs\ProcessCanonicalQuestionJob;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\Question;
use App\Models\RawResourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MercadoLibreQuestionsSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection}
     */
    private function actingMemberWithMeliConnection(bool $outboundDryRun = true): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['outbound_dry_run' => $outboundDryRun]);

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '112184176',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh',
            'user_id' => '112184176',
        ]);
        $credential->save();

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_key' => 'questions',
            'enabled' => true,
            'config' => ['include' => ['raw_snapshot' => true, 'answer' => true]],
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleQuestionPayload(
        string $questionId = '900001',
        string $itemId = 'MLM123',
        string $status = 'ANSWERED',
    ): array {
        $payload = [
            'id' => (int) $questionId,
            'seller_id' => 112184176,
            'item_id' => $itemId,
            'text' => '¿Tiene garantía?',
            'status' => $status,
            'date_created' => '2026-08-01T12:00:00.000-00:00',
            'from' => [
                'id' => 555001,
                'answered_questions' => 2,
            ],
        ];

        if (strtoupper($status) === 'ANSWERED') {
            $payload['answer'] = [
                'text' => 'Sí, 12 meses.',
                'status' => 'ACTIVE',
                'date_created' => '2026-08-01T13:00:00.000-00:00',
            ];
        }

        return $payload;
    }

    #[Test]
    public function parse_webhook_extracts_question_resource(): void
    {
        $connector = app(MercadoLibreConnector::class);

        $parsed = $connector->parseWebhook(new WebhookPayload(
            body: [
                'topic' => 'questions',
                'resource' => '/questions/900001',
                'user_id' => 112184176,
                '_id' => 'evt-q-1',
            ],
        ));

        $this->assertSame('question', $parsed->type);
        $this->assertCount(1, $parsed->events);
        $this->assertSame('question', $parsed->events[0]['resource_type']);
        $this->assertSame('900001', $parsed->events[0]['external_id']);
    }

    #[Test]
    public function pull_questions_returns_paginated_items(): void
    {
        Http::fake([
            '*/questions/search*' => Http::response([
                'total' => 1,
                'limit' => 50,
                'questions' => [
                    $this->sampleQuestionPayload(),
                ],
            ], 200),
        ]);

        $connector = app(MercadoLibreConnector::class);
        $result = $connector->pull(new PullRequest(
            resource: 'questions',
            cursor: ['offset' => 0],
            options: [
                'access_token' => 'token',
                'user_id' => '112184176',
            ],
        ));

        $this->assertCount(1, $result->items);
        $this->assertTrue((bool) ($result->nextCursor['done'] ?? false));
        $this->assertSame(900001, $result->items[0]['id']);
    }

    #[Test]
    public function process_canonical_question_upserts_with_answer(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'question',
            'external_id' => '900001',
            'payload' => $this->sampleQuestionPayload(),
            'checksum' => 'q-abc',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalQuestionJob(
            $workspace->id,
            $connection->id,
            (int) $snapshot->id,
        ))->handle();

        $question = Question::query()
            ->where('connection_id', $connection->id)
            ->where('external_question_id', '900001')
            ->firstOrFail();

        $this->assertSame('answered', $question->status);
        $this->assertSame('¿Tiene garantía?', $question->question_text);
        $this->assertSame('Sí, 12 meses.', $question->answer_text);
        $this->assertSame('MLM123', $question->external_item_id);
        $this->assertSame('555001', $question->buyer_external_id);
        $this->assertNotNull($question->asked_at);
        $this->assertNotNull($question->answered_at);
        $this->assertSame($snapshot->id, $question->raw_snapshot_id);
    }

    #[Test]
    public function fetch_external_resource_projects_question(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        Http::fake([
            '*/questions/900099*' => Http::response($this->sampleQuestionPayload('900099', 'MLM999', 'UNANSWERED'), 200),
        ]);

        (new FetchExternalResourceJob(
            $workspace->id,
            $connection->id,
            'question',
            '900099',
            projectSynchronously: true,
        ))->handle();

        $question = Question::query()
            ->where('connection_id', $connection->id)
            ->where('external_question_id', '900099')
            ->firstOrFail();

        $this->assertSame('unanswered', $question->status);
        $this->assertNull($question->answer_text);
    }

    #[Test]
    public function answer_post_updates_question_in_dry_run(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection(outboundDryRun: true);

        $question = app(UpsertCanonicalQuestion::class)->execute($workspace->id, $connection->id, [
            'external_question_id' => '900010',
            'external_item_id' => 'MLM10',
            'status' => 'unanswered',
            'question_text' => '¿Hay stock?',
            'asked_at' => now(),
        ]);

        $response = $this->postJson(route('questions.answer', $question), [
            'text' => 'Sí, disponible.',
        ]);

        $response->assertOk()
            ->assertJsonPath('question.status', 'answered')
            ->assertJsonPath('question.answer_text', 'Sí, disponible.');

        $question->refresh();
        $this->assertSame('answered', $question->status);
        $this->assertSame('Sí, disponible.', $question->answer_text);
    }

    #[Test]
    public function answer_post_calls_meli_and_refetches_when_not_dry_run(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection(outboundDryRun: false);

        $question = app(UpsertCanonicalQuestion::class)->execute($workspace->id, $connection->id, [
            'external_question_id' => '900011',
            'external_item_id' => 'MLM11',
            'status' => 'unanswered',
            'question_text' => '¿Color?',
            'asked_at' => now(),
        ]);

        Http::fake([
            '*/answers' => Http::response(['status' => 'ACTIVE'], 200),
            '*/questions/900011*' => Http::response(
                $this->sampleQuestionPayload('900011', 'MLM11', 'ANSWERED'),
                200,
            ),
        ]);

        $response = $this->postJson(route('questions.answer', $question), [
            'text' => 'Azul',
        ]);

        $response->assertOk()
            ->assertJsonPath('question.status', 'answered');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/answers')
                && $request->method() === 'POST'
                && (int) ($request['question_id'] ?? 0) === 900011;
        });
    }

    #[Test]
    public function questions_index_filters_unanswered(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();
        $upsert = app(UpsertCanonicalQuestion::class);

        $upsert->execute($workspace->id, $connection->id, [
            'external_question_id' => '1',
            'status' => 'unanswered',
            'question_text' => 'A',
            'asked_at' => now(),
        ]);
        $upsert->execute($workspace->id, $connection->id, [
            'external_question_id' => '2',
            'status' => 'answered',
            'question_text' => 'B',
            'answer_text' => 'Ok',
            'asked_at' => now(),
        ]);

        $response = $this->get(route('questions.index', ['tab' => 'unanswered']));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Questions/Index')
                ->has('questions.data', 1)
                ->where('questions.data.0.status', 'unanswered')
                ->where('filters.tab', 'unanswered')
            );
    }

}
