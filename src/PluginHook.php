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
use Catch\Plugin\Support\InstalledPluginManager;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/**
 * CatchAdmin 插件钩子
 *
 * 监听 Composer 事件，在 POST_AUTOLOAD_DUMP 时更新插件记录并执行 Hook
 * 插件记录存储在 config('plugin.installed_file') 指定的 JSON 文件中
 */
class PluginHook implements PluginInterface, EventSubscriberInterface
{
    private const PLUGIN_TYPE = 'catchadmin-plugin';

    protected Composer $composer;
    protected IOInterface $io;

    protected array $pendingInstalls = [];
    protected array $preloadedHooks = [];  // 预加载的 Hook 实例（用于卸载）
    protected array $pendingUpdates = [];
    protected array $pendingUninstalls = [];
    protected bool $laravelBooted = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'onPrePackageUninstall',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'onPostPackageUninstall',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageUpdate',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump',
        ];
    }

    public function onPostPackageInstall(PackageEvent $event): void
    {
        $op = $event->getOperation();
        if (!$op instanceof InstallOperation) {
            return;
        }

        $package = $op->getPackage();
        if (!$this->isCatchAdminPlugin($package)) {
            return;
        }

        $this->executeBeforeInstallHook($package);
        $this->pendingInstalls[$package->getName()] = $this->buildPluginInfo($package);
    }

    public function onPrePackageUninstall(PackageEvent $event): void
    {
        $op = $event->getOperation();
        if (!$op instanceof UninstallOperation) {
            return;
        }

        $package = $op->getPackage();
        if (!$this->isCatchAdminPlugin($package)) {
            return;
        }

        $packagePath = $this->composer->getInstallationManager()->getInstallPath($package);
        
        // 提前加载 Hook 类，因为卸载后文件将不存在
        $this->preloadHookClass($packagePath, $package->getName());
        
        $this->callHook($package, 'beforeUninstall');
        $this->pendingUninstalls[$package->getName()] = $this->buildPluginInfo($package);
    }

    public function onPostPackageUninstall(PackageEvent $event): void
    {
        // afterUninstall 在 POST_AUTOLOAD_DUMP 执行
    }

    public function onPostPackageUpdate(PackageEvent $event): void
    {
        $op = $event->getOperation();
        if (!$op instanceof UpdateOperation) {
            return;
        }

        $package = $op->getTargetPackage();
        if (!$this->isCatchAdminPlugin($package)) {
            return;
        }

        $this->callHook($package, 'beforeUpdate');
        $this->pendingUpdates[$package->getName()] = $this->buildPluginInfo($package);
    }

    /**
     * autoload 生成后执行插件记录更新和 Hook
     */
    public function onPostAutoloadDump(Event $event): void
    {
        if (!$this->hasPendingOperations()) {
            return;
        }

        $this->bootLaravel();
        $manager = new InstalledPluginManager();

        $this->processInstalls($manager);
        $this->processUpdates($manager);
        $this->processUninstalls($manager);
    }

    protected function hasPendingOperations(): bool
    {
        return !empty($this->pendingInstalls)
            || !empty($this->pendingUpdates)
            || !empty($this->pendingUninstalls);
    }

    protected function processInstalls(InstalledPluginManager $manager): void
    {
        foreach ($this->pendingInstalls as $name => $pluginInfo) {
            $manager->add([
                'name' => $name,
                'version' => $pluginInfo['version'] ?? '',
                'type' => self::PLUGIN_TYPE,
                'path' => $pluginInfo['path'] ?? '',
            ]);
            $this->invokeHookMethod($pluginInfo['path'], 'afterInstall', $pluginInfo);
        }
        $this->pendingInstalls = [];
    }

    protected function processUpdates(InstalledPluginManager $manager): void
    {
        foreach ($this->pendingUpdates as $name => $pluginInfo) {
            $manager->update($name, ['version' => $pluginInfo['version'] ?? '']);
            $this->invokeHookMethod($pluginInfo['path'], 'afterUpdate', $pluginInfo);
        }
        $this->pendingUpdates = [];
    }

    protected function processUninstalls(InstalledPluginManager $manager): void
    {
        foreach ($this->pendingUninstalls as $name => $pluginInfo) {
            $this->invokeHookMethod($pluginInfo['path'], 'afterUninstall', $pluginInfo, $name);
            $manager->remove($name);
        }
        $this->pendingUninstalls = [];
    }

    /**
     * 调用 Hook 方法（约定：包根目录下的 hook.php，类名 Hook）
     */
    protected function invokeHookMethod(string $packagePath, string $method, array $context, ?string $packageName = null): void
    {
        // 优先使用预加载的 Hook 实例（用于卸载场景）
        if ($packageName && isset($this->preloadedHooks[$packageName])) {
            $instance = $this->preloadedHooks[$packageName];
            if (method_exists($instance, $method)) {
                $instance->$method($context);
            }
            return;
        }

        $hookFile = $packagePath . '/hook.php';
        if (!file_exists($hookFile)) {
            return;
        }

        if (!class_exists('Hook', false)) {
            require_once $hookFile;
        }

        if (class_exists('Hook', false)) {
            $instance = new \Hook();
            if (method_exists($instance, $method)) {
                $instance->$method($context);
            }
        }
    }

    /**
     * 预加载 Hook 类（用于卸载前）
     */
    protected function preloadHookClass(string $packagePath, string $packageName): void
    {
        $hookFile = $packagePath . '/hook.php';
        if (!file_exists($hookFile)) {
            return;
        }

        // 直接 require 并创建实例（同一时间只卸载一个插件，不会冲突）
        if (!class_exists('Hook', false)) {
            require_once $hookFile;
        }
        
        if (class_exists('Hook', false)) {
            $this->preloadedHooks[$packageName] = new \Hook();
        }
    }

    protected function isCatchAdminPlugin(PackageInterface $package): bool
    {
        return $package->getType() === self::PLUGIN_TYPE;
    }

    protected function buildPluginInfo(PackageInterface $package): array
    {
        $autoload = $package->getAutoload();
        $namespace = !empty($autoload['psr-4']) ? rtrim(array_key_first($autoload['psr-4']), '\\') : null;

        return [
            'name' => $package->getName(),
            'version' => $package->getVersion(),
            'path' => $this->composer->getInstallationManager()->getInstallPath($package),
            'namespace' => $namespace,
        ];
    }

    protected function executeBeforeInstallHook(PackageInterface $package): void
    {
        try {
            $this->callHook($package, 'beforeInstall');
        } catch (\Throwable $e) {
            $packageName = $package->getName();
            $this->io->writeError('');
            $this->io->writeError("<error>插件安装验证失败: {$e->getMessage()}</error>");
            $this->io->writeError("<warning>请运行以下命令清理: composer remove {$packageName}</warning>");
            $this->io->writeError('');
            throw $e;
        }
    }

    /**
     * 调用 Hook 方法（用于 before* Hook）
     */
    protected function callHook(PackageInterface $package, string $method): void
    {
        $this->bootLaravel();
        $packagePath = $this->composer->getInstallationManager()->getInstallPath($package);
        $this->invokeHookMethod($packagePath, $method, $this->buildPluginInfo($package));
    }

    /**
     * 启动 Laravel 应用，确保 after* Hook 可以使用 Laravel 功能
     */
    protected function bootLaravel(): void
    {
        if ($this->laravelBooted) {
            return;
        }

        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $basePath = dirname($vendorDir);

        if (!$this->loadAutoload($vendorDir) || !$this->bootstrapLaravel($basePath)) {
            return;
        }

        $this->laravelBooted = true;
    }

    protected function loadAutoload(string $vendorDir): bool
    {
        $autoloadFile = $vendorDir . '/autoload.php';
        if (!file_exists($autoloadFile)) {
            return false;
        }
        require $autoloadFile;
        return true;
    }

    protected function bootstrapLaravel(string $basePath): bool
    {
        $bootstrapFile = $basePath . '/bootstrap/app.php';

        if (!file_exists($bootstrapFile)) {
            $this->io->writeError('<warning>无法找到 Laravel bootstrap 文件</warning>');
            return false;
        }

        try {
            if (!defined('LARAVEL_START')) {
                define('LARAVEL_START', microtime(true));
            }

            $app = require $bootstrapFile;
            $app->make(ConsoleKernel::class)->bootstrap();
            return true;
        } catch (\Throwable $e) {
            $this->io->writeError('<warning>Laravel 启动失败: ' . $e->getMessage() . '</warning>');
            return false;
        }
    }
}
