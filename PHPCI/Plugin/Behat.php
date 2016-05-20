<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Plugin;

use PHPCI\Builder;
use PHPCI\Helper\Lang;
use PHPCI\Model\Build;
use PHPCI\Model\BuildError;

/**
 * Behat BDD Plugin
 * @author       Dan Cryer <dan@block8.co.uk>
 * @package      PHPCI
 * @subpackage   Plugins
 */
class Behat implements \PHPCI\Plugin
{
    protected $phpci;
    protected $build;
    protected $features;

    /**
     * @var string|string[] $ymlConfigFile The path (or array of paths) of an yml config for Behat
     */
    protected $ymlConfigFile;

    /**
     * Try and find the phpunit YML config file.
     * @param $buildPath
     * @return null|string
     */
    public static function findConfigFile($buildPath)
    {
        if (file_exists($buildPath . 'behat.yml')) {
            return 'behat.yml';
        }

        if (file_exists($buildPath . 'tests' . DIRECTORY_SEPARATOR . 'behat.yml')) {
            return 'tests' . DIRECTORY_SEPARATOR . 'behat.yml';
        }

        if (file_exists($buildPath . 'behat.yml.dist')) {
            return 'behat.yml.dist';
        }

        if (file_exists($buildPath . 'tests/behat.yml.dist')) {
            return 'tests' . DIRECTORY_SEPARATOR . 'behat.yml.dist';
        }

        return null;
    }

    /**
     * Standard Constructor
     *
     * $options['directory'] Output Directory. Default: %BUILDPATH%
     * $options['filename']  Phar Filename. Default: build.phar
     * $options['regexp']    Regular Expression Filename Capture. Default: /\.php$/
     * $options['stub']      Stub Content. No Default Value
     *
     * @param Builder $phpci
     * @param Build   $build
     * @param array   $options
     */
    public function __construct(Builder $phpci, Build $build, array $options = array())
    {
        $this->phpci    = $phpci;
        $this->build    = $build;
        $this->features = '';

        if (isset($options['executable'])) {
            $this->executable = $options['executable'];
        } else {
            $this->executable = $this->phpci->findBinary('behat');
        }

        if (isset($options['config'])) {
            $this->ymlConfigFile = $options['config'];
        }

        if (!empty($options['features'])) {
            $this->features = $options['features'];
        }
    }

    /**
     * Runs Behat tests.
     */
    public function execute()
    {
        $curdir = getcwd();
        chdir($this->phpci->buildPath);

        $behat = $this->executable;

        if (!$behat) {
            $this->phpci->logFailure(Lang::get('could_not_find', 'behat'));

            return false;
        }

        // Run any config files first. This can be either a single value or an array.
        if ($this->ymlConfigFile !== null) {
            $success = $this->runConfigFile($this->ymlConfigFile);
        } else {
            $success = $this->phpci->executeCommand($behat . ' %s', $this->features);
            chdir($curdir);
        }

        list($errorCount, $data) = $this->parseBehatOutput();

        $this->build->storeMeta('behat-warnings', $errorCount);
        $this->build->storeMeta('behat-data', $data);

        return $success;
    }

    /**
     * Parse the behat output and return details on failures
     *
     * @return array
     */
    public function parseBehatOutput()
    {
        $output = $this->phpci->getLastOutput();

        $parts = explode('---', $output);

        if (count($parts) <= 1) {
            return array(0, array());
        }

        $lines = explode(PHP_EOL, $parts[1]);

        $storeFailures = false;
        $data = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line == 'Failed scenarios:') {
                $storeFailures = true;
                continue;
            }

            if (strpos($line, ':') === false) {
                $storeFailures = false;
            }

            if ($storeFailures) {
                $lineParts = explode(':', $line);
                $data[] = array(
                    'file' => $lineParts[0],
                    'line' => $lineParts[1]
                );

                $this->build->reportError(
                    $this->phpci,
                    'behat',
                    'Behat scenario failed.',
                    BuildError::SEVERITY_HIGH,
                    $lineParts[0],
                    $lineParts[1]
                );
            }
        }

        $errorCount = count($data);

        return array($errorCount, $data);
    }

    /**
     * Run the tests defined in a Behat config file.
     * @param $configPath
     * @return bool|mixed
     */
    protected function runConfigFile($configPath)
    {
        if (is_array($configPath)) {
            return $this->recurseArg($configPath, array($this, "runConfigFile"));
        } else {
            $behat = $this->executable;

            $cmd = $behat . ' --config ' . $configPath . ' %s';
            $success = $this->phpci->executeCommand($cmd, $this->features);

            return $success;
        }
    }

    /**
     * @param $array
     * @param $callable
     * @return bool|mixed
     */
    protected function recurseArg($array, $callable)
    {
        $success = true;
        foreach ($array as $subItem) {
            $success &= call_user_func($callable, $subItem);
        }
        return $success;
    }
}
