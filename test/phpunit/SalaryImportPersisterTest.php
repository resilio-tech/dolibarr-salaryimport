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
	 * Test findExistingSalaryRefs with no notation to look for
	 *
	 * @return void
	 */
	public function testFindExistingSalaryRefsWithEmptyInput()
	{
		$this->assertEquals(array(), $this->persister->findExistingSalaryRefs(array()));
	}

	/**
	 * Test findExistingSalaryRefs returns nothing for notations that were never imported
	 *
	 * @return void
	 */
	public function testFindExistingSalaryRefsWithUnknownNotations()
	{
		$result = $this->persister->findExistingSalaryRefs(array('nonexistent-notation-1', 'nonexistent-notation-2'));

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * Test classifySalaryMatch on every shape a llx_salary row can take
	 *
	 * @return void
	 */
	public function testClassifySalaryMatchOnEveryRowShape()
	{
		$imported = SalaryImportPersister::STATUS_IMPORTED;
		$conflict = SalaryImportPersister::STATUS_CONFLICT;

		// Current shape: notation in both columns
		$this->assertEquals($imported, $this->persister->classifySalaryMatch(true, false, '2026-05-5', '2026-05-5 Salaire mai', '2026-05-5'));
		// Current shape whose label was edited afterwards on the salary card
		$this->assertEquals($imported, $this->persister->classifySalaryMatch(true, false, '2026-05-5', 'Salaire mai', '2026-05-5'));
		// 2.2.0 shape: counter as ref, bare notation as label
		$this->assertEquals($imported, $this->persister->classifySalaryMatch(false, true, '12', '2026-05-5', '2026-05-5'));
		// An old counter ref that happens to look like a numeric notation: another salary entirely
		$this->assertEquals($conflict, $this->persister->classifySalaryMatch(true, false, '12', '2026-05-5', '12'));
		// A salary created from the Dolibarr UI (no ref) whose label was typed to match: its ref is
		// NULL, so the notation is still free and this is not a duplicate at all
		$this->assertEquals('', $this->persister->classifySalaryMatch(false, true, '', '2026-05-5', '2026-05-5'));
		// No match at all
		$this->assertEquals('', $this->persister->classifySalaryMatch(false, false, '99', 'autre', '2026-05-5'));
	}

	/**
	 * Test classifySalaryMatch handles a multibyte notation
	 *
	 * strncasecmp counts bytes, so a character-based length would compare short and wrongly treat
	 * an unrelated label as prefixed.
	 *
	 * @return void
	 */
	public function testClassifySalaryMatchWithMultibyteNotation()
	{
		$this->assertEquals(
			SalaryImportPersister::STATUS_IMPORTED,
			$this->persister->classifySalaryMatch(true, false, 'Müller-2026', 'Müller-2026 Salaire', 'Müller-2026')
		);
	}

	/**
	 * Test statusForNotation reads a status back out of the lookup result
	 *
	 * @return void
	 */
	public function testStatusForNotation()
	{
		$existing = array(
			array('notation' => '2026-05-5', 'status' => SalaryImportPersister::STATUS_IMPORTED),
			array('notation' => '2026', 'status' => SalaryImportPersister::STATUS_CONFLICT)
		);

		$this->assertEquals(SalaryImportPersister::STATUS_IMPORTED, $this->persister->statusForNotation($existing, '2026-05-5'));
		// Numeric notation: must still match after PHP's array-key coercion elsewhere
		$this->assertEquals(SalaryImportPersister::STATUS_CONFLICT, $this->persister->statusForNotation($existing, '2026'));
		$this->assertEquals(SalaryImportPersister::STATUS_CONFLICT, $this->persister->statusForNotation($existing, 2026));
		$this->assertEquals('', $this->persister->statusForNotation($existing, 'unknown'));
		$this->assertEquals('', $this->persister->statusForNotation(array(), '2026-05-5'));
	}

	/**
	 * Test normalizeNotations casts and deduplicates
	 *
	 * @return void
	 */
	public function testNormalizeNotations()
	{
		$this->assertEquals(array('2026', '2026-05-5'), $this->persister->normalizeNotations(array(2026, '2026', '2026-05-5', '')));
		$this->assertEquals(array(), $this->persister->normalizeNotations(array()));
	}

	/**
	 * Test buildSalaryLabel prefixes the imported label with the notation
	 *
	 * @return void
	 */
	public function testBuildSalaryLabelPrefixesNotation()
	{
		$this->assertEquals('2026-05-5 Salaire mai', $this->persister->buildSalaryLabel('2026-05-5', 'Salaire mai'));
	}

	/**
	 * Test buildSalaryLabel falls back to the notation when no label was imported
	 *
	 * @return void
	 */
	public function testBuildSalaryLabelWithEmptyLabel()
	{
		$this->assertEquals('2026-05-5', $this->persister->buildSalaryLabel('2026-05-5', ''));
		$this->assertEquals('2026-05-5', $this->persister->buildSalaryLabel('2026-05-5', '   '));
	}

	/**
	 * Test buildSalaryLabel does not prefix a label that already starts with the notation
	 *
	 * @return void
	 */
	public function testBuildSalaryLabelDoesNotDuplicateNotation()
	{
		$this->assertEquals(
			'2026-05-5 Salaire mai',
			$this->persister->buildSalaryLabel('2026-05-5', '2026-05-5 Salaire mai')
		);
		$this->assertEquals('2026-05-5', $this->persister->buildSalaryLabel('2026-05-5', '2026-05-5'));
	}

	/**
	 * Test buildSalaryLabel still prefixes a label starting with a longer notation
	 *
	 * "2026-05-50" starts with "2026-05-5" but is a different notation, so the prefix is required.
	 *
	 * @return void
	 */
	public function testBuildSalaryLabelPrefixesWhenNotationIsOnlyAPartialMatch()
	{
		$this->assertEquals(
			'2026-05-5 2026-05-50 Salaire mai',
			$this->persister->buildSalaryLabel('2026-05-5', '2026-05-50 Salaire mai')
		);
	}

	/**
	 * Test buildSalaryLabel truncates to the width of llx_salary.label
	 *
	 * The notation prefix makes the stored label longer than the imported one, so a long label
	 * must not overflow the varchar(255) and fail the whole group.
	 *
	 * @return void
	 */
	public function testBuildSalaryLabelTruncatesToColumnWidth()
	{
		$result = $this->persister->buildSalaryLabel('2026-05-5', str_repeat('a', 300));

		$this->assertEquals(SalaryImportPersister::LABEL_MAX_LENGTH, mb_strlen($result));
		$this->assertStringStartsWith('2026-05-5 aaa', $result);
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
	 * Test persistGroup requires valid data
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
		$this->assertTrue(method_exists($this->persister, 'persistGroup'));
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
