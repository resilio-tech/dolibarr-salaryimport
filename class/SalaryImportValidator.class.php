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
 * \file       class/SalaryImportValidator.class.php
 * \ingroup    salaryimport
 * \brief      Class for validating salary import data
 */

/**
 * Class SalaryImportValidator
 *
 * Validates parsed salary data before import
 */
class SalaryImportValidator
{
	/**
	 * @var array Error messages
	 */
	public $errors = array();

	/**
	 * @var array Required fields
	 */
	protected $requiredFields = array(
		'Prénom',
		'Nom',
		'Date de paiement',
		'Montant',
		'Libellé',
		'Date de début',
		'Date de fin',
		'Type de paiement',
		'Payé',
		'Compte bancaire'
	);

	/**
	 * Constructor
	 */
	public function __construct()
	{
	}

	/**
	 * Convert Excel serial date to MySQL date format (Y-m-d)
	 *
	 * Excel stores dates as number of days since 1900-01-01 (with a bug for 1900 leap year)
	 *
	 * @param mixed $excelDate Excel serial date number
	 * @return string|false MySQL date string or false on failure
	 */
	public function parseExcelDate($excelDate)
	{
		if (empty($excelDate)) {
			return false;
		}

		// If it's already a string date, try to parse it
		if (is_string($excelDate) && !is_numeric($excelDate)) {
			$timestamp = strtotime($excelDate);
			if ($timestamp !== false) {
				return date('Y-m-d', $timestamp);
			}
			return false;
		}

		// Convert Excel serial date
		// Excel dates start at 1 = 1900-01-01, but there's a bug where Excel thinks 1900 was a leap year
		// So we use the Unix timestamp calculation: (excelDate - 25569) * 86400
		// 25569 is the number of days between 1900-01-01 and 1970-01-01
		$unixTimestamp = ($excelDate - 25569) * 86400;

		if ($unixTimestamp < 0) {
			return false;
		}

		return date('Y-m-d', $unixTimestamp);
	}

	/**
	 * Format date for display (d/m/Y format)
	 *
	 * @param mixed $excelDate Excel serial date number
	 * @return string|false Formatted date string or false on failure
	 */
	public function formatDateForDisplay($excelDate)
	{
		if (empty($excelDate)) {
			return false;
		}

		// If it's already a string date, try to parse it
		if (is_string($excelDate) && !is_numeric($excelDate)) {
			$timestamp = strtotime($excelDate);
			if ($timestamp !== false) {
				return date('d/m/Y', $timestamp);
			}
			return false;
		}

		$unixTimestamp = ($excelDate - 25569) * 86400;

		if ($unixTimestamp < 0) {
			return false;
		}

		return date('d/m/Y', $unixTimestamp);
	}

	/**
	 * Parse amount value (handle comma as decimal separator)
	 *
	 * @param mixed $amount Amount value from Excel
	 * @return float|false Parsed float or false on failure
	 */
	public function parseAmount($amount)
	{
		if ($amount === null || $amount === '') {
			return false;
		}

		// Allow zero
		if ($amount === 0 || $amount === '0') {
			return 0.0;
		}

		// Convert comma to dot for decimal separator
		$amount = str_replace(',', '.', (string) $amount);

		// Remove spaces
		$amount = str_replace(' ', '', $amount);

		if (!is_numeric($amount)) {
			return false;
		}

		return floatval($amount);
	}

	/**
	 * Parse 'Payé' field (oui/non to 1/0)
	 *
	 * @param string $value Value from Excel (oui, non, yes, no, 1, 0)
	 * @return int|false 1 for paid, 0 for not paid, false on invalid value
	 */
	public function parsePaye($value)
	{
		if ($value === null || $value === '') {
			return false;
		}

		$normalized = strtolower(trim((string) $value));

		if ($normalized === 'oui' || $normalized === 'yes' || $normalized === '1') {
			return 1;
		}

		if ($normalized === 'non' || $normalized === 'no' || $normalized === '0') {
			return 0;
		}

		return false;
	}

	/**
	 * Validate a single row of data
	 *
	 * @param array $line   Data row (associative array with headers as keys)
	 * @param int   $rowNum Row number (for error messages, 1-based)
	 * @return array Validated data array or empty array on error (errors stored in $this->errors)
	 */
	public function validateRow($line, $rowNum)
	{
		global $langs;
		$validated = array();
		$rowErrors = array();

		// Validate firstname and lastname
		$firstname = isset($line['Prénom']) ? trim($line['Prénom']) : '';
		$lastname = isset($line['Nom']) ? trim($line['Nom']) : '';

		if (empty($firstname) || empty($lastname)) {
			$rowErrors[] = $langs->trans('ErrorEmptyFirstnameOrLastname', $rowNum);
		} else {
			$validated['firstname'] = $firstname;
			$validated['lastname'] = $lastname;
		}

		// Validate payment date
		$datep = isset($line['Date de paiement']) ? $line['Date de paiement'] : null;
		if (empty($datep)) {
			$rowErrors[] = $langs->trans('ErrorEmptyPaymentDate', $rowNum);
		} else {
			$parsedDate = $this->parseExcelDate($datep);
			if ($parsedDate === false) {
				$rowErrors[] = $langs->trans('ErrorInvalidPaymentDate', $datep, $rowNum);
			} else {
				$validated['datep'] = $parsedDate;
				$validated['datep_display'] = $this->formatDateForDisplay($datep);
			}
		}

		// Validate amount
		$amount = isset($line['Montant']) ? $line['Montant'] : null;
		$parsedAmount = $this->parseAmount($amount);
		if ($parsedAmount === false) {
			$rowErrors[] = $langs->trans('ErrorEmptyOrInvalidAmount', $rowNum);
		} else {
			$validated['amount'] = $parsedAmount;
		}

		// Validate label
		$label = isset($line['Libellé']) ? trim($line['Libellé']) : '';
		if (empty($label)) {
			$rowErrors[] = $langs->trans('ErrorEmptyLabel', $rowNum);
		} else {
			$validated['label'] = $label;
		}

		// Validate start date
		$datesp = isset($line['Date de début']) ? $line['Date de début'] : null;
		if (empty($datesp)) {
			$rowErrors[] = $langs->trans('ErrorEmptyStartDate', $rowNum);
		} else {
			$parsedDate = $this->parseExcelDate($datesp);
			if ($parsedDate === false) {
				$rowErrors[] = $langs->trans('ErrorInvalidStartDate', $datesp, $rowNum);
			} else {
				$validated['datesp'] = $parsedDate;
				$validated['datesp_display'] = $this->formatDateForDisplay($datesp);
			}
		}

		// Validate end date
		$dateep = isset($line['Date de fin']) ? $line['Date de fin'] : null;
		if (empty($dateep)) {
			$rowErrors[] = $langs->trans('ErrorEmptyEndDate', $rowNum);
		} else {
			$parsedDate = $this->parseExcelDate($dateep);
			if ($parsedDate === false) {
				$rowErrors[] = $langs->trans('ErrorInvalidEndDate', $dateep, $rowNum);
			} else {
				$validated['dateep'] = $parsedDate;
				$validated['dateep_display'] = $this->formatDateForDisplay($dateep);
			}
		}

		// Store payment type code for lookup (will be validated by UserLookup)
		$typepayment = isset($line['Type de paiement']) ? trim($line['Type de paiement']) : '';
		if (empty($typepayment)) {
			$rowErrors[] = $langs->trans('ErrorEmptyPaymentType', $rowNum);
		} else {
			$validated['typepayment_code'] = $typepayment;
		}

		// Validate Payé field
		$paye = isset($line['Payé']) ? $line['Payé'] : null;
		if ($paye === null || $paye === '') {
			$rowErrors[] = $langs->trans('ErrorEmptyPaid', $rowNum);
		} else {
			$parsedPaye = $this->parsePaye($paye);
			if ($parsedPaye === false) {
				$rowErrors[] = $langs->trans('ErrorInvalidPaid', $rowNum);
			} else {
				$validated['paye'] = $parsedPaye;
			}
		}

		// Store bank account for lookup (will be validated by UserLookup)
		$account = isset($line['Compte bancaire']) ? trim($line['Compte bancaire']) : '';
		if (empty($account)) {
			$rowErrors[] = $langs->trans('ErrorEmptyBankAccount', $rowNum);
		} else {
			$validated['account_ref'] = $account;
		}

		// Add errors to class errors
		$this->errors = array_merge($this->errors, $rowErrors);

		// Return empty array if there were errors
		if (count($rowErrors) > 0) {
			return array();
		}

		return $validated;
	}

	/**
	 * Validate all rows from parsed data
	 *
	 * @param array $lines Array of data rows from parser
	 * @return array Array of validated data rows (may be empty if all rows had errors)
	 */
	public function validateAll($lines)
	{
		$this->errors = array();
		$validatedRows = array();

		foreach ($lines as $index => $line) {
			$rowNum = $index + 2; // +2 because row 1 is headers and arrays are 0-indexed
			$validated = $this->validateRow($line, $rowNum);
			if (!empty($validated)) {
				$validatedRows[$index] = $validated;
			}
		}

		return $validatedRows;
	}

	/**
	 * Check if validation passed without errors
	 *
	 * @return bool True if no errors
	 */
	public function isValid()
	{
		return empty($this->errors);
	}

	/**
	 * Get required fields list
	 *
	 * @return array Array of required field names
	 */
	public function getRequiredFields()
	{
		return $this->requiredFields;
	}
}
