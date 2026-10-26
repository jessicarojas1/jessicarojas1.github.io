<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Authorization\GlobalRole;
use App\Identity\Security\ApiRateLimitSubscriber;
use App\Identity\User\PalUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ApiRateLimitSubscriberTest extends TestCase
{
    private function subscriber(TokenStorage $tokens): ApiRateLimitSubscriber
    {
        return new ApiRateLimitSubscriber($tokens, new RateLimiterFactory(['id' => 'api', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '1 minute'], new InMemoryStorage()));
    }

    private function hit(ApiRateLimitSubscriber $subscriber, string $path = '/api/branding', string $ip = '198.51.100.7'): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, Request::create($path, server: ['REMOTE_ADDR' => $ip]), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onRequest($event);

        return $event;
    }

    public function testRequestsBeyondTheBudgetGet429WithRetryAfter(): void
    {
        $subscriber = $this->subscriber(new TokenStorage());
        for ($i = 0; $i < 5; ++$i) {
            self::assertNull($this->hit($subscriber)->getResponse());
        }

        $response = $this->hit($subscriber)->getResponse();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(429, $response->getStatusCode());
        self::assertNotNull($response->headers->get('Retry-After'));
    }

    public function testBudgetsArePerClientAndPerUser(): void
    {
        $tokens = new TokenStorage();
        $subscriber = $this->subscriber($tokens);
        for ($i = 0; $i < 5; ++$i) {
            $this->hit($subscriber, ip: '198.51.100.10');
        }
        self::assertNull($this->hit($subscriber, ip: '198.51.100.11')->getResponse(), 'another IP has its own budget');

        $user = new PalUser('5b0c6d3e-7c55-4e8e-9b3d-1f2a3b4c5d6e', 't', 'o', 'U', null, null, [GlobalRole::Reader]);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', []));
        self::assertNull($this->hit($subscriber, ip: '198.51.100.10')->getResponse(), 'a signed-in user is keyed by user, not the exhausted IP');
    }

    public function testNonApiPathsAreNotCounted(): void
    {
        $subscriber = $this->subscriber(new TokenStorage());
        for ($i = 0; $i < 10; ++$i) {
            self::assertNull($this->hit($subscriber, '/health/live')->getResponse());
        }
    }
}
