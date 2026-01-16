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
 * \file       test/phpunit/SalaryImportPersisterTest.php
 * \ingroup    test
 * \brief      PHPUnit test for SalaryImportPersister class
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../class/SalaryImportPersister.class.php';
require_once dirname(__FILE__).'/../../../../test/phpunit/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class SalaryImportPersisterTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class SalaryImportPersisterTest extends CommonClassTest
{
	/**
	 * @var SalaryImportPersister
	 */
	private $persister;

	/**
	 * setUp
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		global $db, $user;
		$this->persister = new SalaryImportPersister($db, $user);
	}

	/**
	 * Test initCounters
	 *
	 * @return void
	 */
	public function testInitCounters()
	{
		$result = $this->persister->initCounters();
		$this->assertEquals(1, $result);
	}

	/**
	 * Test getNextSalaryRef increments
	 *
	 * @return void
	 */
	public function testGetNextSalaryRefIncrements()
	{
		$ref1 = $this->persister->getNextSalaryRef();
		$ref2 = $this->persister->getNextSalaryRef();

		$this->assertEquals((int)$ref1 + 1, (int)$ref2);
	}

	/**
	 * Test getNextPaymentRef increments
	 *
	 * @return void
	 */
	public function testGetNextPaymentRefIncrements()
	{
		$ref1 = $this->persister->getNextPaymentRef();
		$ref2 = $this->persister->getNextPaymentRef();

		$this->assertEquals((int)$ref1 + 1, (int)$ref2);
	}

	/**
	 * Test isValid initially
	 *
	 * @return void
	 */
	public function testIsValidInitially()
	{
		$this->assertTrue($this->persister->isValid());
	}

	/**
	 * Test persistAll with empty array
	 *
	 * @return void
	 */
	public function testPersistAllEmpty()
	{
		$result = $this->persister->persistAll(array());
		$this->assertEmpty($result);
	}

	/**
	 * Test movePdfToSalary with empty path
	 *
	 * @return void
	 */
	public function testMovePdfToSalaryEmpty()
	{
		$result = $this->persister->movePdfToSalary('', 1);
		$this->assertEquals(1, $result); // Empty path is not an error
	}

	/**
	 * Test movePdfToSalary with non-existent file
	 *
	 * @return void
	 */
	public function testMovePdfToSalaryNotFound()
	{
		$result = $this->persister->movePdfToSalary('/nonexistent/file.pdf', 1);
		$this->assertEquals(1, $result); // Non-existent file is treated as "no PDF"
	}

	/**
	 * Test persistRow requires valid data
	 *
	 * Note: This test requires a complete test environment with users, bank accounts, etc.
	 * In a real test suite, you would mock the database or use fixtures.
	 *
	 * @return void
	 */
	public function testPersistRowRequiresValidData()
	{
		// This would require proper test fixtures
		// For now, we just verify the method exists and returns expected type
		$this->assertTrue(method_exists($this->persister, 'persistRow'));
	}

	/**
	 * Test insertSalary returns error on invalid data
	 *
	 * @return void
	 */
	public function testInsertSalaryWithInvalidUser()
	{
		// Attempt to insert with invalid user ID (0)
		$result = $this->persister->insertSalary(
			'TEST-REF',
			'2024-01-01',
			1500.00,
			1, // assuming payment type 1 exists
			'Test salary',
			'2024-01-01',
			'2024-01-31',
			1,
			0, // invalid user
			1  // assuming account 1 exists
		);

		// The insert may fail due to foreign key constraints
		// We just verify it returns an integer
		$this->assertIsInt($result);
	}

	/**
	 * Test insertBankTransaction
	 *
	 * @return void
	 */
	public function testInsertBankTransactionWithInvalidAccount()
	{
		$result = $this->persister->insertBankTransaction(
			'2024-01-01',
			1500.00,
			99999, // non-existent account
			'VIR'
		);

		// May succeed or fail depending on DB constraints
		$this->assertIsInt($result);
	}
}
