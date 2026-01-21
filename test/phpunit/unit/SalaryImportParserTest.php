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

	// ========================================
	// Tests for parseFile() - with empty headers
	// ========================================

	/**
	 * Test that columns with empty/null headers are skipped
	 * This prevents "Illegal offset type" errors in PHP 8+
	 */
	public function testParseFileWithEmptyHeaders()
	{
		// Create a temp XLSX file with some empty headers
		$tempFile = sys_get_temp_dir().'/test_empty_headers_'.uniqid().'.xlsx';

		try {
			$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
			$sheet = $spreadsheet->getActiveSheet();

			// Headers: "Name", empty, "Amount", null, "Date"
			$sheet->setCellValue('A1', 'Name');
			$sheet->setCellValue('B1', '');      // Empty string header
			$sheet->setCellValue('C1', 'Amount');
			$sheet->setCellValue('D1', null);    // Null header
			$sheet->setCellValue('E1', 'Date');

			// Data row 1
			$sheet->setCellValue('A2', 'John');
			$sheet->setCellValue('B2', 'skip_this');
			$sheet->setCellValue('C2', 100);
			$sheet->setCellValue('D2', 'skip_this_too');
			$sheet->setCellValue('E2', '2024-01-15');

			// Data row 2
			$sheet->setCellValue('A3', 'Jane');
			$sheet->setCellValue('B3', 'ignored');
			$sheet->setCellValue('C3', 200);
			$sheet->setCellValue('D3', 'also_ignored');
			$sheet->setCellValue('E3', '2024-01-16');

			$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
			$writer->save($tempFile);

			// Parse the file - should NOT throw "Illegal offset type" error
			$result = $this->parser->parseFile($tempFile);

			$this->assertEquals(1, $result, 'parseFile should return 1 on success');
			$this->assertEmpty($this->parser->errors, 'No errors should be reported');

			// Check headers - should only have non-empty headers
			$headers = $this->parser->getHeaders();
			$this->assertContains('Name', $headers);
			$this->assertContains('Amount', $headers);
			$this->assertContains('Date', $headers);
			$this->assertNotContains('', $headers, 'Empty headers should be skipped');
			$this->assertNotContains(null, $headers, 'Null headers should be skipped');

			// Check data rows
			$this->assertEquals(2, $this->parser->getRowCount());

			$line1 = $this->parser->getLine(0);
			$this->assertEquals('John', $line1['Name']);
			$this->assertEquals(100, $line1['Amount']);
			$this->assertEquals('2024-01-15', $line1['Date']);
			$this->assertArrayNotHasKey('', $line1, 'Data should not have empty key');

			$line2 = $this->parser->getLine(1);
			$this->assertEquals('Jane', $line2['Name']);
			$this->assertEquals(200, $line2['Amount']);
			$this->assertEquals('2024-01-16', $line2['Date']);
		} finally {
			@unlink($tempFile);
		}
	}

	/**
	 * Test parsing file where ALL headers are empty - should return error
	 */
	public function testParseFileWithAllEmptyHeaders()
	{
		$tempFile = sys_get_temp_dir().'/test_all_empty_headers_'.uniqid().'.xlsx';

		try {
			$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
			$sheet = $spreadsheet->getActiveSheet();

			// All empty headers
			$sheet->setCellValue('A1', '');
			$sheet->setCellValue('B1', null);
			$sheet->setCellValue('C1', '');

			// Data row
			$sheet->setCellValue('A2', 'data1');
			$sheet->setCellValue('B2', 'data2');
			$sheet->setCellValue('C2', 'data3');

			$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
			$writer->save($tempFile);

			// Parse should fail because no valid headers means no data can be extracted
			$result = $this->parser->parseFile($tempFile);

			// With no valid headers, no lines will be parsed
			$this->assertEquals(-5, $result, 'Should return -5 (no data rows) when all headers are empty');
		} finally {
			@unlink($tempFile);
		}
	}
}
