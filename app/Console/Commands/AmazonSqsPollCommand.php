<?php

namespace App\Console\Commands;

use App\Integrations\Amazon\Connector\AmazonConnector;
use App\Integrations\Contracts\Dto\WebhookPayload;
use App\Jobs\ProcessAmazonSqsMessageJob;
use App\Models\Connection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AmazonSqsPollCommand extends Command
{
    protected $signature = 'amazon:sqs-poll
                            {--connection= : Connection ID to poll for}
                            {--workspace= : Workspace ID filter}
                            {--max=10 : Max messages to process}
                            {--stub : Force stub messages without calling AWS}';

    protected $description = 'Poll Amazon SQS (or stub) for ORDER_CHANGE notifications and dispatch ProcessAmazonSqsMessageJob';

    public function handle(AmazonConnector $connector): int
    {
        $max = max(1, (int) $this->option('max'));
        $queueUrl = config('connectors.amazon.sqs_queue_url');
        $useStub = $this->option('stub') || empty($queueUrl);

        $query = Connection::query()->where('provider', 'amazon')->where('status', 'active');

        if ($this->option('connection')) {
            $query->whereKey((int) $this->option('connection'));
        }
        if ($this->option('workspace')) {
            $query->where('workspace_id', (int) $this->option('workspace'));
        }

        $connections = $query->get();
        if ($connections->isEmpty()) {
            $this->warn('No active Amazon connections found.');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            $messages = $useStub
                ? $this->stubMessages($max)
                : $this->receiveFromSqs($queueUrl, $max);

            foreach ($messages as $message) {
                $parsed = $connector->parseWebhook(new WebhookPayload(body: $message));

                ProcessAmazonSqsMessageJob::dispatch(
                    (int) $connection->workspace_id,
                    (int) $connection->id,
                    [
                        'raw' => $message,
                        'parsed' => [
                            'type' => $parsed->type,
                            'events' => $parsed->events,
                        ],
                        'Payload' => $message['Payload'] ?? ($parsed->events[0]['payload'] ?? []),
                        'NotificationMetadata' => $message['NotificationMetadata'] ?? [],
                        'order_id' => $parsed->events[0]['external_id'] ?? null,
                    ],
                );

                $this->line(sprintf(
                    'Dispatched SQS message for connection %d order=%s',
                    $connection->id,
                    $parsed->events[0]['external_id'] ?? 'n/a',
                ));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stubMessages(int $max): array
    {
        $messages = [];
        for ($i = 0; $i < $max; $i++) {
            $messages[] = [
                'NotificationType' => 'ORDER_CHANGE',
                'Payload' => [
                    'OrderChangeNotification' => [
                        'AmazonOrderId' => 'STUB-ORDER-'.($i + 1),
                    ],
                    'OrderId' => 'STUB-ORDER-'.($i + 1),
                ],
                'NotificationMetadata' => [
                    'NotificationId' => 'stub-notif-'.($i + 1),
                ],
            ];
        }

        Log::info('amazon.sqs.poll_stub', ['count' => count($messages)]);

        return $messages;
    }

    /**
     * Minimal ReceiveMessage stub against queue URL (requires AWS credentials in env).
     *
     * @return list<array<string, mixed>>
     */
    private function receiveFromSqs(string $queueUrl, int $max): array
    {
        // Intentionally lightweight: real AWS SigV4 signing belongs in a dedicated client.
        // When credentials are missing, fall back to stub.
        if (empty(config('connectors.amazon.aws_access_key'))) {
            $this->warn('AMAZON_AWS_ACCESS_KEY missing — using stub messages.');

            return $this->stubMessages($max);
        }

        try {
            $response = Http::get($queueUrl, [
                'Action' => 'ReceiveMessage',
                'MaxNumberOfMessages' => min(10, $max),
                'WaitTimeSeconds' => 1,
            ]);

            if ($response->failed()) {
                Log::warning('amazon.sqs.receive_failed', ['status' => $response->status()]);

                return $this->stubMessages(min(1, $max));
            }

            $body = $response->json() ?? [];
            $raw = $body['ReceiveMessageResponse']['ReceiveMessageResult']['Message'] ?? [];
            if (isset($raw['Body'])) {
                $raw = [$raw];
            }

            $out = [];
            foreach ($raw as $msg) {
                $decoded = json_decode((string) ($msg['Body'] ?? '{}'), true) ?: [];
                $out[] = is_array($decoded) ? $decoded : ['raw' => $msg];
            }

            return $out !== [] ? $out : $this->stubMessages(min(1, $max));
        } catch (\Throwable $e) {
            Log::warning('amazon.sqs.receive_exception', ['message' => $e->getMessage()]);

            return $this->stubMessages(min(1, $max));
        }
    }
}
