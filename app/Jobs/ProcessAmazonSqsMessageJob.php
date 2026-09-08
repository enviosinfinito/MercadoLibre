<?php

namespace App\Jobs;

use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Support\Facades\Log;

final class ProcessAmazonSqsMessageJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly array $message,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $orderId = (string) (
            $this->message['order_id']
            ?? $this->message['parsed']['events'][0]['external_id']
            ?? $this->message['Payload']['OrderChangeNotification']['AmazonOrderId']
            ?? $this->message['Payload']['OrderId']
            ?? $this->message['Payload']['AmazonOrderId']
            ?? $this->message['NotificationMetadata']['NotificationId']
            ?? ''
        );

        Log::info('amazon.sqs.process', [
            'workspace_id' => $this->workspaceId,
            'connection_id' => $this->connectionId,
            'order_id' => $orderId,
        ]);

        if ($orderId !== '') {
            FetchExternalResourceJob::dispatch(
                $this->workspaceId,
                $this->connectionId,
                'order',
                $orderId,
            );
        }
    }
}
