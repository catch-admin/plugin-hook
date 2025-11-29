# CatchAdmin 插件 Hook 系统

Composer 插件生命周期钩子系统，允许插件在安装、更新、卸载时执行自定义逻辑。

## 快速开始

### 1. 配置 composer.json

在插件的 `composer.json` 中添加 `extra` 配置：

```json
{
    "name": "vendor/my-plugin",
    "version": "1.0.0",
    "extra": {
        "title": "我的插件",
        "version": "1.0.0",
        "hook": "Vendor\\MyPlugin\\Hook"
    }
}
```

| 字段 | 必填 | 说明 |
|------|------|------|
| `extra.title` | ✅ | 插件标题（用于日志显示） |
| `extra.version` | ✅ | 插件版本标记（标识为 CatchAdmin 插件） |
| `extra.hook` | ❌ | Hook 类的完整命名空间（不设置则不执行钩子） |

### 2. 创建 Hook 类

在插件的 `src/Hook.php` 中创建 Hook 类：

```php
<?php

namespace Vendor\MyPlugin;

class Hook
{
    /**
     * 安装前执行
     * 
     * 触发时机：PRE_PACKAGE_INSTALL
     * 可用功能：仅 PHP 原生函数（Laravel 尚未加载）
     * 用途：环境检测、阻止安装（抛出异常）
     */
    public static function beforeInstall(array $pluginInfo): void
    {
        // 检查 PHP 版本
        if (version_compare(PHP_VERSION, '8.2', '<')) {
            throw new \RuntimeException('需要 PHP 8.2+');
        }
    }

    /**
     * 安装后执行
     * 
     * 触发时机：POST_AUTOLOAD_DUMP（autoload 生成后）
     * 可用功能：Laravel 完整功能
     * 用途：数据库迁移、发布配置、初始化数据
     */
    public static function afterInstall(array $pluginInfo): void
    {
        // 运行数据库迁移
        \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--path' => 'vendor/vendor/my-plugin/database/migrations',
            '--force' => true,
        ]);
        
        // 发布配置文件
        \Illuminate\Support\Facades\Artisan::call('vendor:publish', [
            '--tag' => 'my-plugin-config',
        ]);
    }

    /**
     * 更新前执行
     * 
     * 触发时机：PRE_PACKAGE_UPDATE
     * 可用功能：Laravel 完整功能（旧版本代码）
     * 用途：备份数据、版本兼容性检查
     */
    public static function beforeUpdate(array $pluginInfo): void
    {
        // 备份重要数据
        \Illuminate\Support\Facades\DB::table('my_plugin_data')
            ->get()
            ->toJson();
    }

    /**
     * 更新后执行
     * 
     * 触发时机：POST_AUTOLOAD_DUMP（autoload 生成后）
     * 可用功能：Laravel 完整功能（新版本代码）
     * 用途：运行新版本迁移、更新配置
     */
    public static function afterUpdate(array $pluginInfo): void
    {
        // 运行新版本的迁移
        \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--path' => 'vendor/vendor/my-plugin/database/migrations',
            '--force' => true,
        ]);
    }

    /**
     * 卸载前执行
     * 
     * 触发时机：PRE_PACKAGE_UNINSTALL
     * 可用功能：Laravel 完整功能
     * 用途：备份数据、清理缓存、确认卸载
     */
    public static function beforeUninstall(array $pluginInfo): void
    {
        // 清理配置缓存
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        
        // 备份用户数据
        $data = \Illuminate\Support\Facades\DB::table('my_plugin_users')->get();
        file_put_contents(storage_path('my-plugin-backup.json'), $data->toJson());
    }

    /**
     * 卸载后执行
     * 
     * 触发时机：POST_AUTOLOAD_DUMP
     * 可用功能：有限（包已卸载，Hook 类可能不存在）
     * 用途：清理残留文件
     */
    public static function afterUninstall(array $pluginInfo): void
    {
        // 清理发布的配置文件
        @unlink(config_path('my-plugin.php'));
    }
}
```

## 钩子详解

### 执行时序

```
┌─────────────────────────────────────────────────────────────────┐
│                         安装流程                                 │
├─────────────────────────────────────────────────────────────────┤
│  PRE_PACKAGE_INSTALL  →  beforeInstall()                        │
│          ↓                                                      │
│  下载并解压包到 vendor/                                          │
│          ↓                                                      │
│  POST_PACKAGE_INSTALL →  记录待执行                              │
│          ↓                                                      │
│  重新生成 autoload.php                                          │
│          ↓                                                      │
│  POST_AUTOLOAD_DUMP   →  afterInstall()  ✅ 可用 Laravel        │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                         更新流程                                 │
├─────────────────────────────────────────────────────────────────┤
│  PRE_PACKAGE_UPDATE   →  beforeUpdate()  ✅ 可用 Laravel        │
│          ↓                                                      │
│  替换包文件                                                      │
│          ↓                                                      │
│  POST_PACKAGE_UPDATE  →  记录待执行                              │
│          ↓                                                      │
│  重新生成 autoload.php                                          │
│          ↓                                                      │
│  POST_AUTOLOAD_DUMP   →  afterUpdate()   ✅ 可用 Laravel        │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                         卸载流程                                 │
├─────────────────────────────────────────────────────────────────┤
│  PRE_PACKAGE_UNINSTALL →  beforeUninstall()  ✅ 可用 Laravel    │
│          ↓                                                      │
│  删除包文件                                                      │
│          ↓                                                      │
│  POST_PACKAGE_UNINSTALL → 记录待执行                             │
│          ↓                                                      │
│  重新生成 autoload.php                                          │
│          ↓                                                      │
│  POST_AUTOLOAD_DUMP    →  afterUninstall()                      │
└─────────────────────────────────────────────────────────────────┘
```

### pluginInfo 参数

所有钩子方法都接收 `$pluginInfo` 数组：

```php
[
    'title' => '我的插件',           // extra.title
    'version' => '1.0.0.0',         // Composer 版本号（标准化）
    'name' => 'vendor/my-plugin',   // 包名
    'type' => 'library',            // 包类型
    'extra' => [                    // 完整的 extra 字段
        'title' => '我的插件',
        'version' => '1.0.0',
        'hook' => 'Vendor\\MyPlugin\\Hook',
    ],
    'package' => PackageInterface,  // Composer 包对象
]
```

## 使用场景

### 场景 1：数据库迁移

```php
public static function afterInstall(array $pluginInfo): void
{
    Artisan::call('migrate', [
        '--path' => "vendor/{$pluginInfo['name']}/database/migrations",
        '--force' => true,
    ]);
}

public static function beforeUninstall(array $pluginInfo): void
{
    Artisan::call('migrate:rollback', [
        '--path' => "vendor/{$pluginInfo['name']}/database/migrations",
        '--force' => true,
    ]);
}
```

### 场景 2：发布配置和资源

```php
public static function afterInstall(array $pluginInfo): void
{
    // 发布配置
    Artisan::call('vendor:publish', [
        '--tag' => 'my-plugin-config',
        '--force' => true,
    ]);
    
    // 发布前端资源
    Artisan::call('vendor:publish', [
        '--tag' => 'my-plugin-assets',
        '--force' => true,
    ]);
}
```

### 场景 3：注册菜单

```php
public static function afterInstall(array $pluginInfo): void
{
    DB::table('menus')->insert([
        'name' => '我的插件',
        'route' => '/my-plugin',
        'icon' => 'plugin',
        'sort' => 100,
    ]);
}

public static function beforeUninstall(array $pluginInfo): void
{
    DB::table('menus')->where('route', '/my-plugin')->delete();
}
```

### 场景 4：初始化数据

```php
public static function afterInstall(array $pluginInfo): void
{
    // 运行 Seeder
    Artisan::call('db:seed', [
        '--class' => 'Vendor\\MyPlugin\\Database\\Seeders\\PluginSeeder',
    ]);
}
```

### 场景 5：版本升级处理

```php
public static function afterUpdate(array $pluginInfo): void
{
    $currentVersion = $pluginInfo['version'];
    
    // 从 1.0.x 升级到 1.1.x 的特殊处理
    if (version_compare($currentVersion, '1.1.0', '>=')) {
        // 数据结构调整
        DB::statement('ALTER TABLE my_plugin_table ADD COLUMN new_field VARCHAR(255)');
    }
}
```

### 场景 6：清理缓存

```php
public static function afterInstall(array $pluginInfo): void
{
    Artisan::call('config:clear');
    Artisan::call('route:clear');
    Artisan::call('cache:clear');
}

public static function afterUpdate(array $pluginInfo): void
{
    Artisan::call('config:clear');
    Artisan::call('view:clear');
}
```

### 场景 7：环境检测

```php
public static function beforeInstall(array $pluginInfo): void
{
    // 检查 PHP 版本
    if (version_compare(PHP_VERSION, '8.2', '<')) {
        throw new \RuntimeException("插件需要 PHP 8.2+，当前版本: " . PHP_VERSION);
    }
    
    // 检查扩展
    if (!extension_loaded('redis')) {
        throw new \RuntimeException("插件需要 Redis 扩展");
    }
}
```

## 注意事项

1. **beforeInstall** 和 **beforeUpdate** 中的限制
   - 此时 autoload 尚未重新生成
   - 不能使用 Laravel 的 Facade（如 `Artisan`、`DB`）
   - 只能使用 PHP 原生函数

2. **afterUninstall** 的限制
   - 包已被删除，Hook 类可能不存在
   - 建议只做简单的文件清理操作

3. **阻止安装/更新/卸载**
   - 在 `before*` 方法中抛出异常即可阻止操作
   ```php
   throw new \RuntimeException('不满足安装条件');
   ```

4. **版本号格式**
   - `pluginInfo['version']` 是 Composer 标准化版本号
   - 例如：`1.0.0` 会变成 `1.0.0.0`

## 调试

运行 Composer 命令时添加 `-vvv` 查看详细日志：

```bash
composer require vendor/my-plugin -vvv
```
