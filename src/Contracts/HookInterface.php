<?php

declare(strict_types=1);

namespace Catch\PluginHook\Contracts;

use Composer\IO\IOInterface;

/**
 * 钩子接口
 */
interface HookInterface
{
    public function handle(array $pluginInfo): void;

    public function getName(): string;

    public function setIO(IOInterface $io): void;
}
