<?php

namespace Tests\Unit\Support;

use App\Support\Meta\MetaOAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MetaOAuthStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_consumes_nonce_and_rejects_replay(): void
    {
        $state = app(MetaOAuthState::class)->issue(11, 22, MetaOAuthState::PURPOSE_FACEBOOK, MetaOAuthState::RETURN_MOBILE);

        $first = app(MetaOAuthState::class)->verify($state['state'], MetaOAuthState::PURPOSE_FACEBOOK);
        $this->assertSame(11, $first['workspace_id']);
        $this->assertSame(22, $first['user_id']);
        $this->assertSame(MetaOAuthState::RETURN_MOBILE, $first['return_to']);

        $this->expectException(RuntimeException::class);
        app(MetaOAuthState::class)->verify($state['state'], MetaOAuthState::PURPOSE_FACEBOOK);
    }

    public function test_peek_does_not_consume_nonce(): void
    {
        $issued = app(MetaOAuthState::class)->issue(5, 9, MetaOAuthState::PURPOSE_INSTAGRAM);

        $peeked = app(MetaOAuthState::class)->peek($issued['state']);
        $this->assertSame(5, $peeked['workspace_id']);

        $verified = app(MetaOAuthState::class)->verify($issued['state']);
        $this->assertSame(9, $verified['user_id']);
    }

    public function test_purpose_mismatch_is_rejected(): void
    {
        $issued = app(MetaOAuthState::class)->issue(1, 2, MetaOAuthState::PURPOSE_TIKTOK);

        $this->expectException(RuntimeException::class);
        app(MetaOAuthState::class)->peek($issued['state'], MetaOAuthState::PURPOSE_FACEBOOK);
    }
}
