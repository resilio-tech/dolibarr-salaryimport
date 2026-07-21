<?php
/**
 * Standalone unit tests for SalaryImportService
 * Verifies the source of the preview flow, which cannot be instantiated without Dolibarr
 *
 * The class is named SalaryImportServiceUnitTest so it does not collide with the Dolibarr-backed
 * SalaryImportServiceTest in test/phpunit/.
 *
 * Run with: phpunit htdocs/custom/salaryimport/test/phpunit/unit/SalaryImportServiceUnitTest.php
 */

use PHPUnit\Framework\TestCase;

/**
 * Test class that checks the salaries-already-imported handling in processForPreview()
 */
class SalaryImportServiceUnitTest extends TestCase
{
	/**
	 * Read the body of processForPreview() from the source file
	 *
	 * @return string Method body
	 */
	private function getProcessForPreviewBody()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportService.class.php';
		$this->assertFileExists($sourceFile, 'Service class file should exist');

		$source = file_get_contents($sourceFile);

		$pattern = '/function processForPreview\([^)]*\)\s*\{([\s\S]+?)\n\t\}/';
		$this->assertMatchesRegularExpression($pattern, $source, 'Should find processForPreview method');

		preg_match($pattern, $source, $matches);

		return $matches[1];
	}

	/**
	 * Verify the file is refused by default when it contains salaries already imported
	 */
	public function testAlreadyImportedSalariesAreRefusedByDefault()
	{
		$body = $this->getProcessForPreviewBody();

		$this->assertStringContainsString('findExistingSalaryRefs($notations)', $body, 'The preview should look for already imported salaries');
		$this->assertStringContainsString('if (empty($skipExisting)) {', $body, 'Refusing should be the default');
		$this->assertStringContainsString('ErrorSalaryAlreadyImported', $body, 'Each already imported salary should be reported');
	}

	/**
	 * Verify a notation held by a salary this import did not create is never skipped.
	 *
	 * Skipping it would drop a payroll entry that was never imported, and the run would still be
	 * reported as a success.
	 */
	public function testConflictsAreRefusedEvenWhenSkippingIsRequested()
	{
		$body = $this->getProcessForPreviewBody();

		$this->assertStringContainsString('SalaryImportPersister::STATUS_CONFLICT', $body, 'Hits should be classified');
		$this->assertStringContainsString('ErrorSalaryRefConflict', $body, 'A conflict should be reported as an error');

		$conflictReturn = strpos($body, 'ErrorSalaryRefConflict');
		$skipBranch = strpos($body, 'if (empty($skipExisting)) {');
		$this->assertNotFalse($conflictReturn, 'Should find the conflict branch');
		$this->assertNotFalse($skipBranch, 'Should find the skip branch');
		$this->assertLessThan($skipBranch, $conflictReturn, 'Conflicts should be refused before the skip option is honoured');

		$this->assertStringContainsString('in_array($notation, $skippable, true)', $body, 'Only skippable notations should be dropped');
	}

	/**
	 * Verify the skip branch warns and stops when nothing is left to import
	 */
	public function testSkipExistingWarnsAndStopsWhenNothingRemains()
	{
		$body = $this->getProcessForPreviewBody();

		$this->assertStringContainsString('WarningSalaryAlreadyImportedSkipped', $body, 'Skipped salaries should raise a warning');
		$this->assertStringContainsString('ErrorAllSalariesAlreadyImported', $body, 'An entirely skipped file should be an error, not an empty preview');
	}

	/**
	 * Verify the skip filter preserves array keys.
	 *
	 * The preview table pairs each enriched row with the raw parsed line by key
	 * (salaryimportfile.php), and SalaryImportUserLookup::enrichAll() derives its "+2" row numbers
	 * from the same keys. Re-indexing here would silently display the wrong source lines.
	 */
	public function testSkipExistingPreservesArrayKeys()
	{
		$body = $this->getProcessForPreviewBody();

		$this->assertStringContainsString('foreach ($validatedRows as $index => $row) {', $body, 'The filter should iterate with keys');
		$this->assertStringContainsString('$kept[$index] = $row;', $body, 'The filter must preserve the original keys');
		$this->assertStringNotContainsString('$kept[] = $row;', $body, 'The filter must not re-index the rows');
	}

	/**
	 * Verify the skip flag is a parameter, so the caller decides and the default stays strict
	 */
	public function testSkipExistingIsAnOptInParameter()
	{
		$sourceFile = dirname(__FILE__).'/../../../class/SalaryImportService.class.php';
		$source = file_get_contents($sourceFile);

		$this->assertStringContainsString(
			'public function processForPreview($skipExisting = 0)',
			$source,
			'Skipping should be opt-in, with refusal as the default'
		);
	}
}
