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
 * \file       test/phpunit/SalaryImportValidatorTest.php
 * \ingroup    test
 * \brief      PHPUnit test for SalaryImportValidator class
 */

global $conf, $user, $langs, $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once dirname(__FILE__).'/../../class/SalaryImportValidator.class.php';
require_once dirname(__FILE__).'/../../../../test/phpunit/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class SalaryImportValidatorTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class SalaryImportValidatorTest extends CommonClassTest
{
	/**
	 * @var SalaryImportValidator
	 */
	private $validator;

	/**
	 * setUp
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->validator = new SalaryImportValidator();
	}

	/**
	 * Test parseExcelDate with valid Excel serial date
	 *
	 * @return void
	 */
	public function testParseExcelDateValid()
	{
		// Excel date 44197 = 2021-01-01
		$result = $this->validator->parseExcelDate(44197);
		$this->assertEquals('2021-01-01', $result);

		// Excel date 45292 = 2024-01-01
		$result = $this->validator->parseExcelDate(45292);
		$this->assertEquals('2024-01-01', $result);
	}

	/**
	 * Test parseExcelDate with string date
	 *
	 * @return void
	 */
	public function testParseExcelDateString()
	{
		$result = $this->validator->parseExcelDate('2024-01-15');
		$this->assertEquals('2024-01-15', $result);
	}

	/**
	 * Test parseExcelDate with empty value
	 *
	 * @return void
	 */
	public function testParseExcelDateEmpty()
	{
		$this->assertFalse($this->validator->parseExcelDate(null));
		$this->assertFalse($this->validator->parseExcelDate(''));
	}

	/**
	 * Test formatDateForDisplay
	 *
	 * @return void
	 */
	public function testFormatDateForDisplay()
	{
		// Excel date 44197 = 2021-01-01 = 01/01/2021
		$result = $this->validator->formatDateForDisplay(44197);
		$this->assertEquals('01/01/2021', $result);
	}

	/**
	 * Test parseAmount with various formats
	 *
	 * @return void
	 */
	public function testParseAmount()
	{
		// Numeric value
		$this->assertEquals(1500.50, $this->validator->parseAmount(1500.50));

		// String with dot
		$this->assertEquals(1500.50, $this->validator->parseAmount('1500.50'));

		// String with comma (French format)
		$this->assertEquals(1500.50, $this->validator->parseAmount('1500,50'));

		// Zero values
		$this->assertEquals(0.0, $this->validator->parseAmount(0));
		$this->assertEquals(0.0, $this->validator->parseAmount('0'));
	}

	/**
	 * Test parseAmount with invalid values
	 *
	 * @return void
	 */
	public function testParseAmountInvalid()
	{
		$this->assertFalse($this->validator->parseAmount(null));
		$this->assertFalse($this->validator->parseAmount(''));
		$this->assertFalse($this->validator->parseAmount('abc'));
	}

	/**
	 * Test parsePaye with valid values
	 *
	 * @return void
	 */
	public function testParsePaye()
	{
		// French values
		$this->assertEquals(1, $this->validator->parsePaye('oui'));
		$this->assertEquals(0, $this->validator->parsePaye('non'));

		// English values
		$this->assertEquals(1, $this->validator->parsePaye('yes'));
		$this->assertEquals(0, $this->validator->parsePaye('no'));

		// Numeric values
		$this->assertEquals(1, $this->validator->parsePaye('1'));
		$this->assertEquals(0, $this->validator->parsePaye('0'));

		// Case insensitive
		$this->assertEquals(1, $this->validator->parsePaye('OUI'));
		$this->assertEquals(0, $this->validator->parsePaye('NON'));
	}

	/**
	 * Test parsePaye with invalid values
	 *
	 * @return void
	 */
	public function testParsePayeInvalid()
	{
		$this->assertFalse($this->validator->parsePaye(null));
		$this->assertFalse($this->validator->parsePaye(''));
		$this->assertFalse($this->validator->parsePaye('maybe'));
	}

	/**
	 * Test validateRow with valid data
	 *
	 * @return void
	 */
	public function testValidateRowValid()
	{
		$line = array(
			'Prénom' => 'Jean',
			'Nom' => 'Dupont',
			'Date de paiement' => 45292, // 2024-01-01
			'Montant' => '1500,50',
			'Libellé' => 'Salaire janvier',
			'Date de début' => 45261, // 2023-12-01
			'Date de fin' => 45291, // 2023-12-31
			'Type de paiement' => 'VIR',
			'Payé' => 'oui',
			'Compte bancaire' => 'BNP'
		);

		$result = $this->validator->validateRow($line, 2);

		$this->assertNotEmpty($result);
		$this->assertEquals('Jean', $result['firstname']);
		$this->assertEquals('Dupont', $result['lastname']);
		$this->assertEquals('2024-01-01', $result['datep']);
		$this->assertEquals(1500.50, $result['amount']);
		$this->assertEquals('Salaire janvier', $result['label']);
		$this->assertEquals('VIR', $result['typepayment_code']);
		$this->assertEquals(1, $result['paye']);
		$this->assertEquals('BNP', $result['account_ref']);
		$this->assertTrue($this->validator->isValid());
	}

	/**
	 * Test validateRow with missing firstname
	 *
	 * @return void
	 */
	public function testValidateRowMissingName()
	{
		$line = array(
			'Prénom' => '',
			'Nom' => 'Dupont',
			'Date de paiement' => 45292,
			'Montant' => '1500',
			'Libellé' => 'Salaire',
			'Date de début' => 45261,
			'Date de fin' => 45291,
			'Type de paiement' => 'VIR',
			'Payé' => 'oui',
			'Compte bancaire' => 'BNP'
		);

		$result = $this->validator->validateRow($line, 2);

		$this->assertEmpty($result);
		$this->assertFalse($this->validator->isValid());
		$this->assertContains('Prénom ou nom vide à la ligne 2', $this->validator->errors);
	}

	/**
	 * Test validateRow with invalid date
	 *
	 * @return void
	 */
	public function testValidateRowInvalidDate()
	{
		$line = array(
			'Prénom' => 'Jean',
			'Nom' => 'Dupont',
			'Date de paiement' => '',
			'Montant' => '1500',
			'Libellé' => 'Salaire',
			'Date de début' => 45261,
			'Date de fin' => 45291,
			'Type de paiement' => 'VIR',
			'Payé' => 'oui',
			'Compte bancaire' => 'BNP'
		);

		$result = $this->validator->validateRow($line, 2);

		$this->assertEmpty($result);
		$this->assertFalse($this->validator->isValid());
		$this->assertContains('Date de paiement vide à la ligne 2', $this->validator->errors);
	}

	/**
	 * Test validateRow with invalid paye value
	 *
	 * @return void
	 */
	public function testValidateRowInvalidPaye()
	{
		$line = array(
			'Prénom' => 'Jean',
			'Nom' => 'Dupont',
			'Date de paiement' => 45292,
			'Montant' => '1500',
			'Libellé' => 'Salaire',
			'Date de début' => 45261,
			'Date de fin' => 45291,
			'Type de paiement' => 'VIR',
			'Payé' => 'maybe',
			'Compte bancaire' => 'BNP'
		);

		$result = $this->validator->validateRow($line, 2);

		$this->assertEmpty($result);
		$this->assertFalse($this->validator->isValid());
		$this->assertContains('Payé invalide (doit être oui/non) à la ligne 2', $this->validator->errors);
	}

	/**
	 * Test validateAll with multiple rows
	 *
	 * @return void
	 */
	public function testValidateAll()
	{
		$lines = array(
			array(
				'Prénom' => 'Jean',
				'Nom' => 'Dupont',
				'Date de paiement' => 45292,
				'Montant' => '1500',
				'Libellé' => 'Salaire janvier',
				'Date de début' => 45261,
				'Date de fin' => 45291,
				'Type de paiement' => 'VIR',
				'Payé' => 'oui',
				'Compte bancaire' => 'BNP'
			),
			array(
				'Prénom' => 'Marie',
				'Nom' => 'Martin',
				'Date de paiement' => 45292,
				'Montant' => '2000',
				'Libellé' => 'Salaire janvier',
				'Date de début' => 45261,
				'Date de fin' => 45291,
				'Type de paiement' => 'VIR',
				'Payé' => 'oui',
				'Compte bancaire' => 'BNP'
			)
		);

		$result = $this->validator->validateAll($lines);

		$this->assertCount(2, $result);
		$this->assertTrue($this->validator->isValid());
	}

	/**
	 * Test getRequiredFields
	 *
	 * @return void
	 */
	public function testGetRequiredFields()
	{
		$fields = $this->validator->getRequiredFields();

		$this->assertContains('Prénom', $fields);
		$this->assertContains('Nom', $fields);
		$this->assertContains('Date de paiement', $fields);
		$this->assertContains('Montant', $fields);
	}
}
