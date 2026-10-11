<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Spooled\Errors\SpooledError;
use Spooled\Errors\UnsupportedOperationError;
use Spooled\Http\HttpClient;
use Spooled\Resources\AdminResource;
use Spooled\Resources\AuthResource;
use Spooled\Resources\OrganizationsResource;
use Spooled\Resources\WebhookIngestionResource;

/**
 * SDKGP-26 / FEAT 2a: methods whose backend route does not exist keep their
 * symbol but fail loudly, without a network call.
 */
#[CoversClass(UnsupportedOperationError::class)]
#[CoversClass(AuthResource::class)]
#[CoversClass(OrganizationsResource::class)]
#[CoversClass(AdminResource::class)]
#[CoversClass(WebhookIngestionResource::class)]
final class UnsupportedOperationsTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string, array<int, mixed>}>
     */
    public static function unsupportedMethods(): array
    {
        return [
            'auth requestPasswordReset' => [AuthResource::class, 'requestPasswordReset', ['a@example.com']],
            'auth confirmPasswordReset' => [AuthResource::class, 'confirmPasswordReset', ['tok', 'pw']],
            'auth changePassword' => [AuthResource::class, 'changePassword', ['old', 'new']],
            'organizations clearWebhookToken' => [OrganizationsResource::class, 'clearWebhookToken', []],
            'organizations inviteMember' => [OrganizationsResource::class, 'inviteMember', ['org_1', ['email' => 'a@example.com']]],
            'organizations updateMemberRole' => [OrganizationsResource::class, 'updateMemberRole', ['org_1', 'key_1', ['role' => 'admin']]],
            'admin getConfig' => [AdminResource::class, 'getConfig', []],
            'admin updateConfig' => [AdminResource::class, 'updateConfig', [['a' => 1]]],
            'admin runMaintenance' => [AdminResource::class, 'runMaintenance', []],
            'ingest github' => [WebhookIngestionResource::class, 'github', ['org_1', '{}', ['githubEvent' => 'push', 'secret' => 's']]],
            'ingest stripe' => [WebhookIngestionResource::class, 'stripe', ['org_1', '{}', ['secret' => 's']]],
        ];
    }

    /**
     * @param class-string $class
     * @param array<int, mixed> $args
     */
    #[Test]
    #[DataProvider('unsupportedMethods')]
    public function it_throws_the_typed_error_without_any_network_call(string $class, string $method, array $args): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        foreach (['get', 'post', 'put', 'patch', 'delete', 'getRaw', 'postRaw'] as $verb) {
            $httpClient->expects($this->never())->method($verb);
        }

        $resource = $class === AdminResource::class ? new AdminResource($httpClient, 'admin-key') : new $class($httpClient);

        try {
            $resource->{$method}(...$args);
            $this->fail("{$class}::{$method} should throw");
        } catch (UnsupportedOperationError $e) {
            $this->assertInstanceOf(SpooledError::class, $e);
            $this->assertSame('UNSUPPORTED_OPERATION', $e->errorCode);
            $this->assertStringContainsString('not supported by the Spooled API', $e->getMessage());
            $this->assertStringContainsString($method, $e->getMessage());
            $this->assertSame(0, $e->statusCode);
        }
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('unsupportedMethods')]
    public function it_is_marked_deprecated(string $class, string $method): void
    {
        $doc = (new ReflectionMethod($class, $method))->getDocComment();

        $this->assertIsString($doc);
        $this->assertStringContainsString('@deprecated', $doc);
    }

    #[Test]
    public function signature_helpers_that_do_work_are_untouched(): void
    {
        $ingest = new WebhookIngestionResource($this->createMock(HttpClient::class));

        $signature = $ingest->generateGitHubSignature('{}', 'secret');

        $this->assertTrue($ingest->validateGitHubSignature('{}', $signature, 'secret'));
    }
}
