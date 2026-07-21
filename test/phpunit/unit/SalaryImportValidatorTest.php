<?php
/**
 * Standalone unit tests for SalaryImportValidator
 * No Dolibarr dependency required
 *
 * Run with: phpunit htdocs/custom/salaryimport/test/phpunit/unit/SalaryImportValidatorTest.php
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__).'/../../../class/SalaryImportValidator.class.php';
require_once dirname(__FILE__).'/LangsMock.php';

class SalaryImportValidatorTest extends TestCase
{
	/**
	 * @var SalaryImportValidator
	 */
	private $validator;

	protected function setUp(): void
	{
		initLangsMock();
		$this->validator = new SalaryImportValidator();
	}

	// ========================================
	// Tests for parseExcelDate()
	// ========================================

	public function testParseExcelDateValidSerial()
	{
		// Excel date 44197 = 2021-01-01
		$result = $this->validator->parseExcelDate(44197);
		$this->assertEquals('2021-01-01', $result);
	}

	public function testParseExcelDateValidSerial2024()
	{
		// Excel date 45292 = 2024-01-01
		$result = $this->validator->parseExcelDate(45292);
		$this->assertEquals('2024-01-01', $result);
	}

	public function testParseExcelDateValidSerial2024Jan31()
	{
		// Excel date 45322 = 2024-01-31
		$result = $this->validator->parseExcelDate(45322);
		$this->assertEquals('2024-01-31', $result);
	}

	public function testParseExcelDateStringFormat()
	{
		$result = $this->validator->parseExcelDate('2024-01-15');
		$this->assertEquals('2024-01-15', $result);
	}

	public function testParseExcelDateEmpty()
	{
		$this->assertFalse($this->validator->parseExcelDate(null));
		$this->assertFalse($this->validator->parseExcelDate(''));
		$this->assertFalse($this->validator->parseExcelDate(0));
	}

	// ========================================
	// Tests for formatDateForDisplay()
	// ========================================

	public function testFormatDateForDisplay()
	{
		// Excel date 45292 = 2024-01-01 = 01/01/2024
		$result = $this->validator->formatDateForDisplay(45292);
		$this->assertEquals('01/01/2024', $result);
	}

	public function testFormatDateForDisplayEndOfMonth()
	{
		// Excel date 45322 = 2024-01-31 = 31/01/2024
		$result = $this->validator->formatDateForDisplay(45322);
		$this->assertEquals('31/01/2024', $result);
	}

	public function testFormatDateForDisplayEmpty()
	{
		$this->assertFalse($this->validator->formatDateForDisplay(null));
		$this->assertFalse($this->validator->formatDateForDisplay(''));
	}

	// ========================================
	// Tests for parseAmount()
	// ========================================

	public function testParseAmountNumeric()
	{
		$this->assertEquals(1500.50, $this->validator->parseAmount(1500.50));
		$this->assertEquals(1500.0, $this->validator->parseAmount(1500));
	}

	public function testParseAmountStringWithDot()
	{
		$this->assertEquals(1500.50, $this->validator->parseAmount('1500.50'));
	}

	public function testParseAmountStringWithComma()
	{
		// French format with comma as decimal separator
		$this->assertEquals(1500.50, $this->validator->parseAmount('1500,50'));
	}

	public function testParseAmountWithSpaces()
	{
		$this->assertEquals(1500.50, $this->validator->parseAmount('1 500,50'));
	}

	public function testParseAmountZero()
	{
		$this->assertEquals(0.0, $this->validator->parseAmount(0));
		$this->assertEquals(0.0, $this->validator->parseAmount('0'));
		$this->assertEquals(0.0, $this->validator->parseAmount('0.00'));
	}

	public function testParseAmountInvalid()
	{
		$this->assertFalse($this->validator->parseAmount(null));
		$this->assertFalse($this->validator->parseAmount(''));
		$this->assertFalse($this->validator->parseAmount('abc'));
		$this->assertFalse($this->validator->parseAmount('12abc'));
	}

	// ========================================
	// Tests for parsePaye()
	// ========================================

	public function testParsePayeFrenchYes()
	{
		$this->assertEquals(1, $this->validator->parsePaye('oui'));
		$this->assertEquals(1, $this->validator->parsePaye('OUI'));
		$this->assertEquals(1, $this->validator->parsePaye('Oui'));
	}

	public function testParsePayeFrenchNo()
	{
		$this->assertEquals(0, $this->validator->parsePaye('non'));
		$this->assertEquals(0, $this->validator->parsePaye('NON'));
		$this->assertEquals(0, $this->validator->parsePaye('Non'));
	}

	public function testParsePayeEnglish()
	{
		$this->assertEquals(1, $this->validator->parsePaye('yes'));
		$this->assertEquals(1, $this->validator->parsePaye('YES'));
		$this->assertEquals(0, $this->validator->parsePaye('no'));
		$this->assertEquals(0, $this->validator->parsePaye('NO'));
	}

	public function testParsePayeNumeric()
	{
		$this->assertEquals(1, $this->validator->parsePaye('1'));
		$this->assertEquals(0, $this->validator->parsePaye('0'));
	}

	public function testParsePayeInvalid()
	{
		$this->assertFalse($this->validator->parsePaye(null));
		$this->assertFalse($this->validator->parsePaye(''));
		$this->assertFalse($this->validator->parsePaye('maybe'));
		$this->assertFalse($this->validator->parsePaye('peut-etre'));
		$this->assertFalse($this->validator->parsePaye('2'));
	}

	public function testParsePayeWithWhitespace()
	{
		$this->assertEquals(1, $this->validator->parsePaye(' oui '));
		$this->assertEquals(0, $this->validator->parsePaye(' non '));
	}

	// ========================================
	// Tests for validateRow()
	// ========================================

	public function testValidateRowValid()
	{
		$line = $this->getValidLine();

		$result = $this->validator->validateRow($line, 2);

		$this->assertNotEmpty($result);
		$this->assertEquals('2024-01-1', $result['salary_notation']);
		$this->assertEquals('2024-01-1-CHF', $result['payment_ref']);
		$this->assertEquals('Jean', $result['firstname']);
		$this->assertEquals('Dupont', $result['lastname']);
		$this->assertEquals('2024-01-31', $result['datep']);
		$this->assertEquals('31/01/2024', $result['datep_display']);
		$this->assertEquals(1500.50, $result['amount_nominal']);
		$this->assertEquals(1500.50, $result['amount_chf']);
		$this->assertEquals(1500.50, $result['total_salary_chf']);
		$this->assertEquals('Salaire janvier', $result['label']);
		$this->assertEquals('2024-01-01', $result['datesp']);
		$this->assertEquals('2024-01-31', $result['dateep']);
		$this->assertEquals('VIR', $result['typepayment_code']);
		$this->assertEquals(1, $result['paye']);
		$this->assertEquals('BNP', $result['account_ref']);
		$this->assertEquals(2, $result['row_num']);
		$this->assertTrue($this->validator->isValid());
	}

	public function testValidateRowAmountChfDefaultsToNominal()
	{
		$line = $this->getValidLine();
		unset($line['Montant CHF']); // optional column omitted

		$result = $this->validator->validateRow($line, 2);

		$this->assertNotEmpty($result);
		$this->assertEquals(1500.50, $result['amount_nominal']);
		$this->assertEquals(1500.50, $result['amount_chf']); // defaulted to nominal
	}

	public function testValidateRowMissingSalaryNotation()
	{
		$line = $this->getValidLine();
		$line['Salaire'] = '';

		$result = $this->validator->validateRow($line, 2);

		$this->assertEmpty($result);
		$this->assertContains('Notation de salaire vide à la ligne 2', $this->validator->errors);
	}

	public function testValidateRowMissingPaymentRef()
	{
		$line = $this->getValidLine();
		$line['Réf paiement'] = '';

		$result = $this->validator->validateRow($line, 2);

		$this->assertEmpty($result);
		$this->assertContains('Référence de paiement vide à la ligne 2', $this->validator->errors);
	}

	public function testValidateRowMissingTotalSalary()
	{
		$line = $this->getValidLine();
		$line['Salaire total CHF'] = '';

		$result = $this->validator->validateRow($line, 2);

		$this->assertEmpty($result);
		$this->assertContains('Salaire total CHF vide ou invalide à la ligne 2', $this->validator->errors);
	}

	public function testValidateRowMissingFirstname()
	{
		$line = $this->getValidLine();
		$line['Prénom'] = '';

		$result = $this->validator->validateRow($line, 2);

		$this->assertEmpty($result);
		$this->assertFalse($this->validator->isValid());
		$this->assertContains('Prénom ou nom vide à la ligne 2', $this->validator->errors);
	}

	public function testValidateRowMissingLastname()
	{
		$line = $this->getValidLine();
		$line['Nom'] = '';

		$result = $this->validator->validateRow($line, 2);

		$this->assertEmpty($result);
		$this->assertContains('Prénom ou nom vide à la ligne 2', $this->validator->errors);
	}

	public function testValidateRowMissingPaymentDate()
	{
		$line = $this->getValidLine();
		$line['Date de paiement'] = '';

		$result = $this->validator->validateRow($line, 3);

		$this->assertEmpty($result);
		$this->assertContains('Date de paiement vide à la ligne 3', $this->validator->errors);
	}

	public function testValidateRowInvalidPaymentDate()
	{
		$line = $this->getValidLine();
		$line['Date de paiement'] = 'invalid';

		$result = $this->validator->validateRow($line, 4);

		$this->assertEmpty($result);
		// Should have error about invalid date
		$hasDateError = false;
		foreach ($this->validator->errors as $error) {
			if (strpos($error, 'Date de paiement') !== false && strpos($error, 'invalide') !== false) {
				$hasDateError = true;
				break;
			}
		}
		$this->assertTrue($hasDateError);
	}

	public function testValidateRowMissingAmount()
	{
		$line = $this->getValidLine();
		$line['Montant payé'] = '';

		$result = $this->validator->validateRow($line, 5);

		$this->assertEmpty($result);
		$this->assertContains('Montant payé vide ou invalide à la ligne 5', $this->validator->errors);
	}

	public function testValidateRowZeroAmount()
	{
		$line = $this->getValidLine();
		$line['Montant payé'] = '0';

		$result = $this->validator->validateRow($line, 2);

		// Zero amount should be valid
		$this->assertNotEmpty($result);
		$this->assertEquals(0.0, $result['amount_nominal']);
	}

	public function testValidateRowNotationTooLongForTheRefColumn()
	{
		$line = $this->getValidLine();
		// llx_salary.ref is a varchar(30) and now holds the notation
		$line['Salaire'] = str_repeat('a', 31);

		$result = $this->validator->validateRow($line, 4);

		$this->assertEmpty($result);
		$this->assertContains('Notation de salaire trop longue (30 caractères maximum) à la ligne 4', $this->validator->errors);
	}

	public function testValidateRowNotationAtMaxLengthIsAccepted()
	{
		$line = $this->getValidLine();
		$line['Salaire'] = str_repeat('a', 30);

		$result = $this->validator->validateRow($line, 4);

		$this->assertNotEmpty($result);
		$this->assertEquals(str_repeat('a', 30), $result['salary_notation']);
	}

	public function testValidateRowMissingLabel()
	{
		$line = $this->getValidLine();
		$line['Libellé'] = '';

		$result = $this->validator->validateRow($line, 6);

		$this->assertEmpty($result);
		$this->assertContains('Libellé vide à la ligne 6', $this->validator->errors);
	}

	public function testValidateRowInvalidPaye()
	{
		$line = $this->getValidLine();
		$line['Payé'] = 'maybe';

		$result = $this->validator->validateRow($line, 7);

		$this->assertEmpty($result);
		$this->assertContains('Payé invalide (doit être oui/non) à la ligne 7', $this->validator->errors);
	}

	public function testValidateRowMissingBankAccount()
	{
		$line = $this->getValidLine();
		$line['Compte bancaire'] = '';

		$result = $this->validator->validateRow($line, 8);

		$this->assertEmpty($result);
		$this->assertContains('Compte bancaire vide à la ligne 8', $this->validator->errors);
	}

	// ========================================
	// Tests for validateAll()
	// ========================================

	public function testValidateAllValid()
	{
		$lines = array(
			$this->getValidLine(),
			$this->getValidLine()
		);
		$lines[1]['Prénom'] = 'Marie';
		$lines[1]['Nom'] = 'Martin';

		$result = $this->validator->validateAll($lines);

		$this->assertCount(2, $result);
		$this->assertTrue($this->validator->isValid());
	}

	public function testValidateAllPartiallyValid()
	{
		$lines = array(
			$this->getValidLine(),
			$this->getValidLine()
		);
		$lines[1]['Prénom'] = ''; // Invalid second row

		$result = $this->validator->validateAll($lines);

		$this->assertCount(1, $result); // Only first row is valid
		$this->assertFalse($this->validator->isValid());
	}

	public function testValidateAllEmpty()
	{
		$result = $this->validator->validateAll(array());

		$this->assertEmpty($result);
		$this->assertTrue($this->validator->isValid()); // No errors for empty input
	}

	// ========================================
	// Tests for validateGroups()
	// ========================================

	/**
	 * Build a payment line of a group (helper for group tests).
	 */
	private function getGroupLine($notation, $ref, $firstname, $lastname, $nominal, $chf, $total): array
	{
		$line = $this->getValidLine();
		$line['Salaire'] = $notation;
		$line['Réf paiement'] = $ref;
		$line['Prénom'] = $firstname;
		$line['Nom'] = $lastname;
		$line['Montant payé'] = $nominal;
		$line['Montant CHF'] = $chf;
		$line['Salaire total CHF'] = $total;
		return $line;
	}

	public function testValidateGroupsValidMultiPayment()
	{
		$lines = array(
			$this->getGroupLine('2026-05-2', '2026-05-2-EUR', 'Marie', 'Martin', '2100', '2000', '5000'),
			$this->getGroupLine('2026-05-2', '2026-05-2-CHF', 'Marie', 'Martin', '3000', '3000', '5000'),
		);
		$validated = $this->validator->validateAll($lines);

		$this->assertTrue($this->validator->validateGroups($validated));
		$this->assertTrue($this->validator->isValid());
	}

	public function testValidateGroupsSumMismatch()
	{
		$lines = array(
			$this->getGroupLine('2026-05-2', '2026-05-2-EUR', 'Marie', 'Martin', '2100', '2000', '5000'),
			$this->getGroupLine('2026-05-2', '2026-05-2-CHF', 'Marie', 'Martin', '2500', '2500', '5000'),
		);
		$validated = $this->validator->validateAll($lines);

		$this->assertFalse($this->validator->validateGroups($validated));
		$hasError = false;
		foreach ($this->validator->errors as $error) {
			if (strpos($error, 'somme des paiements CHF') !== false) {
				$hasError = true;
			}
		}
		$this->assertTrue($hasError);
	}

	public function testValidateGroupsMultipleEmployees()
	{
		$lines = array(
			$this->getGroupLine('2026-05-2', '2026-05-2-EUR', 'Marie', 'Martin', '2000', '2000', '5000'),
			$this->getGroupLine('2026-05-2', '2026-05-2-CHF', 'Jean', 'Dupont', '3000', '3000', '5000'),
		);
		$validated = $this->validator->validateAll($lines);

		$this->assertFalse($this->validator->validateGroups($validated));
		$hasError = false;
		foreach ($this->validator->errors as $error) {
			if (strpos($error, 'plusieurs employés') !== false) {
				$hasError = true;
			}
		}
		$this->assertTrue($hasError);
	}

	public function testValidateGroupsTotalMismatch()
	{
		$lines = array(
			$this->getGroupLine('2026-05-2', '2026-05-2-EUR', 'Marie', 'Martin', '2000', '2000', '5000'),
			$this->getGroupLine('2026-05-2', '2026-05-2-CHF', 'Marie', 'Martin', '3000', '3000', '4000'),
		);
		$validated = $this->validator->validateAll($lines);

		$this->assertFalse($this->validator->validateGroups($validated));
		$hasError = false;
		foreach ($this->validator->errors as $error) {
			if (strpos($error, 'total du salaire') !== false) {
				$hasError = true;
			}
		}
		$this->assertTrue($hasError);
	}

	public function testValidateGroupsDateMismatch()
	{
		$lineA = $this->getGroupLine('2026-05-2', '2026-05-2-EUR', 'Marie', 'Martin', '2000', '2000', '5000');
		$lineB = $this->getGroupLine('2026-05-2', '2026-05-2-CHF', 'Marie', 'Martin', '3000', '3000', '5000');
		$lineB['Date de paiement'] = 45323; // different pay date than lineA

		$validated = $this->validator->validateAll(array($lineA, $lineB));

		$this->assertFalse($this->validator->validateGroups($validated));
		$hasError = false;
		foreach ($this->validator->errors as $error) {
			if (strpos($error, 'dates différentes') !== false) {
				$hasError = true;
			}
		}
		$this->assertTrue($hasError);
	}

	public function testValidateGroupsMonoPaymentIsAGroupOfOne()
	{
		$lines = array($this->getGroupLine('2026-05-1', '2026-05-1-CHF', 'Jean', 'Dupont', '4500', '4500', '4500'));
		$validated = $this->validator->validateAll($lines);

		$this->assertTrue($this->validator->validateGroups($validated));
	}

	// ========================================
	// Tests for getRequiredFields()
	// ========================================

	public function testGetRequiredFields()
	{
		$fields = $this->validator->getRequiredFields();

		$this->assertIsArray($fields);
		$this->assertContains('Salaire', $fields);
		$this->assertContains('Réf paiement', $fields);
		$this->assertContains('Prénom', $fields);
		$this->assertContains('Nom', $fields);
		$this->assertContains('Date de paiement', $fields);
		$this->assertContains('Montant payé', $fields);
		$this->assertContains('Salaire total CHF', $fields);
		$this->assertContains('Libellé', $fields);
		$this->assertContains('Date de début', $fields);
		$this->assertContains('Date de fin', $fields);
		$this->assertContains('Type de paiement', $fields);
		$this->assertContains('Payé', $fields);
		$this->assertContains('Compte bancaire', $fields);
	}

	// ========================================
	// Tests for isValid()
	// ========================================

	public function testIsValidInitially()
	{
		$this->assertTrue($this->validator->isValid());
	}

	public function testIsValidAfterError()
	{
		$line = $this->getValidLine();
		$line['Prénom'] = '';
		$this->validator->validateRow($line, 1);

		$this->assertFalse($this->validator->isValid());
	}

	// ========================================
	// Helper methods
	// ========================================

	private function getValidLine(): array
	{
		return array(
			'Salaire' => '2024-01-1',
			'Réf paiement' => '2024-01-1-CHF',
			'Prénom' => 'Jean',
			'Nom' => 'Dupont',
			'Date de paiement' => 45322, // 2024-01-31
			'Montant payé' => '1500,50',
			'Montant CHF' => '1500,50',
			'Salaire total CHF' => '1500,50',
			'Libellé' => 'Salaire janvier',
			'Date de début' => 45292, // 2024-01-01
			'Date de fin' => 45322, // 2024-01-31
			'Type de paiement' => 'VIR',
			'Payé' => 'oui',
			'Compte bancaire' => 'BNP'
		);
	}
}
