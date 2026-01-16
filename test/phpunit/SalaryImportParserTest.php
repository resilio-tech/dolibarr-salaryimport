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
 * \file       test/phpunit/SalaryImportParserTest.php
 * \ingroup    test
 * \brief      PHPUnit test for SalaryImportParser class
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
require_once dirname(__FILE__).'/../../class/SalaryImportParser.class.php';
require_once dirname(__FILE__).'/../../../../test/phpunit/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class SalaryImportParserTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class SalaryImportParserTest extends CommonClassTest
{
	/**
	 * @var SalaryImportParser
	 */
	private $parser;

	/**
	 * setUp
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->parser = new SalaryImportParser();
	}

	/**
	 * Test fixEncoding with normal UTF-8
	 *
	 * @return void
	 */
	public function testFixEncodingNormal()
	{
		$result = $this->parser->fixEncoding('Test');
		$this->assertEquals('Test', $result);
	}

	/**
	 * Test fixEncoding with non-string value
	 *
	 * @return void
	 */
	public function testFixEncodingNonString()
	{
		$this->assertEquals(123, $this->parser->fixEncoding(123));
		$this->assertNull($this->parser->fixEncoding(null));
	}

	/**
	 * Test fixEncoding with double-encoded UTF-8
	 *
	 * @return void
	 */
	public function testFixEncodingDoubleEncoded()
	{
		// "é" double encoded becomes "Ã©"
		$doubleEncoded = utf8_encode('é');
		$result = $this->parser->fixEncoding($doubleEncoded);
		$this->assertEquals('é', $result);
	}

	/**
	 * Test parseFile with non-existent file
	 *
	 * @return void
	 */
	public function testParseFileNotFound()
	{
		$result = $this->parser->parseFile('/nonexistent/file.xlsx');

		$this->assertEquals(-1, $result);
		$this->assertNotEmpty($this->parser->errors);
		$this->assertStringContainsString('not found', $this->parser->errors[0]);
	}

	/**
	 * Test parseFile with wrong extension
	 *
	 * @return void
	 */
	public function testParseFileWrongExtension()
	{
		// Create a temp file with wrong extension
		$tempFile = sys_get_temp_dir().'/test_'.uniqid().'.csv';
		touch($tempFile);

		$result = $this->parser->parseFile($tempFile);

		$this->assertEquals(-3, $result);
		$this->assertNotEmpty($this->parser->errors);
		$this->assertStringContainsString('XLSX', $this->parser->errors[0]);

		unlink($tempFile);
	}

	/**
	 * Test hasColumn
	 *
	 * @return void
	 */
	public function testHasColumn()
	{
		// Before parsing, no columns exist
		$this->assertFalse($this->parser->hasColumn('Test'));
	}

	/**
	 * Test getters before parsing
	 *
	 * @return void
	 */
	public function testGettersBeforeParsing()
	{
		$this->assertEquals(array(), $this->parser->getHeaders());
		$this->assertEquals(array(), $this->parser->getLines());
		$this->assertEquals(0, $this->parser->getRowCount());
		$this->assertNull($this->parser->getLine(0));
		$this->assertNull($this->parser->getValue(0, 'Test'));
		$this->assertNull($this->parser->getFilePath());
	}

	/**
	 * Test parseFile with valid XLSX file from fixtures
	 *
	 * @return void
	 */
	public function testParseFileWithFixture()
	{
		$fixtureFile = dirname(__FILE__).'/fixtures/valid_import.xlsx';

		// Skip if fixture doesn't exist
		if (!file_exists($fixtureFile)) {
			$this->markTestSkipped('Fixture file not found: '.$fixtureFile);
		}

		$result = $this->parser->parseFile($fixtureFile);

		$this->assertEquals(1, $result);
		$this->assertEmpty($this->parser->errors);
		$this->assertNotEmpty($this->parser->getHeaders());
		$this->assertGreaterThan(0, $this->parser->getRowCount());
	}
}
