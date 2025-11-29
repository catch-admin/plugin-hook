<?php

declare(strict_types=1);

namespace Catch\PluginHook\Hooks;

/**
 * 卸载前钩子 - 调用插件的 Installer::preUninstall()
 */
class PreUninstallHook extends AbstractHook
{
    public const NAME = 'pre-uninstall';
    public const INSTALLER_METHOD = 'preUninstall';

    public function getName(): string
    {
        return self::NAME;
    }

    public function handle(array $pluginInfo): void
    {
        $installerClass = $pluginInfo['extra']['installer'] ?? null;

        if (!$installerClass) {
            return;
        }

        $this->loadInstallerClass($pluginInfo['package'], $installerClass);

        if (class_exists($installerClass) && method_exists($installerClass, self::INSTALLER_METHOD)) {
            call_user_func([$installerClass, self::INSTALLER_METHOD], $pluginInfo);
        }
    }
}
