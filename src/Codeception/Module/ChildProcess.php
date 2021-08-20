<?php

namespace Codeception\Module;

use LeeShan87\React\MultiLoop\MultiLoop;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;

class ChildProcess extends \Codeception\Module
{
    /**
     * @var Process
     */
    protected $childProcess;

    /**
     * @var LoopInterface
     */
    protected $childProcessLoop;
    /**
     * @var array
     */
    protected $settings = [];
    /**
     * @var string
     */
    protected $childProcessVarDir = '/var/lib/';
    /**
     * @var string
     */
    protected $childProcessLogDir = '/var/log/';
    /**
     * @var string
     */
    protected $childProcessEtcDir = '/etc/';
    /**
     * @var string
     */
    protected $output;
    protected $remoteConfigEnabled = true;
    /**
     * @var Test
     */
    protected $test;
    /**
     * @var array
     */
    protected $config = [
        // todo: Find out how can we check if coverage is really enabled.
        // Value in $this->settings['coverage'] show the content in the codeception.yml
        // Not the codeception run time cli options. I haven't found any public getter to this value.
        'generate_coverage' => false
    ];
    /**
     * @var string
     */
    protected $outputDir;

    protected $phpChildProcessCliCommandString;
    public function _beforeSuite($settings = [])
    {
        $this->settings = $settings;
    }

    /**
     * Before test Codeception hook
     *
     * @param \Codeception\TestInterface $test
     * @return void
     */
    public function _before(\Codeception\TestInterface $test)
    {
        $this->test = $test;
    }

    /**
     * Helper function the retrieve ChildProcess module configuration.
     *
     * @param string $key
     * @return mixed
     */
    public function grabChildProcessConfig($key)
    {
        return isset($this->config[$key]) ? $this->config[$key] : null;
    }

    /**
     * Returns the currently running test name.
     *
     * @return string
     */
    public function grabTestName()
    {
        return $this->test->getName();
    }

    /**
     * Creates an output directory for the ChildProcess test
     *
     * @param string $outputDir
     * @return void
     */
    public function createChildProcessOutputDir($outputDir)
    {
        $this->outputDir = $outputDir;
        /** @var \Codeception\Module\Filesystem $fileSystem */
        $fileSystem = $this->getModule('Filesystem');
        if (is_dir($outputDir)) {
            $fileSystem->deleteDir($outputDir);
        }
        mkdir($outputDir, 0755, true);
        $this->setChildProcessVarDir($outputDir . '/var/lib');
        $this->setChildProcessLogDir($outputDir . '/var/log');
        $this->setChildProcessEtcDir($outputDir . '/etc');
    }
    /**
     * Overwrite the default PHP_BINARY usage.
     * 
     * With this function we are able to run more accurate php executable setting. For example:
     * ```bash
     * /usr/bin/php -c /etc/php.ini -d zend_extension=xdebug
     * ```
     *
     * @param string $command
     * @return self
     */
    public function setPhpChildProcessCliCommandString($command){
        $this->phpChildProcessCliCommandString = $command;
        return $this;
    }
    /**
     * Returns the path of the current tests output directory
     *
     * @return string
     */
    public function grabChildProcessTestOutputDir()
    {
        return $this->outputDir;
    }

    /**
     * @return self
     */
    public function createChildProcess()
    {
        $this->childProcessLoop = \React\EventLoop\Factory::create();
        return $this;
    }
    /**
     * @param string $varDir
     * @return self
     */
    public function setChildProcessVarDir($varDir)
    {
        $this->childProcessVarDir = $varDir;
        return $this;
    }
    /**
     * Returns the path of the current test ChildProcess var directory
     *
     * @return string
     */
    public function grabChildProcessVarDir()
    {
        return $this->childProcessVarDir;
    }

    /**
     * @param string $logDir
     * @return self
     */
    public function setChildProcessLogDir($logDir)
    {
        $this->childProcessLogDir = $logDir;
        return $this;
    }
    /**
     * Returns the path of the current test ChildProcess log directory
     *
     * @return string
     */
    public function grabChildProcessLogDir()
    {
        return $this->childProcessLogDir;
    }
    /**
     * @param string $etcDir
     * @return self
     */
    public function setChildProcessEtcDir($etcDir)
    {
        $this->childProcessEtcDir = $etcDir;
        return $this;
    }
    /**
     * Returns the path of the current test ChildProcess etc directory
     *
     * @return string
     */
    public function grabChildProcessEtcDir()
    {
        return $this->childProcessEtcDir;
    }

    public function findChildProcessRunnerPath(){
        $ds= DIRECTORY_SEPARATOR;
        return dirname(__DIR__)."{$ds}Util{$ds}ChildProcessRunner.php";

    }
    /**
     * Starts ChildProcess bootstrap process in a separate child process
     *
     * The ChildProcess hold many legacy blocking, exit, wait for ever code fragments. This is why we have to run these test in a separated process,
     * to not block or kill the testing codeception process.
     *
     * @todo We should make this code able to run multiple test in the separated process.
     * @param string $testCode
     * @return void
     */
    public function startPhpCodeInChildProcess($testCode)
    {
        $evn = [
            'test_name' => $this->test->getMetadata()->getFeature(),
            'test_code_snippet' => $testCode,
            'coverage' => $this->config['generate_coverage'] ? $this->settings['coverage'] : false
        ];
        $phpExecutable = PHP_BINARY;
        if(!is_null($this->phpChildProcessCliCommandString)){
            $phpExecutable = $this->phpChildProcessCliCommandString;
        }
        $runner = $this->findChildProcessRunnerPath();
        $commandString = 'exec '. escapeshellarg($phpExecutable). ' '.  escapeshellarg($runner). ' '.escapeshellarg(json_encode($evn));
        $this->output = '';
        $process = new Process($commandString);
        $this->childProcess = $process;
        MultiLoop::addLoop($this->childProcessLoop, 'ChildProcessLoop');
        $process->start($this->childProcessLoop);
        $process->stdout->on('data', function ($chunk) {
            $this->output .= $chunk;
        });
    }
    /**
     * @return LoopInterface
     */
    public function grabChildProcessLoop()
    {
        return $this->childProcessLoop;
    }
    /**
     * Stop the ChildProcess test child process
     *
     * @return void
     */
    public function stopChildProcess()
    {
        $this->childProcess->terminate();
    }
    /**
     * Waits till the test child process is stopped.
     *
     * When you want to generate code coverage in the child process this can take a while.
     * Roughly about 30-50 seconds.
     *
     * @param integer $seconds
     * @return void
     */
    public function waitTillChildProcessStopped($seconds = 40)
    {
        $startTime = time();
        $maxExecutionTime = $startTime + $seconds;
        while ($this->isChildProcessRunning() && time() < $maxExecutionTime) {
            MultiLoop::tickAll();
        }
        MultiLoop::removeLoop('ChildProcessLoop');
    }
    /**
     * Waits till the test child process ready to run the tests.
     *
     * When you want to generate code coverage in the child process this can take a while.
     * Roughly about 30-50 seconds.
     *
     * @return void
     */
    public function waitTillTestChildProcessInitialized()
    {
        while ($this->isChildProcessRunning() && !$this->isTestProcessInitialized()) {
            MultiLoop::tickAll();
        }
        Assert::assertTrue($this->isChildProcessRunning(), "Test child process exited");
    }
    /**
     * Checks if the ChildProcess test child process is stopped
     *
     * @return boolean
     */
    public function isChildProcessStopped()
    {
        return !$this->childProcess->isRunning();
    }
    /**
     * Checks if the ChildProcess test child process is running
     *
     * @return boolean
     */
    public function isChildProcessRunning()
    {
        return $this->childProcess->isRunning();
    }
    /**
     * Checks if the test child process is ready to run the tests.
     *
     * @return boolean
     */
    public function isTestProcessInitialized()
    {
        return strpos($this->output, 'Test Process Initialized') !== false;
    }

    /**
     * Asserts that the ChildProcess console output is containing a string
     *
     * @param string $content
     * @return void
     */
    public function seeInChildProcessOutput($content)
    {
        $this->assertStringContainsString($content, $this->output);
    }

    /**
     * Asserts that the ChildProcess console output is not containing a string
     *
     * @param string $content
     * @return void
     */
    public function dontSeeInChildProcessOutput($content)
    {
        $this->assertStringNotContainsString($content, $this->output);
    }

    /**
     * Asserts that the ChildProcess test test child process is running
     *
     * @return void
     */
    public function seeChildProcessIsRunning()
    {
        $this->assertTrue($this->childProcess->isRunning());
    }

    /**
     * Asserts that the ChildProcess test test child process is stopped
     *
     * @return void
     */
    public function seeChildProcessIsStopped()
    {
        $this->assertFalse($this->childProcess->isRunning());
    }
    /**
     * Asserts that the ChildProcess test child process is not stopped
     *
     * Alias of seeChildProcessIsRunning
     *
     * @return void
     */
    public function dontSeeChildProcessIsStopped()
    {
        $this->assertTrue($this->childProcess->isRunning());
    }

    /**
     * Resturns the ChildProcess console output as a string
     *
     * @return string
     */
    public function grabChildProcessOutputString()
    {
        return $this->output;
    }

    /**
     * Returns the ChildProcess console output as an array
     *
     * @return array
     */
    public function grabChildProcessOutputAsArray()
    {
        return explode("\n", $this->output);
    }
    /**
     * Asserts that an ChildProcess log file is exists
     *
     * @param sting $logFileName
     * @return void
     */
    public function seeLogFile($logFileName)
    {
        $this->assertFileExists($this->childProcessLogDir . DIRECTORY_SEPARATOR . $logFileName);
    }
    /**
     * Asserts that an ChildProcess log file is not exists
     *
     * @param sting $logFileName
     * @return void
     */
    public function dontSeeLogFile($logFileName)
    {
        $this->assertFileDoesNotExist($this->childProcessLogDir . DIRECTORY_SEPARATOR . $logFileName);
    }
    /**
     * Asserts if an ChildProcess log file containing a string
     *
     * @param string $filePath
     * @param string $content
     * @return void
     */ public function seeLogFileContains($logFileName, $content)
    {
        $logFile = $this->childProcessLogDir . DIRECTORY_SEPARATOR . $logFileName;
        $this->seeFileContains($logFile, $content);
    }
    /**
     * @param string $logFileName
     * @return string
     */
    public function grabLogFileContent($logFileName)
    {
        $logFile = $this->childProcessLogDir . DIRECTORY_SEPARATOR . $logFileName;
        return file_get_contents($logFile);
    }

    /**
     * Asserts if an ChildProcess log file not containing a string
     *
     * @param string $filePath
     * @param string $content
     * @return void
     */
    public function dontSeeLogFileContains($logFileName, $content)
    {
        $logFile = $this->childProcessLogDir . DIRECTORY_SEPARATOR . $logFileName;
        $this->dontSeeFileContains($logFile, $content);
    }
    /**
     * Asserts if a file containing a string
     *
     * @param string $filePath
     * @param string $content
     * @return void
     */

    public function seeFileContains($filePath, $content)
    {
        $this->assertFileExists($filePath);
        $fileContent = file_get_contents($filePath);
        $this->assertStringContainsString($content, $fileContent);
    }
    /**
     * Asserts if a file not containing a string
     *
     * @param string $filePath
     * @param string $content
     * @return void
     */
    public function dontSeeFileContains($filePath, $content)
    {
        $this->assertFileExists($filePath);
        $fileContent = file_get_contents($filePath);
        $this->assertStringNotContainsString($content, $fileContent);
    }

    /**
     * Asserts that a file is exists in the ChildProcess var dir
     *
     * @param string $fileName
     * @return void
     */
    public function seeFileInVarDir($fileName)
    {
        $this->assertFileExists($this->childProcessVarDir . DIRECTORY_SEPARATOR . $fileName);
    }
    /**
     * Asserts that file is not exists in the ChildProcess var dir
     *
     * @param string $fileName
     * @return void
     */
    public function dontSeeFileInVarDir($fileName)
    {
        $this->assertFileDoesNotExist($this->childProcessVarDir . DIRECTORY_SEPARATOR . $fileName);
    }
    /**
     * @param string $fileName
     * @return string
     */
    public function grabContentOfFileLocatedInVarDir($fileName)
    {
        $path = $this->childProcessVarDir . DIRECTORY_SEPARATOR . $fileName;
        return file_get_contents($path);
    }

    /**
     * Helper function to debug log messages
     *
     * @param string $message
     * @return void
     */
    protected function _log($message)
    {
        $this->debugSection($this->_getName(), $message);
        if (!$this->doLog) {
            return;
        }
        echo "\n|{$this->_getName()}| $message";
    }
}
