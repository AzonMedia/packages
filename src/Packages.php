<?php
declare(strict_types=1);

namespace Azonmedia\Packages;

use Composer\Composer;
use Composer\Factory;
use Composer\Installer\InstallationManager;
use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableRepositoryInterface;

/**
 * Class Packages
 * @package Azonmedia\Packages
 * Supports handling only Psr-4 packages
 */
class Packages
{

    private string $composer_file_path;

    /**
     * Packages constructor.
     * @param string $composer_file_path
     */
    public function __construct(string $composer_file_path)
    {
        if (!$composer_file_path) {
            throw new \InvalidArgumentException(sprintf('No path to composer.json is provided.'));
        }
        if (!file_exists($composer_file_path)) {
            throw new \InvalidArgumentException(sprintf('The provided path %s does not exist.', $composer_file_path));
        }
        if (!is_readable($composer_file_path)) {
            throw new \InvalidArgumentException(sprintf('The provided path %s is not readable.', $composer_file_path));
        }
        $this->composer_file_path = $composer_file_path;
        $this->check_home_dir();


    }

    private function check_home_dir(): void
    {
        $home_dir = getenv('HOME');
        if (!$home_dir) {
            //$user = `whoami`;
            //$home_dir = $user === 'root' ? '/root' : '/home/'.$user;
            $home_dir = dirname($this->composer_file_path);//create the cache in the project dir as the home dir may not exist (for user www-data may not have home dir)
            putenv("HOME={$home_dir}");
        }
    }

    /**
     * Returns the composer.json path that is used by this instance.
     * @return string
     */
    public function get_composer_file_path() : string
    {
        return $this->composer_file_path;
    }

    public function get_composer() : Composer
    {
        //$Composer = Factory::create(new NullIO(), $this->composer_file_path, false);
        //due to a bug in Composer where it uses the current working directory for the config
        //the cwd needs to be set to the root of the project instead of the ./app and then restored
        $cwd = getcwd();
        chdir(dirname($this->composer_file_path));
        try {
            $Composer = Factory::create(new NullIO(), $this->composer_file_path, false);
        } finally {
            chdir($cwd);
        }
        return $Composer;
    }

    public function get_installation_manager(): InstallationManager
    {
        return $this->get_composer()->getInstallationManager();
    }

    public function get_repository_manager() : RepositoryManager
    {
        return $this->get_composer()->getRepositoryManager();
    }

    /**
     * @return WritableRepositoryInterface
     */
    public function get_local_repository() : WritableRepositoryInterface
    {
        return $this->get_repository_manager()->getLocalRepository();
    }

    /**
     * Returns all installed packages (by using Composer and looking into ./vendor/composer/installed.json).
     * @return PackageInterface[]
     */
    public function get_installed_packages() : iterable
    {
        return $this->get_local_repository()->getPackages();
    }

    public function get_package_installation_path(PackageInterface $Package) : ?string
    {
        $ret = NULL;

        static $ret_cache = [];
        if (array_key_exists($Package->getName(), $ret_cache)) {
            return $ret_cache[$Package->getName()];
        }

        $packages = $this->get_installed_packages();
        $InstallationManager = $this->get_installation_manager();
        foreach ($packages as $InstalledPackage) {
            if ($InstalledPackage->getName() === $Package->getName()) {
                $ret = $InstallationManager->getInstallPath($Package);
                break;
            }
        }

        $ret_cache[$Package->getName()] = $ret;

        return $ret;
    }

    /**
     * Returns the path to the source code. Returns relative path.
     * Works only on packages using psr-4 autoloader and supports only a single namespace/path combination.
     * @param PackageInterface $Package
     * @return string|null
     */
    public static function get_package_src_path(PackageInterface $Package) : ?string
    {
        $ret = NULL;
        $autoload_rules = $Package->getAutoload();
        if (!empty($autoload_rules['psr-4'])) {
            foreach ($autoload_rules['psr-4'] as $namespace=>$path) {
                $ret = $path;
                break;
            }
        }
        return $ret;
    }

    /**
     * Returns a PackageInterface to which the provided $class_name belongs.
     * If no package is found NULL is returned.
     * @param string $class_name
     * @return PackageInterface|null
     */
    public function get_package_by_class(string $class_name) : ?PackageInterface
    {
        $ret = NULL;

        //cache these as the number of files can be quite large
        //caching these in swoole context is not a problem as the classes & packages dont change during execution
        static $ret_cache = [];
        if (array_key_exists($class_name, $ret_cache)) {
            return $ret_cache[$class_name];
        }

        $packages = $this->get_installed_packages();
        $namespace_strlen = 0;

        foreach ($packages as $Package) {
            $autoload_rules = $Package->getAutoload();
            if (!empty($autoload_rules['psr-4'])) {
                foreach ($autoload_rules['psr-4'] as $namespace=>$path) {
                    if (strpos($class_name, $namespace) === 0) {
                        //we need to match the deepest level thus the longest namespace
                        if (strlen($namespace) > $namespace_strlen) {
                            $namespace_strlen = strlen($namespace);
                            $ret = $Package;
                            //do not break here - keep iterating until the deepest namespace match is found
                        }
                    }
                }
            }
        }

        //if nothing was found in the psr-4 autoloaders look for the other autoloaders
        //isolate the first namespace part
        $ns_1 = explode('\\', $class_name)[0];
        //and look for it...
        foreach ($packages as $Package) {
            $autoload_rules = $Package->getAutoload();
            if (!empty($autoload_rules['files'])) {
                foreach ($autoload_rules['files'] as $file) {
                    if (stripos($file, $ns_1) !== false) {
                        $ret = $Package;
                        break;
                    }
                }
            }
        }

        $ret_cache[$class_name] = $ret;

        return $ret;
    }

    /**
     * Returns the psr-4 namespace of the package.
     * Supports only psr-4 packages and returns the first namespace.
     * @param PackageInterface $Package
     * @return string
     */
    public static function get_package_namespace(PackageInterface $Package) : string
    {
        $ret = '';
        $autoload_rules = $Package->getAutoload();
        if (!empty($autoload_rules['psr-4'])) {
            $ret = array_key_first($autoload_rules['psr-4']);
        }
        //there is no reliable way to use the 'files'
//        if (!$ret) {
//            if (!empty($autoload_rules['files'])) {
//
//            }
//        }
        return $ret;
    }

    /**
     * Returns the topmost composer.json file starting from the current directory.
     * @return string
     */
    public static function get_application_composer_file_path() : string
    {
        $ret = '';
        $path = __DIR__;
        do {
            $file = $path.'/composer.json';
            if (file_exists($file) && is_readable($file)) {
                $ret = $file;
                //do not stop at the first found as this is probably the composer.json of this or another package
                //continue until the top most composer.json is found.
            }
            $path = dirname($path);
        } while($path !== '/');
        return $ret;
    }
}