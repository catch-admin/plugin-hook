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

/**
 * CatchAdmin 插件钩子
 * 
 * 监听 Composer 事件，在 POST_AUTOLOAD_DUMP 时更新插件记录并执行 Hook
 * 插件记录存储在 config('plugin.installed_file') 指定的 JSON 文件中
 */
class PluginHook implements PluginInterface, EventSubscriberInterface
{
    protected Composer $composer;
    protected IOInterface $io;
    
    /** @var array<string, array> 待记录的安装插件 */
    protected array $pendingInstalls = [];
    
    /** @var array<string, array> 待更新的插件 */
    protected array $pendingUpdates = [];
    
    /** @var array<string, array> 待删除的插件 */
    protected array $pendingUninstalls = [];

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
        if ($op instanceof InstallOperation) {
            $package = $op->getPackage();
            
            if ($package->getType() !== 'catchadmin-plugin') {
                return;
            }

            $packageName = $package->getName();
            $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
            
            // 执行 beforeInstall Hook（包已下载，可加载）
            try {
                $this->callHook($package, 'beforeInstall');
            } catch (\Throwable $e) {
                // 验证失败，提示用户手动清理
                $this->io->writeError("");
                $this->io->writeError("<error>插件安装验证失败: {$e->getMessage()}</error>");
                $this->io->writeError("<warning>请运行以下命令清理: composer remove {$packageName}</warning>");
                $this->io->writeError("");
                throw $e;
            }
            
            $this->pendingInstalls[$packageName] = [
                'name' => $packageName,
                'version' => $package->getVersion(),
                'path' => $installPath,
                'hook' => $package->getExtra()['hook'] ?? null,
            ];
        }
    }

    public function onPrePackageUninstall(PackageEvent $event): void
    {
        $op = $event->getOperation();
        if ($op instanceof UninstallOperation) {
            $package = $op->getPackage();
            
            if ($package->getType() !== 'catchadmin-plugin') {
                return;
            }

            $packageName = $package->getName();
            $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
            
            // 执行 beforeUninstall Hook（包还在）
            $hookClass = $package->getExtra()['hook'] ?? null;
            if ($hookClass) {
                $this->loadHookClass($package, $hookClass);
                if (class_exists($hookClass) && method_exists($hookClass, 'beforeUninstall')) {
                    call_user_func([$hookClass, 'beforeUninstall'], [
                        'name' => $packageName,
                        'path' => $installPath,
                    ]);
                }
            }
            
            $this->pendingUninstalls[$packageName] = [
                'name' => $packageName,
                'hook' => $hookClass,
            ];
        }
    }

    public function onPostPackageUninstall(PackageEvent $event): void
    {
        // afterUninstall 在 POST_AUTOLOAD_DUMP 执行
    }

    public function onPostPackageUpdate(PackageEvent $event): void
    {
        $op = $event->getOperation();
        if ($op instanceof UpdateOperation) {
            $package = $op->getTargetPackage();
            
            if ($package->getType() !== 'catchadmin-plugin') {
                return;
            }

            $packageName = $package->getName();
            $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
            
            // 执行 beforeUpdate Hook（包已下载，可加载）
            $this->callHook($package, 'beforeUpdate');
            
            $this->pendingUpdates[$packageName] = [
                'name' => $packageName,
                'version' => $package->getVersion(),
                'path' => $installPath,
                'hook' => $package->getExtra()['hook'] ?? null,
            ];
        }
    }

    /**
     * autoload 生成后执行插件记录更新和 Hook
     */
    public function onPostAutoloadDump(Event $event): void
    {
        $manager = new InstalledPluginManager();

        // 处理安装的插件
        foreach ($this->pendingInstalls as $name => $pluginInfo) {
            $manager->add([
                'name' => $name,
                'version' => $pluginInfo['version'] ?? '',
                'type' => 'catchadmin-plugin',
                'path' => $pluginInfo['path'] ?? '',
            ]);

            // 执行 afterInstall Hook
            $hookClass = $pluginInfo['hook'] ?? null;
            if ($hookClass && class_exists($hookClass) && method_exists($hookClass, 'afterInstall')) {
                call_user_func([$hookClass, 'afterInstall'], $pluginInfo);
            }
        }
        $this->pendingInstalls = [];

        // 处理更新的插件
        foreach ($this->pendingUpdates as $name => $pluginInfo) {
            $manager->update($name, [
                'version' => $pluginInfo['version'] ?? '',
            ]);

            // 执行 afterUpdate Hook
            $hookClass = $pluginInfo['hook'] ?? null;
            if ($hookClass && class_exists($hookClass) && method_exists($hookClass, 'afterUpdate')) {
                call_user_func([$hookClass, 'afterUpdate'], $pluginInfo);
            }
        }
        $this->pendingUpdates = [];

        // 处理卸载的插件
        foreach ($this->pendingUninstalls as $name => $pluginInfo) {
            // 执行 afterUninstall Hook
            $hookClass = $pluginInfo['hook'] ?? null;
            if ($hookClass && class_exists($hookClass) && method_exists($hookClass, 'afterUninstall')) {
                call_user_func([$hookClass, 'afterUninstall'], $pluginInfo);
            }

            $manager->remove($name);
        }
        $this->pendingUninstalls = [];
    }

    /**
     * 调用 Hook 方法（用于 beforeInstall/beforeUpdate，此时 autoload 未生成）
     */
    protected function callHook(PackageInterface $package, string $method): void
    {
        $hookClass = $package->getExtra()['hook'] ?? null;
        if (!$hookClass) {
            return;
        }

        $this->loadHookClass($package, $hookClass);

        if (class_exists($hookClass) && method_exists($hookClass, $method)) {
            call_user_func([$hookClass, $method], [
                'name' => $package->getName(),
                'version' => $package->getVersion(),
                'path' => $this->composer->getInstallationManager()->getInstallPath($package),
            ]);
        }
    }

    /**
     * 手动加载 Hook 类（autoload 未生成时使用）
     */
    protected function loadHookClass(PackageInterface $package, string $hookClass): void
    {
        if (class_exists($hookClass)) {
            return;
        }

        // 使用实际安装路径（支持 symlink/junction）
        $packagePath = $this->composer->getInstallationManager()->getInstallPath($package);

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
