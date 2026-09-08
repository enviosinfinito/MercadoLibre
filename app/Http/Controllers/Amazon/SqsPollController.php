<?php

namespace App\Http\Controllers\Amazon;

use App\Http\Controllers\Controller;
use App\Integrations\Amazon\Connector\AmazonConnector;
use App\Integrations\Contracts\Dto\WebhookPayload;
use App\Jobs\ProcessAmazonSqsMessageJob;
use App\Models\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Optional HTTP entrypoint that mimics an SQS poll cycle for local/dev.
 */
class SqsPollController extends Controller
{
    public function __invoke(Request $request, AmazonConnector $connector): JsonResponse
    {
        $connectionId = (int) $request->input('connection_id');
        $connection = Connection::query()
            ->where('provider', 'amazon')
            ->whereKey($connectionId)
            ->firstOrFail();

        $payload = $request->input('message', [
            'NotificationType' => 'ORDER_CHANGE',
            'Payload' => [
                'OrderChangeNotification' => [
                    'AmazonOrderId' => (string) $request->input('order_id', 'DEV-ORDER-1'),
                ],
                'OrderId' => (string) $request->input('order_id', 'DEV-ORDER-1'),
            ],
        ]);

        $parsed = $connector->parseWebhook(new WebhookPayload(body: $payload));

        ProcessAmazonSqsMessageJob::dispatch(
            (int) $connection->workspace_id,
            (int) $connection->id,
            [
                'raw' => $payload,
                'parsed' => [
                    'type' => $parsed->type,
                    'events' => $parsed->events,
                ],
                'Payload' => $payload['Payload'] ?? [],
                'order_id' => $parsed->events[0]['external_id'] ?? null,
            ],
        );

        return response()->json([
            'dispatched' => true,
            'events' => $parsed->events,
        ]);
    }
}
