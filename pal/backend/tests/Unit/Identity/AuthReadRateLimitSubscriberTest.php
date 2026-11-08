<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Security\AuthReadRateLimitSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AuthReadRateLimitSubscriberTest extends TestCase
{
    private function subscriber(): AuthReadRateLimitSubscriber
    {
        return new AuthReadRateLimitSubscriber(
            new RateLimiterFactory(['id' => 'auth_read', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 minute'], new InMemoryStorage()),
        );
    }

    private function hit(AuthReadRateLimitSubscriber $s, string $path, string $method = 'GET', string $ip = '198.51.100.7'): ?Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, Request::create($path, $method, server: ['REMOTE_ADDR' => $ip]), HttpKernelInterface::MAIN_REQUEST);
        $s->onRequest($event);

        return $event->getResponse();
    }

    public function testFloodingAuthMethodsIsRejectedWith429(): void
    {
        $s = $this->subscriber();
        for ($i = 0; $i < 3; ++$i) {
            self::assertNull($this->hit($s, '/auth/methods'));
        }
        $response = $this->hit($s, '/auth/methods');
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertNotNull($response->headers->get('Retry-After'), 'a Retry-After header is set');
    }

    public function testAuthLoginSharesTheSamePerIpBudget(): void
    {
        $s = $this->subscriber();
        self::assertNull($this->hit($s, '/auth/methods'));
        self::assertNull($this->hit($s, '/auth/login'));
        self::assertNull($this->hit($s, '/auth/methods'));
        self::assertSame(429, $this->hit($s, '/auth/login')?->getStatusCode());
    }

    public function testUnrelatedPathsAndNonGetAreIgnored(): void
    {
        $s = $this->subscriber();
        for ($i = 0; $i < 10; ++$i) {
            self::assertNull($this->hit($s, '/auth/password', 'POST'), 'the password POST is limited by the authenticator, not here');
            self::assertNull($this->hit($s, '/api/me'), 'only /auth reads are limited here');
            self::assertNull($this->hit($s, '/auth/callback'), 'the callback has its own limiter');
        }
    }

    public function testBudgetIsPerIp(): void
    {
        $s = $this->subscriber();
        for ($i = 0; $i < 3; ++$i) {
            self::assertNull($this->hit($s, '/auth/methods', 'GET', '203.0.113.1'));
        }
        self::assertNull($this->hit($s, '/auth/methods', 'GET', '203.0.113.2'), 'a different IP has its own budget');
    }
}
