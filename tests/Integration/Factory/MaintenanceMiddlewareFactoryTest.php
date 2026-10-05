<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Tests\Integration\Factory;

use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Mezzio\Factory\MaintenanceMiddlewareFactory;
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;
use Contenir\Maintenance\Mezzio\Tests\TestAsset\Container\ArrayContainer;
use Contenir\Maintenance\Mezzio\Tests\TestAsset\Handler\StubRequestHandler;
use Contenir\Maintenance\Mezzio\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\Maintenance\Repository\FileRepository;
use Contenir\Maintenance\Repository\InMemoryRepository;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function chdir;
use function mkdir;
use function rmdir;

#[Group('integration')]
#[Group('factory')]
final class MaintenanceMiddlewareFactoryTest extends TestCase
{
    use TemporaryDirectoryTrait;

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

    #[Test]
    public function anchorsTheDefaultStateFileToTheWorkingDirectoryAtBuildTime(): void
    {
        $this->changeWorkingDirectoryToTemporary();
        $file = $this->writeTemporaryFile('config/autoload/maintenance.local.php', '<?php return [];');
        $this->saveState($file, MaintenanceState::active('m'));
        $middleware = $this->build(['config' => ['maintenance' => ['body_template' => '%s']]]);
        $elsewhere  = $this->temporaryPath('elsewhere');
        mkdir($elsewhere);
        chdir($elsewhere);

        static::assertSame(503, $this->dispatch($middleware)->getStatusCode());
    }

    #[Test]
    #[DataProvider('missingConfigProvider')]
    public function buildsWithDefaultsWhenTheSiteHasNoMaintenanceConfig(array $services): void
    {
        $this->changeWorkingDirectoryToTemporary();

        $response = $this->dispatch($this->build($services));

        static::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    #[Test]
    public function defaultsToTheStateFileUnderTheWorkingDirectory(): void
    {
        $this->changeWorkingDirectoryToTemporary();
        $file = $this->writeTemporaryFile('config/autoload/maintenance.local.php', '<?php return [];');
        $this->saveState($file, MaintenanceState::active('m'));

        $response = $this->dispatch($this->build(['config' => ['maintenance' => ['body_template' => '%s']]]));

        static::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function delegatesWhenTheStateFileDoesNotExist(): void
    {
        $response = $this->dispatch($this->build([
            'config' => ['maintenance' => ['file' => $this->stateFile(), 'body_template' => '%s']],
        ]));

        static::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    #[Test]
    public function fallsBackToARelativeStateFileWhenTheWorkingDirectoryIsGone(): void
    {
        $vanished = $this->temporaryPath('vanished');
        mkdir($vanished);
        chdir($vanished);
        rmdir($vanished);

        $response = $this->dispatch($this->build(['config' => ['maintenance' => ['body_template' => '%s']]]));

        static::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    #[Test]
    public function picksUpToggledStateWithoutRebuildingTheMiddleware(): void
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

        static::assertSame([200, 503, 200], $statuses);
    }

    #[Test]
    public function prefersARegisteredRepositoryOverTheStateFile(): void
    {
        $this->writeTemporaryFile('shared/maintenance.local.php', '<?php return [];');
        $this->saveState($this->stateFile(), MaintenanceState::active('From the file'));

        $response = $this->dispatch($this->build([
            'config'                              => ['maintenance' => ['file' => $this->stateFile()]],
            MaintenanceRepositoryInterface::class => new InMemoryRepository(),
        ]));

        static::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    #[Test]
    public function readsTheNamespacedStateFileTheAdminWrites(): void
    {
        $this->writeTemporaryFile(
            'shared/maintenance.local.php',
            "<?php return ['maintenance' => ['state' => ['active' => true, 'message' => 'Written by the admin']]];",
        );

        $response = $this->dispatch($this->build([
            'config' => ['maintenance' => ['file' => $this->stateFile(), 'body_template' => 'MAINT: %s']],
        ]));

        static::assertSame('MAINT: Written by the admin', (string) $response->getBody());
    }

    #[Test]
    public function readsTheStateFileNamedByTheFileOption(): void
    {
        $this->writeTemporaryFile('shared/maintenance.local.php', '<?php return [];');
        $this->saveState($this->stateFile(), MaintenanceState::active('Down for upgrade'));

        $response = $this->dispatch($this->build([
            'config' => ['maintenance' => ['file' => $this->stateFile(), 'body_template' => 'MAINT: %s']],
        ]));

        static::assertSame([503, 'MAINT: Down for upgrade'], [
            $response->getStatusCode(),
            (string) $response->getBody(),
        ]);
    }

    #[Test]
    public function rendersTheBundledTemplateWhenNoBodyTemplateIsConfigured(): void
    {
        $response = $this->dispatch($this->build([
            MaintenanceRepositoryInterface::class => new InMemoryRepository(MaintenanceState::active('Back at noon')),
        ]));

        static::assertStringContainsString('role="status">Back at noon</div>', (string) $response->getBody());
    }

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

    private function saveState(string $file, MaintenanceState $state): void
    {
        (new FileRepository($file))->save($state);
    }

    private function stateFile(): string
    {
        return $this->temporaryPath('shared/maintenance.local.php');
    }
}
