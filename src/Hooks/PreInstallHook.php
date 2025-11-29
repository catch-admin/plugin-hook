<?php

declare(strict_types=1);

namespace Catch\PluginHook\Hooks;

/**
 * 安装前钩子
 */
class PreInstallHook extends AbstractHook
{
    public const NAME = 'pre-install';

    public function getName(): string
    {
        return self::NAME;
    }

    public function handle(array $pluginInfo): void
    {
        // 可用于验证、阻止安装等，抛出异常即可阻止
    }
}
