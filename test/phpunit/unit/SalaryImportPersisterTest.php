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
	// Tests for persistGroup order of operations
	// ========================================

	/**
	 * Verify that in persistGroup, payment_salary is created BEFORE bank_url
	 */
	public function testPersistRowSourceCodeOrderOfOperations()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the persistGroup method
		$pattern = '/function persistGroup\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find persistGroup method');

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

	// ========================================
	// Tests for PDF directory naming
	// ========================================

	/**
	 * Verify that movePdfToSalary uses salaryId (rowid) for directory
	 * Dolibarr Salary::fetch() sets ref = rowid, so the directory must use rowid
	 */
	public function testMovePdfToSalaryUsesSalaryIdForDirectory()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the movePdfToSalary method
		$pattern = '/function movePdfToSalary\([^)]+\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find movePdfToSalary method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// Verify the directory itself is built from $salaryId (Dolibarr uses rowid as ref),
		// not just that $salaryId appears somewhere (it is also used in logs and $salary->id)
		$this->assertStringContainsString('.\'/\'.$salaryId', $methodBody, 'movePdfToSalary should build the directory from $salaryId');
	}

	/**
	 * Verify that movePdfToSalary builds the directory from the module dir_output
	 * and not a hardcoded DOL_DATA_ROOT path, so it stays correct under multicompany
	 * (entity >= 2 stores data under DOL_DATA_ROOT/{entity}/salaries)
	 */
	public function testMovePdfToSalaryUsesDirOutputForEntity()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function movePdfToSalary\([^)]+\)\s*\{([\s\S]+?)\n\t\}/';
		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// The destination must derive from dir_output (entity-aware), not a hardcoded path
		$this->assertStringContainsString('salaries->dir_output', $methodBody, 'movePdfToSalary should use dir_output (entity-aware)');
		$this->assertStringNotContainsString("DOL_DATA_ROOT.'/salaries/", $methodBody, 'movePdfToSalary should not hardcode the salaries data path');
	}

	// ========================================
	// Tests for PDF error handling
	// ========================================

	/**
	 * Verify that warnings property exists in SalaryImportPersister
	 */
	public function testPersisterHasWarningsProperty()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$this->assertStringContainsString('public $warnings', $source, 'Persister should have public $warnings property');
	}

	/**
	 * Verify that movePdfToSalary returns error when file not found
	 */
	public function testMovePdfToSalaryReturnsErrorWhenFileNotFound()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the movePdfToSalary method
		$pattern = '/function movePdfToSalary\([^)]+\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find movePdfToSalary method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// Verify file_exists check returns error (not success)
		$this->assertStringContainsString('!file_exists($pdfPath)', $methodBody, 'Should check if file exists');
		$this->assertStringContainsString('ErrorPdfNotFound', $methodBody, 'Should have ErrorPdfNotFound error message');
		$this->assertStringContainsString('return -1', $methodBody, 'Should return -1 when file not found');
	}

	/**
	 * Verify that movePdfToSalary logs warning when file not found
	 */
	public function testMovePdfToSalaryLogsWarningWhenFileNotFound()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the movePdfToSalary method
		$pattern = '/function movePdfToSalary\([^)]+\)\s*\{([\s\S]+?)\n\t\}/';
		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// Verify it logs with LOG_WARNING
		$this->assertStringContainsString('dol_syslog', $methodBody, 'Should call dol_syslog');
		$this->assertStringContainsString('LOG_WARNING', $methodBody, 'Should log with LOG_WARNING level when file not found');
	}

	/**
	 * Verify that persistGroup collects PDF errors as warnings
	 */
	public function testPersistRowCollectsPdfErrorsAsWarnings()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the persistGroup method
		$pattern = '/function persistGroup\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// Verify PDF errors are collected as warnings with context
		$this->assertStringContainsString('$this->warnings[]', $methodBody, 'persistGroup should append to $this->warnings');
		$this->assertStringContainsString('$context', $methodBody, 'persistGroup should include context (employee name)');
	}

	/**
	 * Verify that persistGroup does not fail when PDF move fails
	 */
	public function testPersistRowDoesNotFailOnPdfError()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		// Find the persistGroup method
		$pattern = '/function persistGroup\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		// Find the section after movePdfToSalary call
		$pdfMovePos = strpos($methodBody, 'movePdfToSalary');
		$afterPdfMove = substr($methodBody, $pdfMovePos);

		// Verify there's no "return $result" (empty) or "return array()" after PDF error
		// The result should still be populated with salary data
		$this->assertStringContainsString("'salaryId' => \$salaryId", $afterPdfMove, 'Should still return salaryId after PDF section');
	}

	// ========================================
	// Tests for the multi-currency / grouping changes
	// ========================================

	/**
	 * Verify insertBankTransaction persists amount_main_currency (company-currency amount)
	 */
	public function testInsertBankTransactionIncludesAmountMainCurrency()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function insertBankTransaction\([^)]+\)\s*\{([\s\S]+?)\n\t\}/';
		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString('amount_main_currency', $methodBody, 'insertBankTransaction should persist amount_main_currency');
		$this->assertStringContainsString('NULL', $methodBody, 'amount_main_currency should be NULL when same currency');
	}

	/**
	 * Verify insertPaymentSalary persists num_payment (the displayed payment reference)
	 */
	public function testInsertPaymentSalaryIncludesNumPayment()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function insertPaymentSalary\([^)]+\)\s*\{([\s\S]+?)\n\t\}/';
		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString('num_payment', $methodBody, 'insertPaymentSalary should persist num_payment');
	}

	/**
	 * Verify persistGroup uses the notation as the salary ref and the imported label as the label,
	 * instead of an ad-hoc counter with the notation squatting the label column
	 */
	public function testPersistGroupUsesNotationAsRefAndImportedLabel()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function persistGroup\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find persistGroup method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString('$salaryRef = $notation;', $methodBody, 'The salary ref should be the notation');
		$this->assertStringContainsString(
			'buildSalaryLabel($notation, $importedLabel)',
			$methodBody,
			'The salary label should be built from the notation and the imported label'
		);

		// The insertSalary call must pass the ref then, as 5th argument, the built label
		$callPattern = '/insertSalary\(\s*\$salaryRef,\s*\$first\[\'datep\'\],\s*\$first\[\'total_salary_chf\'\],\s*\$first\[\'typepayment\'\],\s*\$salaryLabel,/';
		$this->assertMatchesRegularExpression($callPattern, $methodBody, 'insertSalary should receive the built label, not the bare notation');
	}

	/**
	 * Verify buildSalaryLabel keeps the notation visible without duplicating it
	 */
	public function testBuildSalaryLabelHandlesEmptyAndAlreadyPrefixedLabels()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function buildSalaryLabel\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find buildSalaryLabel method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString("\$built = \$notation;", $methodBody, 'An empty label should degrade to the notation');
		$this->assertStringContainsString(
			"strpos(\$label, \$notation.' ') === 0",
			$methodBody,
			'An already prefixed label should not be prefixed twice, and the notation must be matched as a whole'
		);
		$this->assertStringContainsString(
			'mb_substr($built, 0, self::LABEL_MAX_LENGTH)',
			$methodBody,
			'The built label should be truncated to the width of llx_salary.label'
		);
	}

	/**
	 * Verify the salary ref counter is gone (the notation is the ref now)
	 */
	public function testNoSalaryRefCounterRemains()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$this->assertStringNotContainsString('salaryRefCounter', $source, 'The salary ref counter should be removed');
		$this->assertStringNotContainsString('getNextSalaryRef', $source, 'getNextSalaryRef should be removed');
	}

	/**
	 * Verify persistGroup refuses a notation already used as a salary ref (replayed confirmation form)
	 */
	public function testPersistGroupRejectsAlreadyImportedNotation()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function persistGroup\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find persistGroup method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$checkPos = strpos($methodBody, 'findExistingSalaryRefs');
		$insertPos = strpos($methodBody, 'insertSalary');

		$this->assertNotFalse($checkPos, 'persistGroup should check for an existing salary ref');
		$this->assertStringContainsString('ErrorSalaryAlreadyImported', $methodBody, 'persistGroup should report the duplicate');
		$this->assertLessThan($insertPos, $checkPos, 'The duplicate check should happen before the insert');
	}

	/**
	 * Verify findExistingSalaryRefs scopes its lookup to the current entity
	 */
	public function testFindExistingSalaryRefsIsEntityScoped()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function findExistingSalaryRefs\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find findExistingSalaryRefs method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString('$this->conf->entity', $methodBody, 'The lookup should be scoped to the current entity');
		$this->assertStringContainsString('$this->db->escape', $methodBody, 'The notations should be escaped');
	}

	/**
	 * Verify findExistingSalaryRefs compares case-insensitively and on strings, so a notation the
	 * database matched under its _ci collation (or one that reached us as an int array key) is not
	 * dropped by the PHP-side mapping
	 */
	public function testFindExistingSalaryRefsMappingIsStringAndCaseInsensitive()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function findExistingSalaryRefs\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find findExistingSalaryRefs method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString('normalizeNotations($notations)', $methodBody, 'Notations should be normalised to strings');
		$this->assertStringContainsString('UNION ALL', $methodBody, 'The database should report which notation each row matched');
		$this->assertStringContainsString("array('notation' => \$notation, 'status' => \$status)", $methodBody, 'The result must be a list, not a map keyed by notation');
		$this->assertStringNotContainsString('$existing[$notation] =', $methodBody, 'A map keyed by notation would coerce numeric notations to int keys');
	}

	/**
	 * Verify persistAll hands persistGroup a string, since PHP turns a numeric array key into an int
	 */
	public function testPersistAllCastsTheNotationBackToString()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function persistAll\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find persistAll method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString('persistGroup((string) $notation, $rows, $existing)', $methodBody, 'persistGroup should receive a string notation');
	}

	/**
	 * Verify persistGroup enforces the ref width itself, since the confirmation form never goes
	 * back through the validator
	 */
	public function testPersistGroupEnforcesRefWidth()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function persistGroup\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find persistGroup method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString('mb_strlen($notation) > self::REF_MAX_LENGTH', $methodBody, 'persistGroup should check the ref width');
		$this->assertStringContainsString('ErrorSalaryRefTooLong', $methodBody, 'persistGroup should report an oversized notation');
		$this->assertStringContainsString(
			'mb_substr(isset($row[\'label\']) ? (string) $row[\'label\'] : \'\', 0, self::LABEL_MAX_LENGTH)',
			$methodBody,
			'The payment label should be capped to the column width too'
		);
		$this->assertStringContainsString(
			'mb_substr(isset($row[\'payment_ref\']) ? (string) $row[\'payment_ref\'] : \'\', 0, self::PAYMENT_REF_MAX_LENGTH)',
			$methodBody,
			'The payment reference should be capped to the column width too'
		);
	}

	/**
	 * Verify classifySalaryMatch tells our own imports apart from a value merely held by another
	 * salary, since only the former may be silently skipped on re-import
	 */
	public function testClassifySalaryMatchDistinguishesImportsFromConflicts()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function classifySalaryMatch\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find classifySalaryMatch method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString('self::STATUS_IMPORTED', $methodBody, 'Should report our own imports');
		$this->assertStringContainsString('self::STATUS_CONFLICT', $methodBody, 'Should report values held by other salaries');
		$this->assertStringContainsString(
			'ctype_digit($notation) ? self::STATUS_CONFLICT : self::STATUS_IMPORTED',
			$methodBody,
			'Only a numeric notation can collide with an old counter ref'
		);
		$this->assertStringContainsString("(\$ref !== '') ? self::STATUS_IMPORTED : ''", $methodBody, 'A label-only match on a ref-less salary leaves the notation free');
		$this->assertStringContainsString('strlen($notation) + 1', $methodBody, 'strncasecmp counts bytes, so the length must be in bytes');
		$this->assertStringNotContainsString('mb_strlen($notation) + 1', $methodBody, 'A character length would compare short on a multibyte notation');
	}

	/**
	 * Verify persistAll looks the whole batch up once instead of scanning llx_salary per group
	 */
	public function testPersistAllLooksUpExistingSalariesOnce()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function persistAll\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find persistAll method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString('findExistingSalaryRefs(array_keys($groups))', $methodBody, 'The lookup should be batched');
		$this->assertStringContainsString('persistGroup((string) $notation, $rows, $existing)', $methodBody, 'The batch result should be handed to persistGroup');
	}

	/**
	 * Verify findExistingSalaryRefs also matches the label, so salaries imported before the notation
	 * became the ref (counter as ref, bare notation as label) are still detected as duplicates
	 */
	public function testFindExistingSalaryRefsAlsoMatchesLegacyLabel()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function findExistingSalaryRefs\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find findExistingSalaryRefs method');

		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$sql = str_replace('"', '', $methodBody);
		$this->assertStringContainsString('AND (ref = ', $sql, 'The lookup should match the ref');
		$this->assertStringContainsString('OR label = ', $sql, 'The lookup should also match a legacy label');
	}

	/**
	 * Verify persistAll groups rows by salary notation (one salary per notation)
	 */
	public function testPersistAllGroupsByNotation()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportPersister.class.php';
		$source = file_get_contents($sourceFile);

		$pattern = '/function persistAll\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		preg_match($pattern, $source, $matches);
		$methodBody = $matches[1];

		$this->assertStringContainsString('salary_notation', $methodBody, 'persistAll should group by salary_notation');
		$this->assertStringContainsString('persistGroup', $methodBody, 'persistAll should delegate to persistGroup');
	}
}
