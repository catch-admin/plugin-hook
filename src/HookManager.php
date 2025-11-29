<?php

declare(strict_types=1);

namespace Catch\PluginHook;

use Catch\PluginHook\Contracts\HookInterface;
use Catch\PluginHook\Hooks\PreInstallHook;
use Catch\PluginHook\Hooks\PostInstallHook;
use Catch\PluginHook\Hooks\PreUninstallHook;
use Catch\PluginHook\Hooks\PostUninstallHook;
use Catch\PluginHook\Support\PluginPackageResolver;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

/**
 * 钩子管理器
 */
class HookManager
{
    protected IOInterface $io;
    protected PluginPackageResolver $resolver;
    protected array $hooks = [];

    public function __construct(IOInterface $io)
    {
        $this->io = $io;
        $this->resolver = new PluginPackageResolver();
        $this->registerDefaultHooks();
    }

    protected function registerDefaultHooks(): void
    {
        $this->register(new PreInstallHook());
        $this->register(new PostInstallHook());
        $this->register(new PreUninstallHook());
        $this->register(new PostUninstallHook());
    }

    public function register(HookInterface $hook): self
    {
        $hook->setIO($this->io);
        $this->hooks[$hook->getName()] = $hook;

        return $this;
    }

    public function trigger(string $hookName, PackageInterface $package): bool
    {
        $pluginInfo = $this->resolver->resolve($package);

        if ($pluginInfo === null || !isset($this->hooks[$hookName])) {
            return false;
        }

        $this->hooks[$hookName]->handle($pluginInfo);

        return true;
    }

    public function triggerPreInstall(PackageInterface $package): bool
    {
        return $this->trigger(PreInstallHook::NAME, $package);
    }

    public function triggerPostInstall(PackageInterface $package): bool
    {
        return $this->trigger(PostInstallHook::NAME, $package);
    }

    public function triggerPreUninstall(PackageInterface $package): bool
    {
        return $this->trigger(PreUninstallHook::NAME, $package);
    }

    public function triggerPostUninstall(PackageInterface $package): bool
    {
        return $this->trigger(PostUninstallHook::NAME, $package);
    }
}
