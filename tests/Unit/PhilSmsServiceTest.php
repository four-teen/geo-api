<?php

namespace Tests\Unit;

use App\Services\PhilSmsService;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class PhilSmsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.philsms', [
            'endpoint' => 'https://sms.example.test/send',
            'token' => 'test-token',
            'sender_id' => 'GeoTag',
            'timeout' => 15,
        ]);
    }

    public function test_it_normalizes_and_sends_a_philippine_mobile_number(): void
    {
        Http::fake([
            'sms.example.test/*' => Http::response([
                'status' => 'success',
                'data' => ['uid' => 'message-123'],
            ]),
        ]);

        $result = app(PhilSmsService::class)->send('0917 123 4567', 'Test message');

        $this->assertSame('639171234567', $result['recipient']);
        $this->assertSame('message-123', $result['uid']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://sms.example.test/send'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['recipient'] === '639171234567'
                && $request['sender_id'] === 'GeoTag'
                && $request['type'] === 'plain'
                && $request['message'] === 'Test message';
        });
    }

    public function test_it_rejects_an_invalid_mobile_number_before_calling_the_provider(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(PhilSmsService::class)->send('12345', 'Test message');
        } finally {
            Http::assertNothingSent();
        }
    }
}
