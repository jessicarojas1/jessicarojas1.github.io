<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform;

use App\Platform\Http\RequestContext;
use App\Platform\Http\RequestIdSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestIdSubscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset the static trusted-proxy configuration mutated by these tests.
        Request::setTrustedProxies([], 0);
    }

    public function testUpstreamIdFromATrustedProxyIsHonoured(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);
        $context = new RequestContext();

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.1.2.3']);
        $request->headers->set(RequestIdSubscriber::HEADER, 'upstream-req-0001');
        $this->dispatch($context, $request);

        self::assertSame('upstream-req-0001', $context->correlationId());
    }

    public function testUpstreamIdFromAnUntrustedClientIsIgnored(): void
    {
        Request::setTrustedProxies([], 0);
        $context = new RequestContext();

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->headers->set(RequestIdSubscriber::HEADER, 'client-chosen-9999');
        $this->dispatch($context, $request);

        self::assertNotSame('client-chosen-9999', $context->correlationId());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $context->correlationId());
    }

    private function dispatch(RequestContext $context, Request $request): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        (new RequestIdSubscriber($context))->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
    }
}
