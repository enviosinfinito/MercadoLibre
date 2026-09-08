<?php

namespace Tests\Unit\Export;

use App\Models\ExportRun;
use App\Services\Export\Delivery\EmailExportDeliveryChannel;
use App\Services\Export\Delivery\ExportDeliveryManager;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ExportDeliveryManagerTest extends TestCase
{
    #[Test]
    public function rejects_disabled_whatsapp_channel(): void
    {
        config(['export.enabled_delivery_channels' => ['email']]);
        $manager = new ExportDeliveryManager([new EmailExportDeliveryChannel]);
        $run = new ExportRun(['status' => 'completed', 'storage_path' => 'x.xlsx']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not enabled');
        $manager->deliver($run, [['type' => 'whatsapp', 'value' => '5215512345678']]);
    }

    #[Test]
    public function lists_only_enabled_registered_channels(): void
    {
        config(['export.enabled_delivery_channels' => ['email', 'whatsapp']]);
        $manager = new ExportDeliveryManager([new EmailExportDeliveryChannel]);
        $this->assertSame(['email'], $manager->enabledTypes());
    }
}
