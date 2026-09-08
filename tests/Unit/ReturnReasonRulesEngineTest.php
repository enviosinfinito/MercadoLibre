<?php

namespace Tests\Unit;

use App\Domain\Returns\Actions\ReturnReasonRulesEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReturnReasonRulesEngineTest extends TestCase
{
    #[Test]
    public function detects_size_reason_from_buyer_text(): void
    {
        $engine = app(ReturnReasonRulesEngine::class);

        $result = $engine->execute([
            'texts' => ['Me quedó más chico de lo esperado, la talla no coincide'],
            'evidence' => [
                ['source' => 'order_message', 'id' => 1, 'text' => 'Me quedó más chico de lo esperado'],
            ],
            'ml_reason' => null,
        ]);

        $this->assertSame('size', $result['reason_group']);
        $this->assertContains($result['confidence'], ['medium', 'high']);
        $this->assertNotEmpty($result['summary']);
    }

    #[Test]
    public function detects_defective_reason(): void
    {
        $engine = app(ReturnReasonRulesEngine::class);

        $result = $engine->execute([
            'texts' => ['El producto no prende y está defectuoso'],
            'evidence' => [
                ['source' => 'problem', 'id' => 1, 'text' => 'El producto no prende'],
            ],
            'ml_reason' => 'Producto defectuoso',
        ]);

        $this->assertSame('defective', $result['reason_group']);
    }
}
