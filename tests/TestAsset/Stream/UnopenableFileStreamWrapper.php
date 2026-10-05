<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Tests\TestAsset\Stream;

use function in_array;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;

/**
 * A stream wrapper whose paths stat as readable regular files but refuse to
 * open, so file_get_contents() fails after the readability checks pass.
 */
final class UnopenableFileStreamWrapper
{
    public const string PROTOCOL = 'contenir-unopenable';

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
     * @mago-expect lint:method-name PHP's stream wrapper protocol names this method.
     * @mago-expect analysis:unused-parameter PHP's stream wrapper protocol passes these arguments.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
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
