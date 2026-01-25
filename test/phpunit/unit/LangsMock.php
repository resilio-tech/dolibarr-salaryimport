<?php
/**
 * Mock class for Dolibarr's $langs object
 * Used in unit tests that run without Dolibarr environment
 */

/**
 * Class LangsMock
 *
 * Provides a simple implementation of the trans() method
 * that returns formatted error messages for testing
 */
class LangsMock
{
	/**
	 * @var array Translation map (key => message with %s placeholders)
	 */
	protected $translations = array(
		// File errors
		'ErrorFileNotFound' => 'File not found: %s',
		'ErrorFileNotReadable' => 'File is not readable: %s',
		'ErrorFileMustBeXlsx' => 'File must be in XLSX format, got: %s',
		'ErrorCannotReadXlsx' => 'Cannot read XLSX file: %s',
		'ErrorNoDataRows' => 'No data rows found in file',
		'ErrorParsingXlsx' => 'Error parsing XLSX file: %s',

		// Upload errors
		'ErrorNoXlsxFile' => 'No XLSX file provided',
		'ErrorUploadXlsx' => 'Error uploading salary file',
		'ErrorXlsxFormat' => 'Salary file must be in xlsx format',
		'ErrorUploadZip' => 'Error uploading PDF zip file',
		'ErrorZipFormat' => 'PDF file must be in zip format',

		// Validation errors (French for validator tests)
		'ErrorEmptyFirstnameOrLastname' => 'Prénom ou nom vide à la ligne %s',
		'ErrorEmptyPaymentDate' => 'Date de paiement vide à la ligne %s',
		'ErrorInvalidPaymentDate' => 'Date de paiement (%s) invalide à la ligne %s',
		'ErrorEmptyOrInvalidAmount' => 'Montant vide ou invalide à la ligne %s',
		'ErrorEmptyLabel' => 'Libellé vide à la ligne %s',
		'ErrorEmptyStartDate' => 'Date de début vide à la ligne %s',
		'ErrorInvalidStartDate' => 'Date de début (%s) invalide à la ligne %s',
		'ErrorEmptyEndDate' => 'Date de fin vide à la ligne %s',
		'ErrorInvalidEndDate' => 'Date de fin (%s) invalide à la ligne %s',
		'ErrorEmptyPaymentType' => 'Type de paiement vide à la ligne %s',
		'ErrorEmptyPaid' => 'Champ Payé vide à la ligne %s',
		'ErrorInvalidPaid' => 'Payé invalide (doit être oui/non) à la ligne %s',
		'ErrorEmptyBankAccount' => 'Compte bancaire vide à la ligne %s',

		// Lookup errors
		'ErrorUserNotFound' => 'User not found at row %s',
		'ErrorPaymentTypeNotFound' => "Payment type '%s' not found at row %s",
		'ErrorBankAccountNotFound' => "Bank account '%s' not found at row %s",
		'ErrorDatabaseQuery' => 'Database error: %s',

		// ZIP errors
		'ErrorZipNotFound' => 'ZIP file not found: %s',
		'ErrorZipOpen' => 'Failed to open ZIP archive: %s',
		'ErrorZipExtract' => 'Failed to extract ZIP archive to: %s',

		// Persist errors
		'ErrorGetLastSalaryRef' => 'Error getting last salary ref: %s',
		'ErrorGetLastPaymentRef' => 'Error getting last payment ref: %s',
		'ErrorInsertSalary' => 'Error inserting salary: %s',
		'ErrorInsertBankTransaction' => 'Error inserting bank transaction: %s',
		'ErrorInsertBankUrl' => 'Error inserting bank URL: %s',
		'ErrorInsertPaymentSalary' => 'Error inserting payment salary: %s',
		'ErrorCreateDirectory' => 'Failed to create directory: %s',
		'ErrorMovePdf' => 'Failed to move PDF file to: %s',
		'ErrorIndexPdf' => 'Failed to index PDF file in database',
		'ErrorPersistRow' => 'Error persisting row %s: %s',

		// Cleanup errors
		'ErrorDeleteXlsx' => 'Failed to delete XLSX file',
		'ErrorDeleteZip' => 'Failed to delete ZIP file: %s',
		'ErrorDeleteFile' => 'Failed to delete file: %s',
		'ErrorDeleteFolder' => 'Failed to delete folder: %s',

		// Import messages
		'NoDataToImport' => 'No data to import',
		'ImportSuccess' => 'Import completed successfully: %s salary(ies) imported',
		'ImportError' => 'Error during import',
		'NoPdfAttached' => 'None'
	);

	/**
	 * Translate a string
	 *
	 * @param string $key Translation key
	 * @param mixed  ...$params Parameters to substitute for %s
	 * @return string Translated string with parameters substituted
	 */
	public function trans($key, ...$params)
	{
		if (isset($this->translations[$key])) {
			$message = $this->translations[$key];
			if (!empty($params)) {
				// Use vsprintf to replace %s placeholders
				$message = vsprintf($message, $params);
			}
			return $message;
		}
		// Return the key itself if no translation found
		return $key;
	}

	/**
	 * Load translations (no-op for mock)
	 *
	 * @param string $domain Translation domain
	 * @return void
	 */
	public function load($domain)
	{
		// No-op for mock
	}
}

/**
 * Initialize global $langs mock
 * Call this at the start of each test that needs translations
 */
function initLangsMock()
{
	global $langs;
	$langs = new LangsMock();
}
