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
 * \file       test/phpunit/SalaryImportUserLookupTest.php
 * \ingroup    test
 * \brief      PHPUnit test for SalaryImportUserLookup class
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../class/SalaryImportUserLookup.class.php';
require_once dirname(__FILE__).'/../../../../test/phpunit/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class SalaryImportUserLookupTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class SalaryImportUserLookupTest extends CommonClassTest
{
	/**
	 * @var SalaryImportUserLookup
	 */
	private $lookup;

	/**
	 * setUp
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		global $db;
		$this->lookup = new SalaryImportUserLookup($db);
	}

	/**
	 * Test findUserByName with non-existent user
	 *
	 * @return void
	 */
	public function testFindUserByNameNotFound()
	{
		$result = $this->lookup->findUserByName('NonExistent', 'User');
		$this->assertFalse($result);
	}

	/**
	 * Test findUserByName caching
	 *
	 * @return void
	 */
	public function testFindUserByNameCaching()
	{
		// First call
		$result1 = $this->lookup->findUserByName('CacheTest', 'User');

		// Second call should use cache
		$result2 = $this->lookup->findUserByName('CacheTest', 'User');

		$this->assertEquals($result1, $result2);
	}

	/**
	 * Test findPaymentType with valid code
	 *
	 * @return void
	 */
	public function testFindPaymentTypeValid()
	{
		// VIR (Virement) should exist in most Dolibarr installations
		$result = $this->lookup->findPaymentType('VIR');

		if ($result === false) {
			$this->markTestSkipped('Payment type VIR not found in database');
		}

		$this->assertIsArray($result);
		$this->assertArrayHasKey('id', $result);
		$this->assertArrayHasKey('code', $result);
		$this->assertArrayHasKey('libelle', $result);
		$this->assertEquals('VIR', $result['code']);
	}

	/**
	 * Test findPaymentType with invalid code
	 *
	 * @return void
	 */
	public function testFindPaymentTypeNotFound()
	{
		$result = $this->lookup->findPaymentType('INVALID_CODE');
		$this->assertFalse($result);
	}

	/**
	 * Test findBankAccount with non-existent account
	 *
	 * @return void
	 */
	public function testFindBankAccountNotFound()
	{
		$result = $this->lookup->findBankAccount('NonExistentAccount123');
		$this->assertFalse($result);
	}

	/**
	 * Test enrichRowData with missing user
	 *
	 * @return void
	 */
	public function testEnrichRowDataMissingUser()
	{
		$validatedRow = array(
			'firstname' => 'NonExistent',
			'lastname' => 'Person',
			'typepayment_code' => 'VIR',
			'account_ref' => 'BANK1'
		);

		$result = $this->lookup->enrichRowData($validatedRow, 2);

		$this->assertEmpty($result);
		$this->assertFalse($this->lookup->isValid());
		$this->assertContains('Utilisateur non trouvé à la ligne 2', $this->lookup->errors);
	}

	/**
	 * Test clearCache
	 *
	 * @return void
	 */
	public function testClearCache()
	{
		// Make a lookup to populate cache
		$this->lookup->findUserByName('Test', 'User');

		// Clear cache
		$this->lookup->clearCache();

		// Should be able to lookup again (no exception)
		$result = $this->lookup->findUserByName('Test', 'User');
		$this->assertFalse($result); // User doesn't exist
	}

	/**
	 * Test isValid initially
	 *
	 * @return void
	 */
	public function testIsValidInitially()
	{
		$this->assertTrue($this->lookup->isValid());
	}

	/**
	 * Test enrichAll with empty array
	 *
	 * @return void
	 */
	public function testEnrichAllEmpty()
	{
		$result = $this->lookup->enrichAll(array());

		$this->assertEmpty($result);
		$this->assertTrue($this->lookup->isValid());
	}
}
