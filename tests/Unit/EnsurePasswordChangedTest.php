<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsurePasswordChangedTest extends TestCase
{
    public function test_it_blocks_flagged_users(): void
    {
        $request = Request::create('/api/bow/voters', 'GET');
        $request->setUserResolver(fn () => (object) ['must_change_password' => true]);

        $response = (new EnsurePasswordChanged())->handle(
            $request,
            fn () => response()->json(['success' => true])
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Password change required before continuing.',
            'data' => [
                'must_change_password' => true,
            ],
        ], $response->getData(true));
    }

    public function test_it_allows_users_who_have_changed_their_password(): void
    {
        $request = Request::create('/api/bow/voters', 'GET');
        $request->setUserResolver(fn () => (object) ['must_change_password' => false]);

        $response = (new EnsurePasswordChanged())->handle(
            $request,
            fn () => 'allowed'
        );

        $this->assertSame('allowed', $response);
    }
}
