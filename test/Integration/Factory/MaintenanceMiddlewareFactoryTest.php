<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Test\Integration\Factory;

use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Mezzio\Factory\MaintenanceMiddlewareFactory;
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;
use Contenir\Maintenance\Mezzio\Test\TestAsset\Container\ArrayContainer;
use Contenir\Maintenance\Mezzio\Test\TestAsset\Handler\StubRequestHandler;
use Contenir\Maintenance\Mezzio\Test\Trait\UsesTemporaryDirectory;
use Contenir\Maintenance\Repository\FileRepository;
use Contenir\Maintenance\Repository\InMemoryRepository;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function chdir;
use function mkdir;
use function rmdir;

#[Group('integration')]
#[Group('factory')]
final class MaintenanceMiddlewareFactoryTest extends TestCase
{
    use UsesTemporaryDirectory;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    /**
     * @param array<string, mixed> $services
     */
    private function build(array $services): MaintenanceMiddleware
    {
        return (new MaintenanceMiddlewareFactory())(new ArrayContainer($services));
    }

    private function dispatch(MaintenanceMiddleware $middleware): ResponseInterface
    {
        return $middleware->process(new ServerRequest(), new StubRequestHandler());
    }

    private function stateFile(): string
    {
        return $this->temporaryPath('shared/maintenance.local.php');
    }

    private function saveState(string $file, MaintenanceState $state): void
    {
        (new FileRepository($file))->save($state);
    }

    public function testReadsTheStateFileNamedByTheFileOption(): void
    {
        $this->writeTemporaryFile('shared/maintenance.local.php', '<?php return [];');
        $this->saveState($this->stateFile(), MaintenanceState::active('Down for upgrade'));

        $response = $this->dispatch($this->build([
            'config' => ['maintenance' => ['file' => $this->stateFile(), 'body_template' => 'MAINT: %s']],
        ]));

        self::assertSame([503, 'MAINT: Down for upgrade'], [$response->getStatusCode(), (string) $response->getBody()]);
    }

    public function testDefaultsToTheStateFileUnderTheWorkingDirectory(): void
    {
        $this->changeWorkingDirectoryToTemporary();
        $file = $this->writeTemporaryFile('config/autoload/maintenance.local.php', '<?php return [];');
        $this->saveState($file, MaintenanceState::active('m'));

        $response = $this->dispatch($this->build(['config' => ['maintenance' => ['body_template' => '%s']]]));

        self::assertSame(503, $response->getStatusCode());
    }

    public function testDelegatesWhenTheStateFileDoesNotExist(): void
    {
        $response = $this->dispatch($this->build([
            'config' => ['maintenance' => ['file' => $this->stateFile(), 'body_template' => '%s']],
        ]));

        self::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    public function testPicksUpToggledStateWithoutRebuildingTheMiddleware(): void
    {
        $this->writeTemporaryFile('shared/maintenance.local.php', '<?php return [];');
        $middleware = $this->build([
            'config' => ['maintenance' => ['file' => $this->stateFile(), 'body_template' => '%s']],
        ]);

        $statuses = [$this->dispatch($middleware)->getStatusCode()];
        $this->saveState($this->stateFile(), MaintenanceState::active('m'));
        $statuses[] = $this->dispatch($middleware)->getStatusCode();
        $this->saveState($this->stateFile(), MaintenanceState::inactive());
        $statuses[] = $this->dispatch($middleware)->getStatusCode();

        self::assertSame([200, 503, 200], $statuses);
    }

    public function testPrefersARegisteredRepositoryOverTheStateFile(): void
    {
        $this->writeTemporaryFile('shared/maintenance.local.php', '<?php return [];');
        $this->saveState($this->stateFile(), MaintenanceState::active('From the file'));

        $response = $this->dispatch($this->build([
            'config'                              => ['maintenance' => ['file' => $this->stateFile()]],
            MaintenanceRepositoryInterface::class => new InMemoryRepository(),
        ]));

        self::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    public function testRendersTheBundledTemplateWhenNoBodyTemplateIsConfigured(): void
    {
        $response = $this->dispatch($this->build([
            MaintenanceRepositoryInterface::class => new InMemoryRepository(MaintenanceState::active('Back at noon')),
        ]));

        self::assertStringContainsString('role="status">Back at noon</div>', (string) $response->getBody());
    }

    public function testFallsBackToARelativeStateFileWhenTheWorkingDirectoryIsGone(): void
    {
        $vanished = $this->temporaryPath('vanished');
        mkdir($vanished);
        chdir($vanished);
        rmdir($vanished);

        $response = $this->dispatch($this->build(['config' => ['maintenance' => ['body_template' => '%s']]]));

        self::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    #[DataProvider('missingConfigProvider')]
    public function testBuildsWithDefaultsWhenTheSiteHasNoMaintenanceConfig(array $services): void
    {
        $this->changeWorkingDirectoryToTemporary();

        $response = $this->dispatch($this->build($services));

        self::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingConfigProvider(): array
    {
        return [
            'no config service'           => [[]],
            'config is not an array'      => [['config' => 'not an array']],
            'no maintenance key'          => [['config' => []]],
            'maintenance is not an array' => [['config' => ['maintenance' => 'on']]],
        ];
    }
}
