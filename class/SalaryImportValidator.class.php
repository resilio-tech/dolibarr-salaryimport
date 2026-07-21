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
	 * Maximum length of a salary notation.
	 *
	 * The notation is persisted as llx_salary.ref, declared varchar(30) by Dolibarr: a longer value
	 * would be truncated or rejected by the database depending on the SQL mode, so it is refused
	 * here with a message pointing at the offending row.
	 */
	const NOTATION_MAX_LENGTH = 30;

	/**
	 * Maximum length of an imported label.
	 *
	 * The label is persisted as llx_payment_salary.label, a varchar(255), on every payment of the
	 * salary. Refusing it here gives the offending row number instead of a failed INSERT halfway
	 * through the import.
	 */
	const LABEL_MAX_LENGTH = 255;

	/**
	 * @var array Error messages
	 */
	public $errors = array();

	/**
	 * @var array Required fields
	 */
	protected $requiredFields = array(
		'Salaire',
		'Réf paiement',
		'Prénom',
		'Nom',
		'Date de paiement',
		'Montant payé',
		'Salaire total CHF',
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

		// Keep the row number for group-level error messages
		$validated['row_num'] = $rowNum;

		// Validate salary notation (grouping key, e.g. "2026-05-5")
		$notation = isset($line['Salaire']) ? trim((string) $line['Salaire']) : '';
		if ($notation === '') {
			$rowErrors[] = $langs->trans('ErrorEmptySalaryNotation', $rowNum);
		} elseif (mb_strlen($notation) > self::NOTATION_MAX_LENGTH) {
			$rowErrors[] = $langs->trans('ErrorSalaryNotationTooLong', self::NOTATION_MAX_LENGTH, $rowNum);
		} else {
			$validated['salary_notation'] = $notation;
		}

		// Validate payment reference (e.g. "2026-05-5-EUR")
		$paymentRef = isset($line['Réf paiement']) ? trim((string) $line['Réf paiement']) : '';
		if ($paymentRef === '') {
			$rowErrors[] = $langs->trans('ErrorEmptyPaymentRef', $rowNum);
		} else {
			$validated['payment_ref'] = $paymentRef;
		}

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

		// Validate paid amount (nominal, in the bank account currency)
		$amountNominal = isset($line['Montant payé']) ? $line['Montant payé'] : null;
		$parsedNominal = $this->parseAmount($amountNominal);
		if ($parsedNominal === false) {
			$rowErrors[] = $langs->trans('ErrorEmptyOrInvalidAmount', $rowNum);
		} else {
			$validated['amount_nominal'] = $parsedNominal;
		}

		// Validate paid amount in company currency (CHF).
		// Optional column: defaults to the nominal amount when absent (mono-currency account).
		$amountChfRaw = isset($line['Montant CHF']) ? $line['Montant CHF'] : null;
		if ($amountChfRaw === null || $amountChfRaw === '') {
			if ($parsedNominal !== false) {
				$validated['amount_chf'] = $parsedNominal;
			}
		} else {
			$parsedChf = $this->parseAmount($amountChfRaw);
			if ($parsedChf === false) {
				$rowErrors[] = $langs->trans('ErrorEmptyOrInvalidAmountChf', $rowNum);
			} else {
				$validated['amount_chf'] = $parsedChf;
			}
		}

		// Validate total salary amount (company currency, repeated on each line of the group)
		$totalRaw = isset($line['Salaire total CHF']) ? $line['Salaire total CHF'] : null;
		$parsedTotal = $this->parseAmount($totalRaw);
		if ($parsedTotal === false) {
			$rowErrors[] = $langs->trans('ErrorEmptyOrInvalidTotalSalary', $rowNum);
		} else {
			$validated['total_salary_chf'] = $parsedTotal;
		}

		// Validate label
		$label = isset($line['Libellé']) ? trim((string) $line['Libellé']) : '';
		if ($label === '') {
			$rowErrors[] = $langs->trans('ErrorEmptyLabel', $rowNum);
		} elseif (mb_strlen($label) > self::LABEL_MAX_LENGTH) {
			$rowErrors[] = $langs->trans('ErrorLabelTooLong', self::LABEL_MAX_LENGTH, $rowNum);
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
	 * Validate consistency across rows sharing the same salary notation.
	 *
	 * Each notation forms a single salary paid in N payments. For every group this checks:
	 *  - all rows belong to the same employee,
	 *  - the pay date and period are identical on every row,
	 *  - the total salary amount is identical on every row,
	 *  - the sum of the CHF payment amounts equals the declared total.
	 *
	 * Errors are appended to $this->errors with the offending row numbers.
	 *
	 * @param array $validatedRows Rows returned by validateAll() (must contain salary_notation, row_num, ...)
	 * @return bool True if every group is consistent
	 */
	public function validateGroups($validatedRows)
	{
		global $langs;

		// Group rows by salary notation
		$groups = array();
		foreach ($validatedRows as $row) {
			if (!isset($row['salary_notation']) || $row['salary_notation'] === '') {
				continue;
			}
			$groups[$row['salary_notation']][] = $row;
		}

		$valid = true;

		foreach ($groups as $notation => $rows) {
			$rowNums = array();
			foreach ($rows as $r) {
				$rowNums[] = isset($r['row_num']) ? $r['row_num'] : '?';
			}
			$rowList = implode(', ', $rowNums);

			// 1. Single employee per group
			$employees = array();
			foreach ($rows as $r) {
				if (isset($r['firstname'], $r['lastname'])) {
					$employees[strtolower($r['firstname'].' '.$r['lastname'])] = true;
				}
			}
			if (count($employees) > 1) {
				$this->errors[] = $langs->trans('ErrorGroupMultipleEmployees', $notation, $rowList);
				$valid = false;
			}

			// 1b. Identical dates on every row: a salary has a single pay date and period,
			// even when split into several payments (only the account/currency may differ).
			$dateSignatures = array();
			foreach ($rows as $r) {
				$dateSignatures[
					(isset($r['datep']) ? $r['datep'] : '').'|'
					.(isset($r['datesp']) ? $r['datesp'] : '').'|'
					.(isset($r['dateep']) ? $r['dateep'] : '')
				] = true;
			}
			if (count($dateSignatures) > 1) {
				$this->errors[] = $langs->trans('ErrorGroupDateMismatch', $notation, $rowList);
				$valid = false;
			}

			// 2. Identical total on every row (compared rounded to 2 decimals to avoid float noise)
			$totals = array();
			foreach ($rows as $r) {
				if (isset($r['total_salary_chf'])) {
					$totals[number_format((float) $r['total_salary_chf'], 2, '.', '')] = true;
				}
			}
			if (count($totals) > 1) {
				$this->errors[] = $langs->trans('ErrorGroupTotalMismatch', $notation, $rowList);
				$valid = false;
				continue; // ambiguous total: skip the sum check for this group
			}

			// 3. Sum of CHF payments equals the declared total.
			// Round both to 2 decimals (the reconciliation must be exact to the cent).
			$sumChf = 0.0;
			foreach ($rows as $r) {
				if (isset($r['amount_chf'])) {
					$sumChf += $r['amount_chf'];
				}
			}
			$total = isset($rows[0]['total_salary_chf']) ? $rows[0]['total_salary_chf'] : null;
			if ($total !== null && round($sumChf, 2) !== round((float) $total, 2)) {
				// Format both amounts to 2 decimals so the user sees cent-accurate values
				// (raw floats could surface noise like 4999.9999999997).
				$this->errors[] = $langs->trans(
					'ErrorGroupSumMismatch',
					$notation,
					$rowList,
					number_format($sumChf, 2, '.', ''),
					number_format((float) $total, 2, '.', '')
				);
				$valid = false;
			}
		}

		return $valid;
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
