<?php

declare(strict_types=1);

namespace Catch\PluginHook\Hooks;

use Catch\PluginHook\Contracts\HookInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

/**
 * 抽象钩子基类
 */
abstract class AbstractHook implements HookInterface
{
    protected ?IOInterface $io = null;

    public function setIO(IOInterface $io): void
    {
        $this->io = $io;
    }

    /**
     * 手动加载 Installer 类（在 autoload 重新生成前使用）
     */
    protected function loadInstallerClass(PackageInterface $package, string $installerClass): void
    {
        if (class_exists($installerClass)) {
            return;
        }

        $vendorDir = getcwd() . '/vendor';
        $packagePath = $vendorDir . '/' . $package->getName();

        $autoload = $package->getAutoload();
        foreach ($autoload['psr-4'] ?? [] as $namespace => $path) {
            if (str_starts_with($installerClass, $namespace)) {
                $relativePath = str_replace($namespace, '', $installerClass);
                $relativePath = str_replace('\\', '/', $relativePath) . '.php';
                $filePath = $packagePath . '/' . $path . $relativePath;

                if (file_exists($filePath)) {
                    require_once $filePath;
                    return;
                }
            }
        }
    }
}
