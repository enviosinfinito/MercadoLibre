<?php

namespace Tests\Feature;

use App\Domain\Sales\Actions\UpsertCanonicalOrder;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderSatInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const CFDI_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Serie="A" Folio="123" Fecha="2026-08-01T12:00:00" Total="150.00">
  <cfdi:Emisor Rfc="AAA010101AAA" Nombre="Emisor SA"/>
  <cfdi:Receptor Rfc="XAXX010101000" Nombre="Publico General"/>
  <cfdi:Complemento>
    <tfd:TimbreFiscalDigital UUID="12345678-1234-1234-1234-1234567890AB"/>
  </cfdi:Complemento>
</cfdi:Comprobante>
XML;

    private const PDF_BYTES = "%PDF-1.4\n%fake-sat-invoice";

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection, 3: Order}
     */
    private function actingMemberWithOrder(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '112184176',
            'site_id' => 'MLM',
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

        $order = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '200000777',
            'external_pack_id' => '200000777',
            'status' => 'paid',
            'buyer_external_id' => '555001',
            'currency_code' => 'MXN',
            'total_amount' => '150',
            'meta' => ['messaging_pack_id' => '200000777'],
            'lines' => [],
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection, $order];
    }

    #[Test]
    public function facturador_returns_pdf_and_xml_base64_with_parsed_uuid(): void
    {
        [, , , $order] = $this->actingMemberWithOrder();

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/users/112184176/invoices/orders/200000777')) {
                return Http::response([
                    'id' => 'INV-1',
                    'pdf' => base64_encode(self::PDF_BYTES),
                    'xml' => base64_encode(self::CFDI_XML),
                ], 200);
            }

            return Http::response(['message' => 'unexpected '.$url], 500);
        });

        $show = $this->getJson(route('orders.show', $order));
        $show->assertOk()->assertJsonPath('invoice_probe.status', 'unknown');

        $response = $this->getJson(route('orders.invoice', $order));
        $response->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('source', 'facturador')
            ->assertJsonPath('uuid', '12345678-1234-1234-1234-1234567890AB')
            ->assertJsonPath('folio', '123')
            ->assertJsonPath('serie', 'A')
            ->assertJsonPath('issuer_rfc', 'AAA010101AAA')
            ->assertJsonPath('receiver_rfc', 'XAXX010101000')
            ->assertJsonPath('total', '150.00');

        $this->assertSame(base64_encode(self::PDF_BYTES), $response->json('pdf_base64'));
        $this->assertSame(base64_encode(self::CFDI_XML), $response->json('xml_base64'));

        $order->refresh();
        $this->assertSame('found', $order->meta['sat_invoice']['status'] ?? null);
        $this->assertSame('12345678-1234-1234-1234-1234567890AB', $order->meta['sat_invoice']['uuid'] ?? null);
        $this->assertArrayNotHasKey('pdf_base64', $order->meta['sat_invoice']);

        $pdf = $this->get(route('orders.invoice.pdf', $order));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertSame(self::PDF_BYTES, $pdf->getContent());

        $xml = $this->get(route('orders.invoice.xml', $order));
        $xml->assertOk();
        $this->assertStringContainsString('application/xml', (string) $xml->headers->get('content-type'));
        $this->assertSame(self::CFDI_XML, $xml->getContent());

        $this->getJson(route('orders.show', $order))
            ->assertOk()
            ->assertJsonPath('invoice_probe.status', 'found')
            ->assertJsonPath('invoice_probe.uuid', '12345678-1234-1234-1234-1234567890AB');
    }

    #[Test]
    public function falls_back_to_pack_fiscal_documents_when_facturador_is_missing(): void
    {
        [, , , $order] = $this->actingMemberWithOrder();

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/users/112184176/invoices/orders/')) {
                return Http::response([
                    'message' => 'not found',
                    'error' => 'not_found',
                ], 404);
            }
            if (str_contains($url, '/packs/200000777/fiscal_documents/doc-pdf')) {
                return Http::response(self::PDF_BYTES, 200, ['Content-Type' => 'application/pdf']);
            }
            if (str_contains($url, '/packs/200000777/fiscal_documents/doc-xml')) {
                return Http::response(self::CFDI_XML, 200, ['Content-Type' => 'application/xml']);
            }
            if (str_contains($url, '/packs/200000777/fiscal_documents')) {
                return Http::response([
                    'pack_id' => 200000777,
                    'fiscal_documents' => [
                        [
                            'id' => 'doc-pdf',
                            'file_type' => 'application/pdf',
                            'filename' => 'factura.pdf',
                            'date' => '2026-08-01T12:00:00Z',
                        ],
                        [
                            'id' => 'doc-xml',
                            'file_type' => 'application/xml',
                            'filename' => 'factura.xml',
                            'date' => '2026-08-01T12:00:00Z',
                        ],
                    ],
                ], 200);
            }

            return Http::response(['message' => 'unexpected '.$url], 500);
        });

        $response = $this->getJson(route('orders.invoice', $order));
        $response->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('source', 'fiscal_documents')
            ->assertJsonPath('uuid', '12345678-1234-1234-1234-1234567890AB')
            ->assertJsonPath('receiver_rfc', 'XAXX010101000');

        $this->assertSame(base64_encode(self::PDF_BYTES), $response->json('pdf_base64'));
        $this->assertSame(base64_encode(self::CFDI_XML), $response->json('xml_base64'));
    }

    #[Test]
    public function returns_not_found_when_neither_source_has_an_invoice(): void
    {
        [, , , $order] = $this->actingMemberWithOrder();

        Http::fake([
            '*/users/*/invoices/orders/*' => Http::response(['message' => 'not found'], 404),
            '*/packs/*/fiscal_documents*' => Http::response([
                'message' => 'The pack_fiscal_document with pack_id: 200000777 does not exist',
                'error' => 'not_found',
            ], 404),
        ]);

        $this->getJson(route('orders.invoice', $order))
            ->assertOk()
            ->assertJsonPath('status', 'not_found')
            ->assertJsonPath('pdf_base64', null)
            ->assertJsonPath('xml_base64', null);

        $order->refresh();
        $this->assertSame('not_found', $order->meta['sat_invoice']['status'] ?? null);
    }

    #[Test]
    public function returns_forbidden_without_500_when_facturador_denies_access(): void
    {
        [, , , $order] = $this->actingMemberWithOrder();

        Http::fake([
            '*/users/*/invoices/orders/*' => Http::response([
                'code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES',
                'message' => 'At least one policy returned UNAUTHORIZED.',
                'status' => 403,
            ], 403),
            '*/packs/*' => Http::response(['should' => 'not run'], 500),
        ]);

        $response = $this->getJson(route('orders.invoice', $order));
        $response->assertOk()
            ->assertJsonPath('status', 'forbidden')
            ->assertJsonPath('source', 'facturador');
        $this->assertStringContainsString('Facturación', (string) $response->json('error'));

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/fiscal_documents'));
    }

    #[Test]
    public function forbidden_when_facturador_is_unsupported_and_pack_documents_unauthorized(): void
    {
        [, , , $order] = $this->actingMemberWithOrder();

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/invoices')) {
                return Http::response([
                    'message' => "Request method 'GET' is not supported",
                    'error_code' => '10000',
                ], 405);
            }
            if (str_contains($url, '/fiscal_documents')) {
                return Http::response([
                    'code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES',
                    'message' => 'At least one policy returned UNAUTHORIZED.',
                    'status' => 403,
                ], 403);
            }

            return Http::response(['message' => 'unexpected '.$url], 500);
        });

        $this->getJson(route('orders.invoice', $order))
            ->assertOk()
            ->assertJsonPath('status', 'forbidden')
            ->assertJsonPath('source', 'fiscal_documents');
    }

    #[Test]
    public function invoice_returns_404_for_other_workspace(): void
    {
        [, , $connection] = $this->actingMemberWithOrder();
        $otherWorkspace = Workspace::factory()->create();
        $otherOrder = Order::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'foreign-inv',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $this->getJson(route('orders.invoice', $otherOrder))->assertNotFound();
    }
}
