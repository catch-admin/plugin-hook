<?php

declare(strict_types=1);

namespace Catch\PluginHook\Support;

use Composer\Package\PackageInterface;

/**
 * 插件包解析器 - 检测包是否包含 extra.title 和 extra.version
 */
class PluginPackageResolver
{
    public function isPlugin(PackageInterface $package): bool
    {
        $extra = $package->getExtra();

        return isset($extra['title']) && isset($extra['version']);
    }

    public function resolve(PackageInterface $package): ?array
    {
        if (!$this->isPlugin($package)) {
            return null;
        }

        $extra = $package->getExtra();

        return [
            'title' => $extra['title'],
            'version' => $package->getVersion(),  // 使用 Composer 的版本号
            'name' => $package->getName(),
            'type' => $package->getType(),
            'extra' => $extra,
            'package' => $package,
        ];
    }
}
