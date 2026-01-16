<?php
/* Copyright (C) 2024 SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       test/phpunit/SalaryImportPdfMatcherTest.php
 * \ingroup    test
 * \brief      PHPUnit test for SalaryImportPdfMatcher class
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../class/SalaryImportPdfMatcher.class.php';
require_once dirname(__FILE__).'/../../../../test/phpunit/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class SalaryImportPdfMatcherTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class SalaryImportPdfMatcherTest extends CommonClassTest
{
	/**
	 * @var SalaryImportPdfMatcher
	 */
	private $matcher;

	/**
	 * @var string Test directory
	 */
	private $testDir;

	/**
	 * setUp
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->testDir = sys_get_temp_dir().'/salaryimport_test_'.uniqid();
		mkdir($this->testDir, 0755, true);
		$this->matcher = new SalaryImportPdfMatcher($this->testDir);
	}

	/**
	 * tearDown
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		// Cleanup test directory
		if (is_dir($this->testDir)) {
			$this->recursiveDelete($this->testDir);
		}
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory
	 *
	 * @param string $dir Directory path
	 * @return void
	 */
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

	/**
	 * Test normalizeString with simple ASCII
	 *
	 * @return void
	 */
	public function testNormalizeStringSimple()
	{
		$result = $this->matcher->normalizeString('Jean');
		$this->assertEquals('jean', $result);
	}

	/**
	 * Test normalizeString with accents
	 *
	 * @return void
	 */
	public function testNormalizeStringWithAccents()
	{
		$result = $this->matcher->normalizeString('François');
		$this->assertEquals('francois', $result);

		$result = $this->matcher->normalizeString('Éléonore');
		$this->assertEquals('eleonore', $result);
	}

	/**
	 * Test normalizeString with special characters
	 *
	 * @return void
	 */
	public function testNormalizeStringWithSpecialChars()
	{
		$result = $this->matcher->normalizeString('Jean-Pierre');
		$this->assertEquals('jean-pierre', $result);

		$result = $this->matcher->normalizeString("O'Brien");
		$this->assertEquals('o-brien', $result);
	}

	/**
	 * Test matchesUserName positive case
	 *
	 * @return void
	 */
	public function testMatchesUserNamePositive()
	{
		$this->assertTrue($this->matcher->matchesUserName('jean', 'Jean', 'Dupont'));
		$this->assertTrue($this->matcher->matchesUserName('dupont', 'Jean', 'Dupont'));
	}

	/**
	 * Test matchesUserName negative case
	 *
	 * @return void
	 */
	public function testMatchesUserNameNegative()
	{
		$this->assertFalse($this->matcher->matchesUserName('marie', 'Jean', 'Dupont'));
		$this->assertFalse($this->matcher->matchesUserName('martin', 'Jean', 'Dupont'));
	}

	/**
	 * Test matchesUserName with accents
	 *
	 * @return void
	 */
	public function testMatchesUserNameWithAccents()
	{
		$this->assertTrue($this->matcher->matchesUserName('francois', 'François', 'Martin'));
		$this->assertTrue($this->matcher->matchesUserName('françois', 'François', 'Martin'));
	}

	/**
	 * Test scanDirectoryForPdfs
	 *
	 * @return void
	 */
	public function testScanDirectoryForPdfs()
	{
		// Create test PDF files
		touch($this->testDir.'/jean_dupont.pdf');
		touch($this->testDir.'/marie_martin.pdf');
		touch($this->testDir.'/not_a_pdf.txt');

		$pdfs = $this->matcher->scanDirectoryForPdfs($this->testDir);

		$this->assertCount(2, $pdfs);

		// Check first PDF
		$this->assertEquals('jean_dupont.pdf', $pdfs[0]['filename']);
		$this->assertEquals($this->testDir.'/jean_dupont.pdf', $pdfs[0]['path']);
		$this->assertEquals(array('jean', 'dupont'), $pdfs[0]['links']);
	}

	/**
	 * Test scanDirectoryForPdfs with empty directory
	 *
	 * @return void
	 */
	public function testScanDirectoryForPdfsEmpty()
	{
		$pdfs = $this->matcher->scanDirectoryForPdfs($this->testDir);
		$this->assertEmpty($pdfs);
	}

	/**
	 * Test findPdfForUser
	 *
	 * @return void
	 */
	public function testFindPdfForUser()
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

	/**
	 * Test findPdfForUser returns null when not found
	 *
	 * @return void
	 */
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

	/**
	 * Test getWorkDir
	 *
	 * @return void
	 */
	public function testGetWorkDir()
	{
		$this->assertEquals($this->testDir, $this->matcher->getWorkDir());
	}

	/**
	 * Test cleanup
	 *
	 * @return void
	 */
	public function testCleanup()
	{
		// Create test folder and files
		$folderName = 'test_folder';
		mkdir($this->testDir.'/'.$folderName, 0755);
		touch($this->testDir.'/'.$folderName.'/test.pdf');
		touch($this->testDir.'/test.zip');

		$result = $this->matcher->cleanup($folderName, 'test.zip');

		$this->assertEquals(1, $result);
		$this->assertFalse(is_dir($this->testDir.'/'.$folderName));
		$this->assertFalse(file_exists($this->testDir.'/test.zip'));
	}
}
