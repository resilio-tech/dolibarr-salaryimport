<?php
/**
 * Test that our File patch works with open_basedir restrictions
 *
 * This test must be run with open_basedir enabled to simulate production conditions.
 * Run with: php -d open_basedir=$(pwd):/tmp test/phpunit/unit/OpenBasedirTest.php
 *
 * The key is that /xl, /_rels, /docProps paths (internal XLSX paths) are NOT in
 * the allowed paths, which would trigger open_basedir errors without our patch.
 */

// Load our patched File class FIRST
require_once dirname(__FILE__).'/../../../lib/PhpSpreadsheetFileFix.php';

use PhpOffice\PhpSpreadsheet\Shared\File;

/**
 * Test the File::realpath() fix for open_basedir
 */
class OpenBasedirTest
{
	/**
	 * @var array Test results
	 */
	private $results = array();

	/**
	 * @var int Error count
	 */
	private $errors = 0;

	/**
	 * Run all tests
	 */
	public function runTests()
	{
		echo "=== OpenBasedir Restriction Test ===\n\n";

		// Check if open_basedir is active
		$openBasedir = ini_get('open_basedir');
		if (empty($openBasedir)) {
			echo "WARNING: open_basedir is NOT set. Run this test with:\n";
			echo "  php -d 'open_basedir=" . dirname(__FILE__, 4) . ":/tmp' " . __FILE__ . "\n\n";
			echo "Continuing anyway to test the patch logic...\n\n";
		} else {
			echo "open_basedir is set to: $openBasedir\n\n";
		}

		// Test internal ZIP paths that should NOT trigger file_exists()
		$this->testInternalZipPaths();

		// Test real filesystem paths that SHOULD use file_exists()
		$this->testRealFilesystemPaths();

		// Test path normalization (removing ..)
		$this->testPathNormalization();

		// Summary
		$this->printSummary();

		return $this->errors === 0 ? 0 : 1;
	}

	/**
	 * Test that internal ZIP paths don't trigger open_basedir errors
	 */
	private function testInternalZipPaths()
	{
		echo "--- Testing internal ZIP paths (should NOT call file_exists) ---\n";

		$internalPaths = array(
			'/xl/worksheets/sheet1.xml',
			'/xl/styles.xml',
			'/xl/workbook.xml',
			'/xl/sharedStrings.xml',
			'xl/worksheets/sheet1.xml',
			'xl/styles.xml',
			'_rels/.rels',
			'/_rels/.rels',
			'docProps/core.xml',
			'/docProps/app.xml',
			'[Content_Types].xml',
			'../comments1.xml',
			'../drawings/drawing1.xml',
		);

		foreach ($internalPaths as $path) {
			$this->testPath($path, 'internal ZIP path');
		}
		echo "\n";
	}

	/**
	 * Test that real filesystem paths still work
	 */
	private function testRealFilesystemPaths()
	{
		echo "--- Testing real filesystem paths (should use file_exists) ---\n";

		$realPaths = array(
			__FILE__,  // This file exists
			'/tmp',    // Usually exists
			dirname(__FILE__) . '/nonexistent.txt',  // Doesn't exist but path is valid
		);

		foreach ($realPaths as $path) {
			$this->testPath($path, 'filesystem path');
		}
		echo "\n";
	}

	/**
	 * Test path normalization with ..
	 */
	private function testPathNormalization()
	{
		echo "--- Testing path normalization ---\n";

		$tests = array(
			'xl/worksheets/../styles.xml' => 'xl/styles.xml',
			'/xl/worksheets/../sharedStrings.xml' => '/xl/sharedStrings.xml',
			'xl/worksheets/../../docProps/core.xml' => 'docProps/core.xml',
		);

		foreach ($tests as $input => $expected) {
			$result = File::realpath($input);
			$pass = ($result === $expected);

			if ($pass) {
				echo "  [PASS] realpath('$input') = '$result'\n";
			} else {
				echo "  [FAIL] realpath('$input') = '$result' (expected '$expected')\n";
				$this->errors++;
			}
			$this->results[] = array('path' => $input, 'pass' => $pass);
		}
		echo "\n";
	}

	/**
	 * Test a single path
	 */
	private function testPath($path, $type)
	{
		// Capture any warnings/errors
		$errorOccurred = false;
		$errorMessage = '';

		set_error_handler(function($errno, $errstr) use (&$errorOccurred, &$errorMessage) {
			$errorOccurred = true;
			$errorMessage = $errstr;
			return true;  // Don't execute PHP's internal error handler
		});

		try {
			$result = File::realpath($path);
			restore_error_handler();

			if ($errorOccurred) {
				echo "  [FAIL] realpath('$path') triggered error: $errorMessage\n";
				$this->errors++;
				$this->results[] = array('path' => $path, 'pass' => false);
			} else {
				echo "  [PASS] realpath('$path') = '$result'\n";
				$this->results[] = array('path' => $path, 'pass' => true);
			}
		} catch (Exception $e) {
			restore_error_handler();
			echo "  [FAIL] realpath('$path') threw exception: " . $e->getMessage() . "\n";
			$this->errors++;
			$this->results[] = array('path' => $path, 'pass' => false);
		}
	}

	/**
	 * Print test summary
	 */
	private function printSummary()
	{
		$total = count($this->results);
		$passed = $total - $this->errors;

		echo "=== Summary ===\n";
		echo "Total: $total, Passed: $passed, Failed: $this->errors\n";

		if ($this->errors === 0) {
			echo "\n[SUCCESS] All tests passed! The patch works correctly.\n";
		} else {
			echo "\n[FAILURE] Some tests failed. Check the output above.\n";
		}
	}
}

// Run tests if executed directly (not via PHPUnit)
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
	$test = new OpenBasedirTest();
	exit($test->runTests());
}
