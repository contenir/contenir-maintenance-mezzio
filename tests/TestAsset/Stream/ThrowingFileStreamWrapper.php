<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Tests\TestAsset\Stream;

use RuntimeException;

use function in_array;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;

/**
 * A stream wrapper whose paths stat as readable regular files but throw when
 * opened, as a userland wrapper backed by a remote store might.
 */
final class ThrowingFileStreamWrapper
{
    public const string PROTOCOL = 'contenir-throwing';

    public const string MESSAGE = 'The stream backend is unavailable.';

    /** @var resource|null */
    public mixed $context;

    public static function register(): void
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::PROTOCOL, stream_get_wrappers(), strict: true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    /**
     * @throws RuntimeException Always.
     *
     * @mago-expect lint:method-name PHP's stream wrapper protocol names this method.
     * @mago-expect analysis:unused-parameter PHP's stream wrapper protocol passes these arguments.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }

    /**
     * @return array<string, int>
     *
     * @mago-expect lint:method-name PHP's stream wrapper protocol names this method.
     * @mago-expect analysis:unused-parameter PHP's stream wrapper protocol passes these arguments.
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0o100_444, 'size' => 0];
    }
}
