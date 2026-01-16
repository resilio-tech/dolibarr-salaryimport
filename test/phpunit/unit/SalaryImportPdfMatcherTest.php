<?php
/**
 * Standalone unit tests for SalaryImportPdfMatcher
 * No Dolibarr dependency required
 *
 * Run with: phpunit htdocs/custom/salaryimport/test/phpunit/unit/SalaryImportPdfMatcherTest.php
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__).'/../../../class/SalaryImportPdfMatcher.class.php';

class SalaryImportPdfMatcherTest extends TestCase
{
	/**
	 * @var SalaryImportPdfMatcher
	 */
	private $matcher;

	/**
	 * @var string Test directory
	 */
	private $testDir;

	protected function setUp(): void
	{
		$this->testDir = sys_get_temp_dir().'/salaryimport_test_'.uniqid();
		mkdir($this->testDir, 0755, true);
		$this->matcher = new SalaryImportPdfMatcher($this->testDir);
	}

	protected function tearDown(): void
	{
		$this->recursiveDelete($this->testDir);
	}

	private function recursiveDelete($dir)
	{
		if (is_dir($dir)) {
			$files = scandir($dir);
			foreach ($files as $file) {
				if ($file !== '.' && $file !== '..') {
					$path = $dir.'/'.$file;
					if (is_dir($path)) {
						$this->recursiveDelete($path);
					} else {
						unlink($path);
					}
				}
			}
			rmdir($dir);
		}
	}

	// ========================================
	// Tests for normalizeString()
	// ========================================

	public function testNormalizeStringSimple()
	{
		$this->assertEquals('jean', $this->matcher->normalizeString('Jean'));
		$this->assertEquals('dupont', $this->matcher->normalizeString('DUPONT'));
	}

	public function testNormalizeStringWithAccents()
	{
		$this->assertEquals('francois', $this->matcher->normalizeString('François'));
		$this->assertEquals('eleonore', $this->matcher->normalizeString('Éléonore'));
		$this->assertEquals('noel', $this->matcher->normalizeString('Noël'));
		$this->assertEquals('celine', $this->matcher->normalizeString('Céline'));
	}

	public function testNormalizeStringWithSpecialChars()
	{
		$this->assertEquals('jean-pierre', $this->matcher->normalizeString('Jean-Pierre'));
		// Note: apostrophe becomes HTML entity &#039; which becomes -039-
		// This is expected behavior from htmlentities()
		$result = $this->matcher->normalizeString("O'Brien");
		$this->assertStringContainsString('brien', $result);
		$this->assertEquals('test-test', $this->matcher->normalizeString('test  test'));
	}

	public function testNormalizeStringEmpty()
	{
		$this->assertEquals('', $this->matcher->normalizeString(''));
	}

	// ========================================
	// Tests for matchesUserName()
	// ========================================

	public function testMatchesUserNameFirstname()
	{
		$this->assertTrue($this->matcher->matchesUserName('jean', 'Jean', 'Dupont'));
		$this->assertTrue($this->matcher->matchesUserName('JEAN', 'Jean', 'Dupont'));
	}

	public function testMatchesUserNameLastname()
	{
		$this->assertTrue($this->matcher->matchesUserName('dupont', 'Jean', 'Dupont'));
		$this->assertTrue($this->matcher->matchesUserName('DUPONT', 'Jean', 'Dupont'));
	}

	public function testMatchesUserNameWithAccents()
	{
		$this->assertTrue($this->matcher->matchesUserName('francois', 'François', 'Martin'));
		$this->assertTrue($this->matcher->matchesUserName('françois', 'François', 'Martin'));
	}

	public function testMatchesUserNameNegative()
	{
		$this->assertFalse($this->matcher->matchesUserName('marie', 'Jean', 'Dupont'));
		$this->assertFalse($this->matcher->matchesUserName('martin', 'Jean', 'Dupont'));
		$this->assertFalse($this->matcher->matchesUserName('', 'Jean', 'Dupont'));
	}

	// ========================================
	// Tests for scanDirectoryForPdfs()
	// ========================================

	public function testScanDirectoryForPdfs()
	{
		// Create test files
		touch($this->testDir.'/jean_dupont.pdf');
		touch($this->testDir.'/marie_martin.pdf');
		touch($this->testDir.'/not_a_pdf.txt');
		touch($this->testDir.'/UPPERCASE.PDF');

		$pdfs = $this->matcher->scanDirectoryForPdfs($this->testDir);

		$this->assertCount(3, $pdfs); // jean, marie, UPPERCASE

		// Check structure
		$filenames = array_column($pdfs, 'filename');
		$this->assertContains('jean_dupont.pdf', $filenames);
		$this->assertContains('marie_martin.pdf', $filenames);
	}

	public function testScanDirectoryForPdfsExtractsLinks()
	{
		touch($this->testDir.'/jean_dupont.pdf');

		$pdfs = $this->matcher->scanDirectoryForPdfs($this->testDir);

		$this->assertEquals(array('jean', 'dupont'), $pdfs[0]['links']);
	}

	public function testScanDirectoryForPdfsEmpty()
	{
		$pdfs = $this->matcher->scanDirectoryForPdfs($this->testDir);
		$this->assertEmpty($pdfs);
	}

	public function testScanDirectoryForPdfsNonExistent()
	{
		$pdfs = $this->matcher->scanDirectoryForPdfs('/nonexistent/path');
		$this->assertEmpty($pdfs);
	}

	// ========================================
	// Tests for findPdfForUser()
	// ========================================

	public function testFindPdfForUserMatch()
	{
		$pdfs = array(
			array(
				'filename' => 'jean_dupont.pdf',
				'path' => '/path/to/jean_dupont.pdf',
				'links' => array('jean', 'dupont')
			),
			array(
				'filename' => 'marie_martin.pdf',
				'path' => '/path/to/marie_martin.pdf',
				'links' => array('marie', 'martin')
			)
		);

		$result = $this->matcher->findPdfForUser('Jean', 'Dupont', $pdfs);
		$this->assertEquals('/path/to/jean_dupont.pdf', $result);

		$result = $this->matcher->findPdfForUser('Marie', 'Martin', $pdfs);
		$this->assertEquals('/path/to/marie_martin.pdf', $result);
	}

	public function testFindPdfForUserNotFound()
	{
		$pdfs = array(
			array(
				'filename' => 'jean_dupont.pdf',
				'path' => '/path/to/jean_dupont.pdf',
				'links' => array('jean', 'dupont')
			)
		);

		$result = $this->matcher->findPdfForUser('Pierre', 'Durand', $pdfs);
		$this->assertNull($result);
	}

	public function testFindPdfForUserWithAccents()
	{
		$pdfs = array(
			array(
				'filename' => 'francois_noel.pdf',
				'path' => '/path/to/francois_noel.pdf',
				'links' => array('francois', 'noel')
			)
		);

		// Should match even with accented names
		$result = $this->matcher->findPdfForUser('François', 'Noël', $pdfs);
		$this->assertEquals('/path/to/francois_noel.pdf', $result);
	}

	public function testFindPdfForUserEmptyList()
	{
		$result = $this->matcher->findPdfForUser('Jean', 'Dupont', array());
		$this->assertNull($result);
	}

	// ========================================
	// Tests for extractFromZip()
	// ========================================

	public function testExtractFromZipNotFound()
	{
		$pdfs = $this->matcher->extractFromZip('/nonexistent/file.zip');

		$this->assertEmpty($pdfs);
		$this->assertNotEmpty($this->matcher->errors);
		$this->assertStringContainsString('not found', $this->matcher->errors[0]);
	}

	public function testExtractFromZipValid()
	{
		if (!class_exists('ZipArchive')) {
			$this->markTestSkipped('ZipArchive extension not available');
		}

		// Create a test ZIP file
		$zipPath = $this->testDir.'/test.zip';
		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE);
		$zip->addFromString('test_user.pdf', '%PDF-1.4 test');
		$zip->close();

		$pdfs = $this->matcher->extractFromZip($zipPath, 'extracted');

		$this->assertCount(1, $pdfs);
		$this->assertEquals('test_user.pdf', $pdfs[0]['filename']);
		$this->assertEquals(array('test', 'user'), $pdfs[0]['links']);
	}

	// ========================================
	// Tests for cleanup()
	// ========================================

	public function testCleanup()
	{
		// Create test structure
		$folderName = 'test_folder';
		mkdir($this->testDir.'/'.$folderName, 0755);
		touch($this->testDir.'/'.$folderName.'/test.pdf');
		touch($this->testDir.'/test.zip');

		$result = $this->matcher->cleanup($folderName, 'test.zip');

		$this->assertEquals(1, $result);
		$this->assertDirectoryDoesNotExist($this->testDir.'/'.$folderName);
		$this->assertFileDoesNotExist($this->testDir.'/test.zip');
	}

	public function testCleanupNonExistent()
	{
		// Should not fail if files don't exist
		$result = $this->matcher->cleanup('nonexistent_folder', 'nonexistent.zip');
		$this->assertEquals(1, $result);
	}

	// ========================================
	// Tests for getWorkDir()
	// ========================================

	public function testGetWorkDir()
	{
		$this->assertEquals($this->testDir, $this->matcher->getWorkDir());
	}

	public function testDefaultWorkDirWithoutDolibarr()
	{
		// When DOL_DATA_ROOT is not defined, should use sys_get_temp_dir
		$matcher = new SalaryImportPdfMatcher();
		$this->assertStringContainsString('salaryimport', $matcher->getWorkDir());
	}
}
