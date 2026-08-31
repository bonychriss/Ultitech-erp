<?php
namespace Core;

class ModuleLoader {
    protected $modules = [];
    protected $basePath;

    public function __construct($basePath) {
        $this->basePath = $basePath;
    }

    public function scanAndRegister() {
        $dirs = glob($this->basePath . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $manifestPath = $dir . '/manifest.json';
            if (file_exists($manifestPath)) {
                $moduleName = basename($dir);
                $this->register($moduleName, $dir);
            }
        }
    }

    public function register($moduleName, $path) {
        $config = json_decode(file_get_contents($path . '/manifest.json'), true);
        if ($config && ($config['enabled'] ?? false)) {
            // Include boot file if exists
            if (file_exists($path . '/boot.php')) {
                require_once $path . '/boot.php';
            }
            $this->modules[$moduleName] = $config;
        }
    }

    public function getModules() {
        return $this->modules;
    }
}
