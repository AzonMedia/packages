<?php
declare(strict_types=1);

namespace Azonmedia\Packages;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
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
    }

    /**
     * Returns the composer.json path that is used by this instance.
     * @return string
     */
    public function get_composer_file_path() : string
    {
        return $this->composer_file_path;
    }

    /**
     * @return WritableRepositoryInterface
     */
    public function get_local_repository() : WritableRepositoryInterface
    {
        //$Composer = Factory::create(new NullIO(), $this->composer_file_path, false);
        //due to a bug in Composer where it uses the current working directory for the config
        //the cwd needs to be set to the root of the project instead of the ./app and then restored
        $cwd = getcwd();
        chdir(dirname($this->composer_file_path));
        $Composer = Factory::create(new NullIO(), $this->composer_file_path, false);
        $Repository = $Composer->getRepositoryManager()->getLocalRepository();
        chdir($cwd);
        return $Repository;
    }

    /**
     * Returns all installed packages (by using Composer and looking into ./vendor/composer/installed.json).
     * @return PackageInterface[]
     */
    public function get_installed_packages() : iterable
    {
        return $this->get_local_repository()->getPackages();
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
        $packages = $this->get_installed_packages();
        $namespace_strlen = 0;

        foreach ($packages as $Package) {
            $autoload_rules = $Package->getAutoload();
            if ($Package->getName() === 'guzaba/guzaba2') {
                print_r($autoload_rules);
            }
            if (!empty($autoload_rules['psr-4'])) {
                foreach ($autoload_rules['psr-4'] as $namespace=>$path) {
                    if (strpos($class_name, $namespace) === 0) {
                        //we need to match the deepest level thus the longest namespace
                        if (strlen($namespace) > $namespace_strlen) {
                            $namespace_strlen = strlen($namespace);
                            $ret = $Package;
                        }
                    }
                }
            }
        }
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
        if ($autoload_rules['psr-4']) {
            $ret = array_key_first($autoload_rules['psr-4']);
        }
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