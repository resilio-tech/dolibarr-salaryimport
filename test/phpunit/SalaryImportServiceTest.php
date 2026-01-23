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
 * \file       test/phpunit/SalaryImportServiceTest.php
 * \ingroup    test
 * \brief      PHPUnit integration test for SalaryImportService class
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
// IMPORTANT: Load our patched File class BEFORE PhpSpreadsheet
require_once dirname(__FILE__).'/../../lib/PhpSpreadsheetFileFix.php';
require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
require_once dirname(__FILE__).'/../../class/SalaryImportService.class.php';
require_once dirname(__FILE__).'/../../../../test/phpunit/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class SalaryImportServiceTest
 *
 * Integration test for the main service
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class SalaryImportServiceTest extends CommonClassTest
{
	/**
	 * @var SalaryImportService
	 */
	private $service;

	/**
	 * setUp
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		global $db, $user;
		$this->service = new SalaryImportService($db, $user);
	}

	/**
	 * Test constructor creates all components
	 *
	 * @return void
	 */
	public function testConstructorCreatesComponents()
	{
		$this->assertInstanceOf('SalaryImportParser', $this->service->getParser());
		$this->assertInstanceOf('SalaryImportValidator', $this->service->getValidator());
		$this->assertInstanceOf('SalaryImportUserLookup', $this->service->getUserLookup());
		$this->assertInstanceOf('SalaryImportPdfMatcher', $this->service->getPdfMatcher());
		$this->assertInstanceOf('SalaryImportPersister', $this->service->getPersister());
	}

	/**
	 * Test dependency injection
	 *
	 * @return void
	 */
	public function testDependencyInjection()
	{
		global $db, $user;

		// Create mock objects
		$mockParser = new SalaryImportParser();
		$mockValidator = new SalaryImportValidator();

		$service = new SalaryImportService(
			$db,
			$user,
			$mockParser,
			$mockValidator
		);

		$this->assertSame($mockParser, $service->getParser());
		$this->assertSame($mockValidator, $service->getValidator());
	}

	/**
	 * Test getWorkDir
	 *
	 * @return void
	 */
	public function testGetWorkDir()
	{
		$workDir = $this->service->getWorkDir();
		$this->assertStringContainsString('salaryimport', $workDir);
	}

	/**
	 * Test handleXlsxUpload with upload error
	 *
	 * @return void
	 */
	public function testHandleXlsxUploadError()
	{
		$fileData = array(
			'name' => 'test.xlsx',
			'tmp_name' => '/tmp/test.xlsx',
			'error' => UPLOAD_ERR_NO_FILE,
			'size' => 0
		);

		$result = $this->service->handleXlsxUpload($fileData);

		$this->assertEquals(-1, $result);
		$this->assertNotEmpty($this->service->errors);
	}

	/**
	 * Test handleXlsxUpload with wrong extension
	 *
	 * @return void
	 */
	public function testHandleXlsxUploadWrongExtension()
	{
		$fileData = array(
			'name' => 'test.csv',
			'tmp_name' => '/tmp/test.csv',
			'error' => 0,
			'size' => 100
		);

		$result = $this->service->handleXlsxUpload($fileData);

		$this->assertEquals(-2, $result);
		$this->assertContains('Le fichier de salaire doit être au format xlsx', $this->service->errors);
	}

	/**
	 * Test handleZipUpload with no file
	 *
	 * @return void
	 */
	public function testHandleZipUploadNoFile()
	{
		$fileData = array(
			'name' => '',
			'tmp_name' => '',
			'error' => 0,
			'size' => 0
		);

		$result = $this->service->handleZipUpload($fileData);

		$this->assertEquals(0, $result); // No file is not an error
	}

	/**
	 * Test handleZipUpload with wrong extension
	 *
	 * @return void
	 */
	public function testHandleZipUploadWrongExtension()
	{
		$fileData = array(
			'name' => 'test.rar',
			'tmp_name' => '/tmp/test.rar',
			'error' => 0,
			'size' => 100
		);

		$result = $this->service->handleZipUpload($fileData);

		$this->assertEquals(-2, $result);
		$this->assertContains('Le fichier de PDF doit être au format zip', $this->service->errors);
	}

	/**
	 * Test processForPreview without uploaded file
	 *
	 * @return void
	 */
	public function testProcessForPreviewWithoutUpload()
	{
		$result = $this->service->processForPreview();

		$this->assertEquals(-1, $result);
		$this->assertContains('No XLSX file uploaded', $this->service->errors);
	}

	/**
	 * Test getPreviewData initially empty
	 *
	 * @return void
	 */
	public function testGetPreviewDataEmpty()
	{
		$this->assertEmpty($this->service->getPreviewData());
	}

	/**
	 * Test setPreviewData
	 *
	 * @return void
	 */
	public function testSetPreviewData()
	{
		$data = array(
			array('userId' => 1, 'userName' => 'Test User')
		);

		$this->service->setPreviewData($data);

		$this->assertEquals($data, $this->service->getPreviewData());
	}

	/**
	 * Test serializeForForm
	 *
	 * @return void
	 */
	public function testSerializeForForm()
	{
		$data = array(
			0 => array(
				'userId' => 1,
				'userName' => 'Test User',
				'datep' => '2024-01-01',
				'amount' => 1500.00,
				'typepayment' => 1,
				'typepaymentcode' => 'VIR',
				'label' => 'Test',
				'datesp' => '2024-01-01',
				'dateep' => '2024-01-31',
				'paye' => 1,
				'account' => 1,
				'pdf' => '/path/to/file.pdf'
			)
		);

		$this->service->setPreviewData($data);
		$serialized = $this->service->serializeForForm();

		$this->assertArrayHasKey(0, $serialized);
		$this->assertEquals(1, $serialized[0]['userId']);
		$this->assertEquals('Test User', $serialized[0]['userName']);
	}

	/**
	 * Test executeImport with empty data
	 *
	 * @return void
	 */
	public function testExecuteImportEmpty()
	{
		$result = $this->service->executeImport(array());

		$this->assertEquals(-1, $result);
		$this->assertContains('No data to import', $this->service->errors);
	}

	/**
	 * Test cleanup without files
	 *
	 * @return void
	 */
	public function testCleanupWithoutFiles()
	{
		$result = $this->service->cleanup();

		$this->assertEquals(1, $result);
	}

	/**
	 * Test full workflow with fixtures
	 *
	 * This test requires fixture files and proper test database setup
	 *
	 * @return void
	 */
	public function testFullWorkflowWithFixtures()
	{
		$fixtureXlsx = dirname(__FILE__).'/fixtures/valid_import.xlsx';
		$fixtureZip = dirname(__FILE__).'/fixtures/test_pdfs.zip';

		// Skip if fixtures don't exist
		if (!file_exists($fixtureXlsx)) {
			$this->markTestSkipped('Fixture files not found');
		}

		// This would be a full integration test
		// For now, we just verify the workflow methods exist
		$this->assertTrue(method_exists($this->service, 'handleXlsxUpload'));
		$this->assertTrue(method_exists($this->service, 'handleZipUpload'));
		$this->assertTrue(method_exists($this->service, 'processForPreview'));
		$this->assertTrue(method_exists($this->service, 'executeImport'));
		$this->assertTrue(method_exists($this->service, 'cleanup'));
	}
}
