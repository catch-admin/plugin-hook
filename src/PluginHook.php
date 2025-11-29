<?php

declare(strict_types=1);

namespace Catch\PluginHook;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Package\PackageInterface;
use Catch\PluginHook\Support\PluginPackageResolver;

/**
 * CatchAdmin 插件钩子
 * 
 * Hook 方法对应：
 * - beforeInstall   → PRE_PACKAGE_INSTALL（可阻止安装）
 * - afterInstall    → POST_AUTOLOAD_DUMP（可使用 Laravel）
 * - beforeUpdate    → PRE_PACKAGE_UPDATE（更新前）
 * - afterUpdate     → POST_AUTOLOAD_DUMP（更新后，可使用 Laravel）
 * - beforeUninstall → PRE_PACKAGE_UNINSTALL（包还在）
 * - afterUninstall  → POST_AUTOLOAD_DUMP（autoload 生成后）
 */
class PluginHook implements PluginInterface, EventSubscriberInterface
{
    protected IOInterface $io;
    protected PluginPackageResolver $resolver;
    
    /** @var array<string, array> 待执行 afterInstall 的插件 */
    protected array $pendingInstalls = [];
    
    /** @var array<string, array> 待执行 afterUpdate 的插件 */
    protected array $pendingUpdates = [];
    
    /** @var array<string, array> 待执行 afterUninstall 的插件 */
    protected array $pendingUninstalls = [];

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->io = $io;
        $this->resolver = new PluginPackageResolver();
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::PRE_PACKAGE_INSTALL => 'onPrePackageInstall',
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'onPrePackageUninstall',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'onPostPackageUninstall',
            PackageEvents::PRE_PACKAGE_UPDATE => 'onPrePackageUpdate',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageUpdate',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump',
        ];
    }

    public function onPrePackageInstall(PackageEvent $event): void
    {
        $op = $event->getOperation();
        if ($op instanceof InstallOperation) {
            $this->callHook($op->getPackage(), 'beforeInstall');
        }
    }

    public function onPostPackageInstall(PackageEvent $event): void
    {
        $op = $event->getOperation();
        if ($op instanceof InstallOperation) {
            $pluginInfo = $this->resolver->resolve($op->getPackage());
            if ($pluginInfo && isset($pluginInfo['extra']['hook'])) {
                $this->pendingInstalls[$op->getPackage()->getName()] = $pluginInfo;
            }
        }
    }

    public function onPrePackageUninstall(PackageEvent $event): void
    {
        $op = $event->getOperation();
        if ($op instanceof UninstallOperation) {
            $this->callHook($op->getPackage(), 'beforeUninstall');
            
            // 记录待执行 afterUninstall
            $pluginInfo = $this->resolver->resolve($op->getPackage());
            if ($pluginInfo && isset($pluginInfo['extra']['hook'])) {
                $this->pendingUninstalls[$op->getPackage()->getName()] = $pluginInfo;
            }
        }
    }

    public function onPostPackageUninstall(PackageEvent $event): void
    {
        // afterUninstall 在 POST_AUTOLOAD_DUMP 执行
    }

    public function onPrePackageUpdate(PackageEvent $event): void
    {
        $op = $event->getOperation();
        if ($op instanceof UpdateOperation) {
            // beforeUpdate: 与 beforeInstall 相同，只做基本检测
            // 不执行任何包中的代码（新包还没安装）
            $this->callHook($op->getTargetPackage(), 'beforeUpdate');
        }
    }

    public function onPostPackageUpdate(PackageEvent $event): void
    {
        $op = $event->getOperation();
        if ($op instanceof UpdateOperation) {
            $pluginInfo = $this->resolver->resolve($op->getTargetPackage());
            if ($pluginInfo && isset($pluginInfo['extra']['hook'])) {
                $this->pendingUpdates[$op->getTargetPackage()->getName()] = $pluginInfo;
            }
        }
    }

    /**
     * autoload 生成后执行 afterInstall/afterUpdate/afterUninstall
     */
    public function onPostAutoloadDump(Event $event): void
    {
        // 执行 afterInstall
        foreach ($this->pendingInstalls as $name => $pluginInfo) {
            $hookClass = $pluginInfo['extra']['hook'];
            if (class_exists($hookClass) && method_exists($hookClass, 'afterInstall')) {
                call_user_func([$hookClass, 'afterInstall'], $pluginInfo);
            }
        }
        $this->pendingInstalls = [];

        // 执行 afterUpdate
        foreach ($this->pendingUpdates as $name => $pluginInfo) {
            $hookClass = $pluginInfo['extra']['hook'];
            if (class_exists($hookClass) && method_exists($hookClass, 'afterUpdate')) {
                call_user_func([$hookClass, 'afterUpdate'], $pluginInfo);
            }
        }
        $this->pendingUpdates = [];

        // 执行 afterUninstall
        foreach ($this->pendingUninstalls as $name => $pluginInfo) {
            $hookClass = $pluginInfo['extra']['hook'];
            if (class_exists($hookClass) && method_exists($hookClass, 'afterUninstall')) {
                call_user_func([$hookClass, 'afterUninstall'], $pluginInfo);
            }
        }
        $this->pendingUninstalls = [];
    }

    /**
     * 调用 Hook 方法（用于 beforeInstall/beforeUpdate/beforeUninstall）
     */
    protected function callHook(PackageInterface $package, string $method): void
    {
        $pluginInfo = $this->resolver->resolve($package);
        
        if (!$pluginInfo || !isset($pluginInfo['extra']['hook'])) {
            return;
        }

        $hookClass = $pluginInfo['extra']['hook'];
        
        // 手动加载类（autoload 可能未生成）
        $this->loadHookClass($package, $hookClass);

        if (class_exists($hookClass) && method_exists($hookClass, $method)) {
            call_user_func([$hookClass, $method], $pluginInfo);
        }
    }

    /**
     * 手动加载 Hook 类
     */
    protected function loadHookClass(PackageInterface $package, string $hookClass): void
    {
        if (class_exists($hookClass)) {
            return;
        }

        $vendorDir = getcwd() . '/vendor';
        $packagePath = $vendorDir . '/' . $package->getName();

        $autoload = $package->getAutoload();
        foreach ($autoload['psr-4'] ?? [] as $namespace => $path) {
            if (str_starts_with($hookClass, $namespace)) {
                $relativePath = str_replace($namespace, '', $hookClass);
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
