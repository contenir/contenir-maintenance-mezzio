<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Tests\Unit;

use Contenir\Maintenance\Mezzio\ConfigProvider;
use Contenir\Maintenance\Mezzio\Factory\MaintenanceMiddlewareFactory;
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function contributesNoMaintenanceKeySoSiteConfigIsTheOnlySource(): void
    {
        $config = (new ConfigProvider())();

        static::assertSame(['dependencies'], array_keys($config));
    }

    #[Test]
    public function defaultBodyTemplatePathPointsAtTheBundledTemplate(): void
    {
        static::assertStringEndsWith('/templates/maintenance.phtml', ConfigProvider::defaultBodyTemplatePath());
    }

    #[Test]
    public function exposesTheDependenciesForDirectUse(): void
    {
        static::assertSame(
            ['factories' => [MaintenanceMiddleware::class => MaintenanceMiddlewareFactory::class]],
            (new ConfigProvider())->getDependencies(),
        );
    }

    #[Test]
    public function registersTheMiddlewareFactoryAsADependency(): void
    {
        $config = (new ConfigProvider())();

        static::assertSame(
            [MaintenanceMiddleware::class => MaintenanceMiddlewareFactory::class],
            $config['dependencies']['factories'],
        );
    }
}
