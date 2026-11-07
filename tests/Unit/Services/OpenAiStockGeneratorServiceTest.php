<?php

namespace Tests\Unit\Services;

use App\Features\Ai\Services\OpenAiStockGeneratorService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAiStockGeneratorServiceTest extends TestCase
{
    public function test_empty_prompt_throws_exception(): void
    {
        $service = new OpenAiStockGeneratorService('https://api.openai.com/v1', 'mock-key', 'gpt-4o-mini');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt tidak boleh kosong.');
        $service->generateStocks('   ');
    }

    public function test_successful_generation(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'stocks' => [
                                ['name' => 'Kertas A4', 'description' => 'Kertas 80gsm', 'quantity' => 20, 'unit' => 'rim', 'price' => 55000, 'min_stock' => 4],
                                ['name' => 'Pulpen', 'description' => '   ', 'quantity' => -3, 'unit' => '', 'price' => 'abc', 'min_stock' => 'x'],
                                ['name' => 'Spidol', 'quantity' => 'bad', 'price' => -1],
                            ],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $service = new OpenAiStockGeneratorService('https://api.openai.com/v1', 'mock-key', 'gpt-4o-mini');
        $results = $service->generateStocks('Buatkan 3 item alat tulis', 3);

        $this->assertCount(3, $results);
        $this->assertSame('Kertas A4', $results[0]->name);
        $this->assertSame('Kertas 80gsm', $results[0]->description);
        $this->assertSame(20, $results[0]->quantity);
        $this->assertSame('rim', $results[0]->unit);
        $this->assertSame(55000.0, $results[0]->price);
        $this->assertSame(4, $results[0]->minStock);

        // sanitised fallbacks
        $this->assertNull($results[1]->description);
        $this->assertSame(0, $results[1]->quantity);
        $this->assertSame('pcs', $results[1]->unit);
        $this->assertNull($results[1]->price);
        $this->assertSame(5, $results[1]->minStock);
        $this->assertSame(0, $results[2]->quantity);
        $this->assertNull($results[2]->price);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer mock-key')
                && str_contains($body['messages'][0]['content'], 'inventory')
                && str_contains($body['messages'][0]['content'], 'exactly 3');
        });
    }

    public function test_generation_uses_config_defaults(): void
    {
        config(['openai.endpoint' => 'https://cfg.example.com/v1', 'openai.key' => 'cfg-key', 'openai.model' => 'cfg-model']);
        Http::fake([
            'https://cfg.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"stocks":[{"name":"X"}]}']]],
            ]),
        ]);

        $results = (new OpenAiStockGeneratorService)->generateStocks('apa saja', 0); // count clamped to 1

        $this->assertCount(1, $results);
        Http::assertSent(fn ($r) => $r['model'] === 'cfg-model' && str_contains($r['messages'][0]['content'], 'exactly 1'));
    }

    public function test_api_failure_without_error_message_uses_status_code(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $service = new OpenAiStockGeneratorService('https://api.openai.com/v1', 'mock-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Request failed with status code 500');
        $service->generateStocks('apa saja');
    }

    public function test_empty_response_content_is_invalid(): void
    {
        Http::fake(['*' => Http::response(['choices' => []], 200)]);

        $service = new OpenAiStockGeneratorService('https://api.openai.com/v1', 'mock-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI mengembalikan format respon yang tidak valid.');
        $service->generateStocks('apa saja');
    }

    public function test_api_failure_throws_exception(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Invalid API Key provided',
                ],
            ], 401),
        ]);

        $service = new OpenAiStockGeneratorService('https://api.openai.com/v1', 'mock-key', 'gpt-4o-mini');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API Error: Invalid API Key provided');
        $service->generateStocks('Barang kantor');
    }

    public function test_parse_content_with_markdown_fences(): void
    {
        $service = new OpenAiStockGeneratorService('https://api.openai.com/v1', 'mock-key', 'gpt-4o-mini');
        $raw = "```json\n[{\"name\": \"Code Fence Stock\", \"description\": \"Test desc\"}]\n```";

        $results = $service->parseContent($raw);
        $this->assertCount(1, $results);
        $this->assertEquals('Code Fence Stock', $results[0]->name);
    }

    public function test_parse_content_invalid_json(): void
    {
        $service = new OpenAiStockGeneratorService('https://api.openai.com/v1', 'mock-key', 'gpt-4o-mini');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI mengembalikan format respon yang tidak valid.');
        $service->parseContent('Not valid JSON');
    }

    public function test_parse_content_empty_stocks(): void
    {
        $service = new OpenAiStockGeneratorService('https://api.openai.com/v1', 'mock-key', 'gpt-4o-mini');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tidak ada item stok yang berhasil di-generate dari AI.');
        $service->parseContent('{"stocks": []}');
    }

    public function test_parse_content_no_valid_items(): void
    {
        $service = new OpenAiStockGeneratorService('https://api.openai.com/v1', 'mock-key', 'gpt-4o-mini');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Format item stok yang dikembalikan AI tidak sesuai spesifikasi.');
        $service->parseContent('{"stocks": [{"bad_key": "val"}]}');
    }

    public function test_constructor_handles_swapped_endpoint_and_key(): void
    {
        Http::fake([
            'https://ai.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"stocks":[{"name":"Barang"}]}']]],
            ]),
        ]);

        // Key given as URL, endpoint given as secret
        $service = new OpenAiStockGeneratorService('sk-secret-key', 'https://ai.example.com/v1');
        $stocks = $service->generateStocks('Barang kantor');
        $this->assertCount(1, $stocks);
    }

    public function test_constructor_auto_prefixes_https(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"stocks":[{"name":"Barang"}]}']]],
            ]),
        ]);

        $service = new OpenAiStockGeneratorService('api.openai.com/v1', 'mock-key');
        $stocks = $service->generateStocks('Barang kantor');
        $this->assertCount(1, $stocks);
    }
}
