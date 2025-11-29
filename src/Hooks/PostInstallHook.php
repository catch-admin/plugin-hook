<?php

declare(strict_types=1);

namespace Catch\PluginHook\Hooks;

/**
 * 安装后钩子 - 调用插件的 Installer::postInstall()
 */
class PostInstallHook extends AbstractHook
{
    public const NAME = 'post-install';
    public const INSTALLER_METHOD = 'postInstall';

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
