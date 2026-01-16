<?php
/**
 * Standalone unit tests for SalaryImportParser
 * Requires PhpSpreadsheet to be available
 *
 * Run with: phpunit htdocs/custom/salaryimport/test/phpunit/unit/SalaryImportParserTest.php
 */

use PHPUnit\Framework\TestCase;

// Try to load PhpSpreadsheet autoloader
$phpSpreadsheetLoaded = false;
$autoloaderPaths = array(
	__DIR__.'/../../../../../includes/phpoffice/phpspreadsheet/src/autoloader.php',
	__DIR__.'/../../../../../../includes/phpoffice/phpspreadsheet/src/autoloader.php',
);

foreach ($autoloaderPaths as $path) {
	if (file_exists($path)) {
		require_once $path;
		// Also need PSR autoloader
		$psrPath = dirname($path).'/../../Psr/autoloader.php';
		if (file_exists($psrPath)) {
			require_once $psrPath;
		}
		$phpSpreadsheetLoaded = true;
		break;
	}
}

if ($phpSpreadsheetLoaded) {
	require_once dirname(__FILE__).'/../../../class/SalaryImportParser.class.php';
}

class SalaryImportParserTest extends TestCase
{
	/**
	 * @var SalaryImportParser|null
	 */
	private $parser;

	/**
	 * @var bool
	 */
	private static $phpSpreadsheetAvailable = false;

	public static function setUpBeforeClass(): void
	{
		global $phpSpreadsheetLoaded;
		self::$phpSpreadsheetAvailable = $phpSpreadsheetLoaded;
	}

	protected function setUp(): void
	{
		if (!self::$phpSpreadsheetAvailable) {
			$this->markTestSkipped('PhpSpreadsheet not available');
		}
		$this->parser = new SalaryImportParser();
	}

	// ========================================
	// Tests for fixEncoding()
	// ========================================

	public function testFixEncodingNormalUtf8()
	{
		$this->assertEquals('Test', $this->parser->fixEncoding('Test'));
		$this->assertEquals('Hello World', $this->parser->fixEncoding('Hello World'));
	}

	public function testFixEncodingWithAccents()
	{
		$this->assertEquals('été', $this->parser->fixEncoding('été'));
		$this->assertEquals('François', $this->parser->fixEncoding('François'));
	}

	public function testFixEncodingNonString()
	{
		$this->assertEquals(123, $this->parser->fixEncoding(123));
		$this->assertEquals(45.67, $this->parser->fixEncoding(45.67));
		$this->assertNull($this->parser->fixEncoding(null));
		$this->assertTrue($this->parser->fixEncoding(true));
	}

	public function testFixEncodingPreservesValidUtf8()
	{
		// The fixEncoding function should preserve valid UTF-8 strings
		// It's designed to fix specific double-encoding issues from Excel

		// Normal UTF-8 accented text should pass through unchanged
		$this->assertEquals('café', $this->parser->fixEncoding('café'));
		$this->assertEquals('naïve', $this->parser->fixEncoding('naïve'));
		$this->assertEquals('Prénom', $this->parser->fixEncoding('Prénom'));
	}

	public function testFixEncodingEmpty()
	{
		$this->assertEquals('', $this->parser->fixEncoding(''));
	}

	// ========================================
	// Tests for parseFile() - error cases
	// ========================================

	public function testParseFileNotFound()
	{
		$result = $this->parser->parseFile('/nonexistent/path/file.xlsx');

		$this->assertEquals(-1, $result);
		$this->assertNotEmpty($this->parser->errors);
		$this->assertStringContainsString('not found', $this->parser->errors[0]);
	}

	public function testParseFileWrongExtension()
	{
		// Create a temp file with wrong extension
		$tempFile = sys_get_temp_dir().'/test_'.uniqid().'.csv';
		touch($tempFile);

		try {
			$result = $this->parser->parseFile($tempFile);

			$this->assertEquals(-3, $result);
			$this->assertNotEmpty($this->parser->errors);
			$this->assertStringContainsString('XLSX', $this->parser->errors[0]);
		} finally {
			@unlink($tempFile);
		}
	}

	public function testParseFileNotReadable()
	{
		// Create a temp file and make it unreadable (Unix only)
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$this->markTestSkipped('Cannot test file permissions on Windows');
		}

		$tempFile = sys_get_temp_dir().'/test_'.uniqid().'.xlsx';
		touch($tempFile);
		chmod($tempFile, 0000);

		try {
			$result = $this->parser->parseFile($tempFile);
			// Should return error about not readable
			$this->assertLessThan(0, $result);
		} finally {
			chmod($tempFile, 0644);
			@unlink($tempFile);
		}
	}

	// ========================================
	// Tests for getters before parsing
	// ========================================

	public function testGetHeadersBeforeParsing()
	{
		$this->assertEquals(array(), $this->parser->getHeaders());
	}

	public function testGetLinesBeforeParsing()
	{
		$this->assertEquals(array(), $this->parser->getLines());
	}

	public function testGetRowCountBeforeParsing()
	{
		$this->assertEquals(0, $this->parser->getRowCount());
	}

	public function testGetLineBeforeParsing()
	{
		$this->assertNull($this->parser->getLine(0));
		$this->assertNull($this->parser->getLine(99));
	}

	public function testGetValueBeforeParsing()
	{
		$this->assertNull($this->parser->getValue(0, 'Test'));
	}

	public function testHasColumnBeforeParsing()
	{
		$this->assertFalse($this->parser->hasColumn('Test'));
	}

	public function testGetFilePathBeforeParsing()
	{
		$this->assertNull($this->parser->getFilePath());
	}
}
