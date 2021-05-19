<?php
// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

/**
 * C3 - Codeception Code Coverage
 *
 * @author tiger
 */

namespace Codeception;

use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter as CodeCoverageFilter;
use Codeception\Configuration;
use PHP_CodeCoverage;

// $_SERVER['HTTP_X_CODECEPTION_CODECOVERAGE_DEBUG'] = 1;

class C3 {

    /**
     * @var string
     */
    private $configDir;

    /**
     * @var string
     */
    private $autoloadRootDir;

    /**
     * @var string
     */
    private $errorLogFile;

    /**
     * @var string
     */
    private $tempDir;

    /**
     * @param string $configDir where to look for codeception.yml and codeception.dist.yml
     */
    public function __construct($configDir)
    {
        $this->configDir = realpath($configDir);
        $this->autoloadRootDir = $this->configDir;
    }

    /**
     * @param string $dir directory from where to attempt autoloading Codeception
     */
    public function setAutoloadRootDir($dir)
    {
        $this->autoloadRootDir = $dir;
    }

    /**
     * @param string $file
     */
    public function setErrorLogFile($file)
    {
        $this->errorLogFile = $file;
    }

    /**
     * Run Codeception C3 Coverage collection.
     *
     * @throws Exception\ConfigurationException
     */
    public function run()
    {
        $this->convertCookiesToHttpXHeaders();
        if (!array_key_exists('HTTP_X_CODECEPTION_CODECOVERAGE', $_SERVER)) {
            return;
        }

        $this->autoloadCodeception();
        $this->registerPhpUnitCompatibility();
        $this->initCodeception();

        $path = realpath($this->tempDir) . DIRECTORY_SEPARATOR . 'codecoverage';
        $completeReport = $currentReport = $path . '.serialized';
        $requestedC3Report = (strpos($_SERVER['REQUEST_URI'], 'c3/report') !== false);
        if ($requestedC3Report) {
            $this->handleReportRequest($completeReport, $path);
        } else {
            $this->handleCoverageCollectionStartAndStop($currentReport);
        }
    }

    private function convertCookiesToHttpXHeaders()
    {
        if (isset($_COOKIE['CODECEPTION_CODECOVERAGE'])) {
            $cookie = json_decode($_COOKIE['CODECEPTION_CODECOVERAGE'], true);

            // fix for improperly encoded JSON in Code Coverage cookie with WebDriver.
            // @see https://github.com/Codeception/Codeception/issues/874
            if (!is_array($cookie)) {
                $cookie = json_decode($cookie, true);
            }

            if ($cookie) {
                foreach ($cookie as $key => $value) {
                    if (!empty($value)) {
                        $_SERVER["HTTP_X_CODECEPTION_" . strtoupper($key)] = $value;
                    }
                }
            }
        }
    }

    private function autoloadCodeception()
    {
        if (!class_exists('\\Codeception\\Codecept') || !function_exists('codecept_is_path_absolute')) {
            if (file_exists($this->autoloadRootDir . '/codecept.phar')) {
                require_once 'phar://' . $this->autoloadRootDir . '/codecept.phar/autoload.php';
            } elseif (stream_resolve_include_path($this->autoloadRootDir . '/vendor/autoload.php')) {
                require_once $this->autoloadRootDir . '/vendor/autoload.php';
                // Required to load some methods only available at codeception/autoload.php
                if (stream_resolve_include_path($this->autoloadRootDir . '/vendor/codeception/codeception/autoload.php')) {
                    require_once $this->autoloadRootDir . '/vendor/codeception/codeception/autoload.php';
                }
            } elseif (stream_resolve_include_path('Codeception/autoload.php')) {
                require_once 'Codeception/autoload.php';
            } else {
                $this->error('Codeception is not loaded. Please check that either PHAR or Composer package can be used');
            }
        }
    }

    private function registerPhpUnitCompatibility()
    {
        // phpunit codecoverage shimming
        if (!class_exists('PHP_CodeCoverage') and class_exists('SebastianBergmann\CodeCoverage\CodeCoverage')) {
            class_alias('SebastianBergmann\CodeCoverage\CodeCoverage', 'PHP_CodeCoverage');
            class_alias('SebastianBergmann\CodeCoverage\Report\Text', 'PHP_CodeCoverage_Report_Text');
            class_alias('SebastianBergmann\CodeCoverage\Report\PHP', 'PHP_CodeCoverage_Report_PHP');
            class_alias('SebastianBergmann\CodeCoverage\Report\Clover', 'PHP_CodeCoverage_Report_Clover');
            class_alias('SebastianBergmann\CodeCoverage\Report\Crap4j', 'PHP_CodeCoverage_Report_Crap4j');
            class_alias('SebastianBergmann\CodeCoverage\Report\Html\Facade', 'PHP_CodeCoverage_Report_HTML');
            class_alias('SebastianBergmann\CodeCoverage\Report\Xml\Facade', 'PHP_CodeCoverage_Report_XML');
            class_alias('SebastianBergmann\CodeCoverage\Exception', 'PHP_CodeCoverage_Exception');
        }
        // phpunit version
        if (!class_exists('PHPUnit_Runner_Version') && class_exists('PHPUnit\Runner\Version')) {
            class_alias('PHPUnit\Runner\Version', 'PHPUnit_Runner_Version');
        }
    }

    private function initCodeception()
    {
        // Load Codeception Config
        $configDistFile = $this->configDir . DIRECTORY_SEPARATOR . 'codeception.dist.yml';
        $configFile = $this->configDir . DIRECTORY_SEPARATOR . 'codeception.yml';

        if (isset($_SERVER['HTTP_X_CODECEPTION_CODECOVERAGE_CONFIG'])) {
            $configFile = $this->configDir . DIRECTORY_SEPARATOR . $_SERVER['HTTP_X_CODECEPTION_CODECOVERAGE_CONFIG'];
        }
        if (file_exists($configFile)) {
            // Use codeception.yml for configuration.
        } elseif (file_exists($configDistFile)) {
            // Use codeception.dist.yml for configuration.
            $configFile = $configDistFile;
        } else {
            $this->error(sprintf("Codeception config file '%s' not found", $configFile));
        }
        try {
            Configuration::config($configFile);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }

        // workaround for 'zend_mm_heap corrupted' problem
        gc_disable();

        $memoryLimit = ini_get('memory_limit');
        $requiredMemory = '384M';
        if ((substr($memoryLimit, -1) === 'M' && (int)$memoryLimit < (int)$requiredMemory)
            || (substr($memoryLimit, -1) === 'K' && (int)$memoryLimit < (int)$requiredMemory * 1024)
            || (ctype_digit($memoryLimit) && (int)$memoryLimit < (int)$requiredMemory * 1024 * 1024)
        ) {
            ini_set('memory_limit', $requiredMemory);
        }

        $this->tempDir = Configuration::logDir() . 'c3tmp';
        define('C3_CODECOVERAGE_PROJECT_ROOT', Configuration::projectDir());
        define('C3_CODECOVERAGE_TESTNAME', $_SERVER['HTTP_X_CODECEPTION_CODECOVERAGE']);

        if (!is_dir($this->tempDir)) {
            if (mkdir($this->tempDir, 0777, true) === false) {
                $this->error('Failed to create directory "' . $this->tempDir . '"');
            }
        }
    }

    private function handleReportRequest($completeReport, $path)
    {
        set_time_limit(0);

        $route = ltrim(strrchr(rtrim($_SERVER['REQUEST_URI'], '/'), '/'), '/');

        if ($route === 'clear') {
            $this->clear();
            return $this->exit();
        }

        list($codeCoverage, ) = $this->factory($completeReport);

        switch ($route) {
            case 'html':
                try {
                    $this->sendFile($this->buildHtmlReport($codeCoverage, $path));
                } catch (Exception $e) {
                    $this->error($e->getMessage());
                }
                return $this->exit();
            case 'clover':
                try {
                    $this->sendFile($this->buildCloverReport($codeCoverage, $path));
                } catch (Exception $e) {
                    $this->error($e->getMessage());
                }
                return $this->exit();
            case 'crap4j':
                try {
                    $this->sendFile($this->buildCrap4jReport($codeCoverage, $path));
                } catch (Exception $e) {
                    $this->error($e->getMessage());
                }
                return $this->exit();
            case 'serialized':
                try {
                    $this->sendFile($completeReport);
                } catch (Exception $e) {
                    $this->error($e->getMessage());
                }
                return $this->exit();
            case 'phpunit':
                try {
                    $this->sendFile($this->buildPhpunitReport($codeCoverage, $path));
                } catch (Exception $e) {
                    $this->error($e->getMessage());
                }
                return $this->exit();
            case 'cobertura':
                try {
                    $this->sendFile($this->buildCoberturaReport($codeCoverage, $path));
                } catch (Exception $e) {
                    $this->error($e->getMessage());
                }
                return $this->exit();
        }
    }

    private function handleCoverageCollectionStartAndStop($currentReport)
    {
        list($codeCoverage, ) = $this->factory(null);
        $codeCoverage->start(C3_CODECOVERAGE_TESTNAME);
        if (!array_key_exists('HTTP_X_CODECEPTION_CODECOVERAGE_DEBUG', $_SERVER)) {
            register_shutdown_function(
                function () use ($codeCoverage, $currentReport) {
                    $codeCoverage->stop();
                    if (!file_exists(dirname($currentReport))) { // verify directory exists
                        if (!mkdir(dirname($currentReport), 0777, true)) {
                            $this->error("Can't write CodeCoverage report into $currentReport");
                        }
                    }

                    // This will either lock the existing report for writing and return it along with a file pointer,
                    // or return a fresh PHP_CodeCoverage object without a file pointer. We'll merge the current request
                    // into that coverage object, write it to disk, and release the lock. By doing this in the end of
                    // the request, we avoid this scenario, where Request 2 overwrites the changes from Request 1:
                    //
                    //             Time ->
                    // Request 1 [ <read>               <write>          ]
                    // Request 2 [         <read>                <write> ]
                    //
                    // In addition, by locking the file for exclusive writing, we make sure no other request try to
                    // read/write to the file at the same time as this request (leading to a corrupt file). flock() is a
                    // blocking call, so it waits until an exclusive lock can be acquired before continuing.

                    list($existingCodeCoverage, $file) = $this->factory($currentReport, true);
                    $existingCodeCoverage->merge($codeCoverage);

                    if ($file === null) {
                        file_put_contents($currentReport, serialize($existingCodeCoverage), LOCK_EX);
                    } else {
                        fseek($file, 0);
                        fwrite($file, serialize($existingCodeCoverage));
                        fflush($file);
                        flock($file, LOCK_UN);
                        fclose($file);
                    }
                }
            );
        }
    }

    private function buildHtmlReport(PHP_CodeCoverage $codeCoverage, $path)
    {
        $writer = new PHP_CodeCoverage_Report_HTML();
        $writer->process($codeCoverage, $path . 'html');

        if (file_exists($path . '.tar')) {
            unlink($path . '.tar');
        }

        $phar = new PharData($path . '.tar');
        $phar->setSignatureAlgorithm(Phar::SHA1);
        $files = $phar->buildFromDirectory($path . 'html');
        array_map('unlink', $files);

        if (in_array('GZ', Phar::getSupportedCompression())) {
            if (file_exists($path . '.tar.gz')) {
                unlink($path . '.tar.gz');
            }

            $phar->compress(\Phar::GZ);

            // close the file so that we can rename it
            unset($phar);

            unlink($path . '.tar');
            rename($path . '.tar.gz', $path . '.tar');
        }

        return $path . '.tar';
    }

    private function buildCloverReport(PHP_CodeCoverage $codeCoverage, $path)
    {
        $writer = new PHP_CodeCoverage_Report_Clover();
        $writer->process($codeCoverage, $path . '.clover.xml');

        return $path . '.clover.xml';
    }

    private function buildCrap4jReport(PHP_CodeCoverage $codeCoverage, $path)
    {
        $writer = new PHP_CodeCoverage_Report_Crap4j();
        $writer->process($codeCoverage, $path . '.crap4j.xml');

        return $path . '.crap4j.xml';
    }

    private function buildCoberturaReport(PHP_CodeCoverage $codeCoverage, $path)
    {
        if (!class_exists(\SebastianBergmann\CodeCoverage\Report\Cobertura::class)) {
            throw new Exception("Cobertura report requires php-code-coverage >= 9.2");
        }
        $writer = new \SebastianBergmann\CodeCoverage\Report\Cobertura();
        $writer->process($codeCoverage, $path . '.cobertura.xml');

        return $path . '.cobertura.xml';
    }

    private function buildPhpunitReport(PHP_CodeCoverage $codeCoverage, $path)
    {
        $writer = new PHP_CodeCoverage_Report_XML(\PHPUnit_Runner_Version::id());
        $writer->process($codeCoverage, $path . 'phpunit');

        if (file_exists($path . '.tar')) {
            unlink($path . '.tar');
        }

        $phar = new PharData($path . '.tar');
        $phar->setSignatureAlgorithm(Phar::SHA1);
        $files = $phar->buildFromDirectory($path . 'phpunit');
        array_map('unlink', $files);

        if (in_array('GZ', Phar::getSupportedCompression())) {
            if (file_exists($path . '.tar.gz')) {
                unlink($path . '.tar.gz');
            }

            $phar->compress(\Phar::GZ);

            // close the file so that we can rename it
            unset($phar);

            unlink($path . '.tar');
            rename($path . '.tar.gz', $path . '.tar');
        }

        return $path . '.tar';
    }

    private function sendFile($filename)
    {
        if (!headers_sent()) {
            readfile($filename);
        }

        return $this->exit();
    }

    /**
     * @param $filename
     * @param bool $lock Lock the file for writing?
     * @return [null|PHP_CodeCoverage|\SebastianBergmann\CodeCoverage\CodeCoverage, resource]
     */
    private function factory($filename, $lock = false)
    {
        $file = null;
        if ($filename !== null && is_readable($filename)) {
            if ($lock) {
                $file = fopen($filename, 'r+');
                if (flock($file, LOCK_EX)) {
                    $phpCoverage = unserialize(stream_get_contents($file));
                } else {
                    $this->error("Failed to acquire write-lock for $filename");
                }
            } else {
                $phpCoverage = unserialize(file_get_contents($filename));
            }

            return array($phpCoverage, $file);
        } else {
            if (method_exists(Driver::class, 'forLineCoverage')) {
                //php-code-coverage 9+
                $filter = new CodeCoverageFilter();
                $driver = Driver::forLineCoverage($filter);
                $phpCoverage = new PHP_CodeCoverage($driver, $filter);
            } else {
                //php-code-coverage 8 or older
                $phpCoverage = new PHP_CodeCoverage();
            }
        }

        if (isset($_SERVER['HTTP_X_CODECEPTION_CODECOVERAGE_SUITE'])) {
            $suite = $_SERVER['HTTP_X_CODECEPTION_CODECOVERAGE_SUITE'];
            try {
                $settings = Configuration::suiteSettings($suite, Configuration::config());
            } catch (Exception $e) {
                $this->error($e->getMessage());
            }
        } else {
            $settings = Configuration::config();
        }

        try {
            \Codeception\Coverage\Filter::setup($phpCoverage)
                ->whiteList($settings)
                ->blackList($settings);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }

        return array($phpCoverage, $file);
    }

    private function exit()
    {
        if (!isset($_SERVER['HTTP_X_CODECEPTION_CODECOVERAGE_DEBUG'])) {
            exit;
        }
        return null;
    }

    private function clear()
    {
        \Codeception\Util\FileSystem::doEmptyDir($this->tempDir);
    }

    private function error($message)
    {
        $errorLogFile = isset($this->errorLogFile) ?
            $this->errorLogFile :
            $this->tempDir . DIRECTORY_SEPARATOR . 'error.txt';
        if (is_writable($errorLogFile)) {
            file_put_contents($errorLogFile, $message);
        } else {
            $message = "Could not write error to log file ($errorLogFile), original message: $message";
        }
        if (!headers_sent()) {
            header('X-Codeception-CodeCoverage-Error: ' . str_replace("\n", ' ', $message), true, 500);
        }
        setcookie('CODECEPTION_CODECOVERAGE_ERROR', $message);
    }

}

// @codeCoverageIgnoreEnd
