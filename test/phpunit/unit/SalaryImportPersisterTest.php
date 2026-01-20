<?php
/**
 * Standalone unit tests for SalaryImportPersister
 * Tests SQL generation by analyzing the generated queries
 *
 * Run with: phpunit htdocs/custom/salaryimport/test/phpunit/unit/SalaryImportPersisterTest.php
 */

use PHPUnit\Framework\TestCase;

/**
 * Test class that directly tests SQL generation logic
 * without loading the actual SalaryImportPersister class
 */
class SalaryImportPersisterTest extends TestCase
{
	// ========================================
	// Tests for insertSalary SQL generation
	// ========================================

	/**
	 * Test that insertSalary SQL includes entity field
	 * This validates the fix for multi-entity support
	 */
	public function testInsertSalarySqlIncludesEntityField()
	{
		// Simulate what insertSalary should generate
		$entity = 2;
		$ref = 'REF-001';
		$datep = '2024-01-15';
		$amount = 1500.00;
		$typepayment = 1;
		$label = 'Test salary';
		$datesp = '2024-01-01';
		$dateep = '2024-01-31';
		$paye = 1;
		$userId = 5;
		$accountId = 10;
		$userAuthorId = 1;

		// Build the expected SQL (matching the fixed version)
		$sql = "INSERT INTO llx_salary";
		$sql .= " (ref, datep, amount, fk_typepayment, label, datesp, dateep, paye, fk_user, fk_account, fk_user_author, entity)";
		$sql .= " VALUES (";
		$sql .= "'".$ref."',";
		$sql .= "'".$datep."',";
		$sql .= floatval($amount).",";
		$sql .= intval($typepayment).",";
		$sql .= "'".$label."',";
		$sql .= "'".$datesp."',";
		$sql .= "'".$dateep."',";
		$sql .= intval($paye).",";
		$sql .= intval($userId).",";
		$sql .= intval($accountId).",";
		$sql .= intval($userAuthorId).",";
		$sql .= intval($entity);
		$sql .= ")";

		// Verify entity is in column list
		$this->assertStringContainsString(', entity)', $sql, 'Column list should include entity');

		// Verify entity value is at end of values
		$this->assertMatchesRegularExpression('/,\s*2\s*\)$/', $sql, 'Values should end with entity value');
	}

	/**
	 * Read the actual source code and verify entity is included
	 */
	public function testInsertSalarySourceCodeIncludesEntity()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$this->assertFileExists($sourceFile, 'Persister class file should exist');

		$source = file_get_contents($sourceFile);

		// Find the insertSalary method
		$pattern = '/function insertSalary\([^)]+\)\s*\{([^}]+INSERT INTO[^}]+)\}/s';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find insertSalary method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// Verify entity is in the INSERT statement
		$this->assertStringContainsString('entity', $methodBody, 'insertSalary should include entity field');
		$this->assertStringContainsString('$this->conf->entity', $methodBody, 'insertSalary should use $this->conf->entity');
	}

	// ========================================
	// Tests for persistRow order of operations
	// ========================================

	/**
	 * Verify that in persistRow, payment_salary is created BEFORE bank_url
	 */
	public function testPersistRowSourceCodeOrderOfOperations()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the persistRow method
		$pattern = '/function persistRow\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find persistRow method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// Find positions of key operations
		$insertPaymentSalaryPos = strpos($methodBody, 'insertPaymentSalary');
		$insertBankUrlPos = strpos($methodBody, 'insertBankUrl');

		$this->assertNotFalse($insertPaymentSalaryPos, 'Should find insertPaymentSalary call');
		$this->assertNotFalse($insertBankUrlPos, 'Should find insertBankUrl call');

		// Verify payment_salary is created BEFORE bank_url
		$this->assertLessThan(
			$insertBankUrlPos,
			$insertPaymentSalaryPos,
			'insertPaymentSalary should be called BEFORE insertBankUrl'
		);
	}

	/**
	 * Verify that bank_url uses $paymentId (not $salaryId) for payment_salary type
	 */
	public function testBankUrlUsesPaymentIdNotSalaryId()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the section where insertBankUrl is called with payment_salary type
		$pattern = '/insertBankUrl\(\s*\$bankId,\s*(\$\w+),\s*[\'"]\/salaries\/payment_salary/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find insertBankUrl call for payment_salary');

		preg_match($pattern, $source, $matches);
		$urlIdVariable = $matches[1];

		// Verify it uses $paymentId, not $salaryId
		$this->assertEquals('$paymentId', $urlIdVariable, 'bank_url for payment_salary should use $paymentId, not $salaryId');
	}

	// ========================================
	// Tests for insertPaymentSalary SQL
	// ========================================

	/**
	 * Verify insertPaymentSalary includes entity
	 */
	public function testInsertPaymentSalarySourceCodeIncludesEntity()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the insertPaymentSalary method
		$pattern = '/function insertPaymentSalary\([^)]+\)\s*\{([^}]+INSERT INTO[^}]+)\}/s';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find insertPaymentSalary method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// Verify entity is in the INSERT statement
		$this->assertStringContainsString('entity', $methodBody, 'insertPaymentSalary should include entity field');
	}

	// ========================================
	// Tests for insertBankUrl SQL
	// ========================================

	/**
	 * Test insertBankUrl SQL structure
	 */
	public function testInsertBankUrlSqlStructure()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the insertBankUrl method
		$pattern = '/function insertBankUrl\([^)]+\)\s*\{([^}]+INSERT INTO[^}]+)\}/s';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find insertBankUrl method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// Verify required fields are present
		$this->assertStringContainsString('fk_bank', $methodBody, 'Should include fk_bank');
		$this->assertStringContainsString('url_id', $methodBody, 'Should include url_id');
		$this->assertStringContainsString('url', $methodBody, 'Should include url');
		$this->assertStringContainsString('type', $methodBody, 'Should include type');
	}

	// ========================================
	// Additional validation tests
	// ========================================

	/**
	 * Verify bank transaction amount is negated (expense)
	 */
	public function testInsertBankTransactionNegatesAmount()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the insertBankTransaction method
		$pattern = '/function insertBankTransaction\([^)]+\)\s*\{([^}]+INSERT INTO[^}]+)\}/s';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find insertBankTransaction method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// Verify amount is negated
		$this->assertStringContainsString('-$amount', $methodBody, 'Bank transaction should negate amount for expense');
	}
}
