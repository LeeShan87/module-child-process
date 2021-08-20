<?php
// @codeCoverageIgnoreStart

use SebastianBergmann\CodeCoverage\CodeCoverage;

if (php_sapi_name() !== 'cli') {
    die("This script must be run in a cli test environment");
}
$dir = __DIR__;
$ds = DIRECTORY_SEPARATOR;
for ($i = 0; $i < 5; $i++) {
    $projectRootDir = dirname($dir);
    if (!is_dir("$projectRootDir{$ds}vendor")) {
        $dir = $projectRootDir;
        continue;
    }
    include "$projectRootDir{$ds}vendor{$ds}autoload.php";
    include "$projectRootDir{$ds}vendor{$ds}codeception{$ds}codeception{$ds}autoload.php";
}
function _logToOutput($message)
{
    global $outputFile;
    if ($outputFile === false) {
        return;
    }
    file_put_contents(codecept_output_dir($outputFile), $message . "\n", FILE_APPEND);
}
cli_set_process_title('ChildProcessRunner');
$codeceptionTestInfo = $argv[1];
$codeceptionTest = json_decode($codeceptionTestInfo, true);
if (!is_array($codeceptionTest)) {
    die("CodeCeption test information must be set before using this script");
}
$testName = isset($codeceptionTest['test_name']) ? $codeceptionTest['test_name'] : 'no test';
$codeSnippet = isset($codeceptionTest['test_code_snippet']) ? $codeceptionTest['test_code_snippet'] : '';
$outputFile = isset($codeceptionTest['child_process_log_file']) ? $codeceptionTest['child_process_log_file'] : false;
$generateCoverage = isset($codeceptionTest['coverage']) ? $codeceptionTest['coverage'] : false;
if ($generateCoverage !== false) {
    // @todo Findout a better way to collect coverage configuration
    $configFile = $projectDir . '/codeception.yml';
    \Codeception\Configuration::config($configFile);
    $settings =  \Codeception\Configuration::config();
    $coverage = new CodeCoverage;
    $coverage->setCheckForUnexecutedCoveredCode(true);
    \Codeception\Coverage\Filter::setup($coverage)
        ->whiteList($settings)
        ->blackList($settings);
    $writer = new \SebastianBergmann\CodeCoverage\Report\PHP;
    register_shutdown_function(function () use ($coverage, $writer, $outputFile) {
        $coverage->stop();
        $writer->process($coverage, $outputFile);
    });
    $coverage->start($testName);
}
echo "Test Process Initialized\n";
// @todo Eval is evil. We should find a more secure solution to this.
eval($codeSnippet);
