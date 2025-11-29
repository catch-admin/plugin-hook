<?php

declare(strict_types=1);

namespace Catch\PluginHook\Hooks;

/**
 * 卸载后钩子
 */
class PostUninstallHook extends AbstractHook
{
    public const NAME = 'post-uninstall';

    public function getName(): string
    {
        return self::NAME;
    }

    public function handle(array $pluginInfo): void
    {
        // 包已卸载，无法调用包中代码
    }
}
