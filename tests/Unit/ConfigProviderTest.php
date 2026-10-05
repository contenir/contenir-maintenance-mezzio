<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Tests\Unit;

use Contenir\Maintenance\Mezzio\ConfigProvider;
use Contenir\Maintenance\Mezzio\Factory\MaintenanceMiddlewareFactory;
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    public function testContributesNoMaintenanceKeySoSiteConfigIsTheOnlySource(): void
    {
        $config = (new ConfigProvider())();

        self::assertSame(['dependencies'], array_keys($config));
    }

    public function testDefaultBodyTemplatePathPointsAtTheBundledTemplate(): void
    {
        self::assertStringEndsWith('/templates/maintenance.phtml', ConfigProvider::defaultBodyTemplatePath());
    }

    public function testExposesTheDependenciesForDirectUse(): void
    {
        self::assertSame(
            ['factories' => [MaintenanceMiddleware::class => MaintenanceMiddlewareFactory::class]],
            (new ConfigProvider())->getDependencies(),
        );
    }

    public function testRegistersTheMiddlewareFactoryAsADependency(): void
    {
        $config = (new ConfigProvider())();

        self::assertSame(
            [MaintenanceMiddleware::class => MaintenanceMiddlewareFactory::class],
            $config['dependencies']['factories'],
        );
    }
}
