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
 * \file       class/SalaryImportPersister.class.php
 * \ingroup    salaryimport
 * \brief      Class for persisting salary import data to database
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/salaries/class/salary.class.php';

/**
 * Class SalaryImportPersister
 *
 * Handles database persistence for salary import
 */
class SalaryImportPersister
{
	/**
	 * @var DoliDB Database handler
	 */
	protected $db;

	/**
	 * @var User Current user
	 */
	protected $user;

	/**
	 * @var Conf Global configuration
	 */
	protected $conf;

	/**
	 * @var array Error messages
	 */
	public $errors = array();

	/**
	 * @var array Warning messages (non-blocking errors like missing PDFs)
	 */
	public $warnings = array();

	/**
	 * @var int Counter for salary references
	 */
	protected $salaryRefCounter;

	/**
	 * @var int Counter for payment references
	 */
	protected $paymentRefCounter;

	/**
	 * @var bool Whether counters have been initialized
	 */
	protected $countersInitialized = false;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db   Database handler
	 * @param User   $user Current user
	 * @param Conf   $conf Global configuration (optional)
	 */
	public function __construct($db, $user, $confParam = null)
	{
		global $conf;

		$this->db = $db;
		$this->user = $user;
		$this->conf = $confParam !== null ? $confParam : $conf;
	}

	/**
	 * Initialize reference counters by fetching last refs from database
	 *
	 * @return int 1 on success, <0 on error
	 */
	public function initCounters()
	{
		global $langs;

		// Get last salary ref
		$sql = "SELECT ref FROM ".MAIN_DB_PREFIX."salary ORDER BY CAST(ref AS UNSIGNED) DESC LIMIT 1";
		$result = $this->db->query($sql);
		if (!$result) {
			$this->errors[] = $langs->trans('ErrorGetLastSalaryRef', $this->db->lasterror());
			return -1;
		}
		$obj = $this->db->fetch_object($result);
		$this->salaryRefCounter = $obj ? intval($obj->ref) : 0;

		// Get last payment ref
		$sql = "SELECT ref FROM ".MAIN_DB_PREFIX."payment_salary ORDER BY CAST(ref AS UNSIGNED) DESC LIMIT 1";
		$result = $this->db->query($sql);
		if (!$result) {
			$this->errors[] = $langs->trans('ErrorGetLastPaymentRef', $this->db->lasterror());
			return -2;
		}
		$obj = $this->db->fetch_object($result);
		$this->paymentRefCounter = $obj ? intval($obj->ref) : 0;

		$this->countersInitialized = true;
		return 1;
	}

	/**
	 * Get next salary reference
	 *
	 * @return string Next salary reference
	 */
	public function getNextSalaryRef()
	{
		if (!$this->countersInitialized) {
			$this->initCounters();
		}
		$this->salaryRefCounter++;
		return (string) $this->salaryRefCounter;
	}

	/**
	 * Get next payment reference
	 *
	 * @return string Next payment reference
	 */
	public function getNextPaymentRef()
	{
		if (!$this->countersInitialized) {
			$this->initCounters();
		}
		$this->paymentRefCounter++;
		return (string) $this->paymentRefCounter;
	}

	/**
	 * Insert salary record
	 *
	 * @param string $ref         Salary reference
	 * @param string $datep       Payment date (Y-m-d)
	 * @param float  $amount      Amount
	 * @param int    $typepayment Payment type ID
	 * @param string $label       Label
	 * @param string $datesp      Start date (Y-m-d)
	 * @param string $dateep      End date (Y-m-d)
	 * @param int    $paye        Paid status (0 or 1)
	 * @param int    $userId      User ID
	 * @param int    $accountId   Bank account ID
	 * @return int Salary ID on success, <0 on error
	 */
	public function insertSalary($ref, $datep, $amount, $typepayment, $label, $datesp, $dateep, $paye, $userId, $accountId)
	{
		$entity = $this->conf->entity;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."salary";
		$sql .= " (ref, datep, amount, fk_typepayment, label, datesp, dateep, paye, fk_user, fk_account, fk_user_author, entity)";
		$sql .= " VALUES (";
		$sql .= "'".$this->db->escape($ref)."',";
		$sql .= "'".$this->db->escape($datep)."',";
		$sql .= floatval($amount).",";
		$sql .= intval($typepayment).",";
		$sql .= "'".$this->db->escape($label)."',";
		$sql .= "'".$this->db->escape($datesp)."',";
		$sql .= "'".$this->db->escape($dateep)."',";
		$sql .= intval($paye).",";
		$sql .= intval($userId).",";
		$sql .= intval($accountId).",";
		$sql .= intval($this->user->id).",";
		$sql .= intval($entity);
		$sql .= ")";

		$result = $this->db->query($sql);
		if (!$result) {
			global $langs;
			$this->errors[] = $langs->trans('ErrorInsertSalary', $this->db->lasterror());
			return -1;
		}

		return $this->db->last_insert_id(MAIN_DB_PREFIX.'salary');
	}

	/**
	 * Insert bank transaction record
	 *
	 * @param string $datep              Transaction date (Y-m-d)
	 * @param float  $amount             Amount in the account currency (will be negated for salary payment)
	 * @param int    $accountId          Bank account ID
	 * @param string $typepaymentcode    Payment type code
	 * @param float  $amountMainCurrency Amount in the company currency, or null when the account is already
	 *                                   in the company currency (column left NULL)
	 * @return int Bank transaction ID on success, <0 on error
	 */
	public function insertBankTransaction($datep, $amount, $accountId, $typepaymentcode, $amountMainCurrency = null)
	{
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank";
		$sql .= " (datec, datev, dateo, amount, amount_main_currency, label, fk_account, fk_user_author, fk_type)";
		$sql .= " VALUES (";
		$sql .= "'".$this->db->escape($datep)."',";
		$sql .= "'".$this->db->escape($datep)."',";
		$sql .= "'".$this->db->escape($datep)."',";
		$sql .= floatval(-$amount).","; // Negative for expense
		$sql .= ($amountMainCurrency === null ? "NULL" : floatval(-$amountMainCurrency)).","; // Negative, NULL when same currency
		$sql .= "'(SalaryPayment)',";
		$sql .= intval($accountId).",";
		$sql .= intval($this->user->id).",";
		$sql .= "'".$this->db->escape($typepaymentcode)."'";
		$sql .= ")";

		$result = $this->db->query($sql);
		if (!$result) {
			global $langs;
			$this->errors[] = $langs->trans('ErrorInsertBankTransaction', $this->db->lasterror());
			return -1;
		}

		return $this->db->last_insert_id(MAIN_DB_PREFIX.'bank');
	}

	/**
	 * Insert bank URL record (link between bank transaction and related object)
	 *
	 * @param int    $bankId  Bank transaction ID
	 * @param int    $urlId   Related object ID
	 * @param string $url     URL path
	 * @param string $label   Label for the link
	 * @param string $type    Type of link (e.g., 'payment_salary', 'user')
	 * @return int 1 on success, <0 on error
	 */
	public function insertBankUrl($bankId, $urlId, $url, $label, $type)
	{
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_url";
		$sql .= " (fk_bank, url_id, url, label, type)";
		$sql .= " VALUES (";
		$sql .= intval($bankId).",";
		$sql .= intval($urlId).",";
		$sql .= "'".$this->db->escape($url)."',";
		$sql .= "'".$this->db->escape($label)."',";
		$sql .= "'".$this->db->escape($type)."'";
		$sql .= ")";

		$result = $this->db->query($sql);
		if (!$result) {
			global $langs;
			$this->errors[] = $langs->trans('ErrorInsertBankUrl', $this->db->lasterror());
			return -1;
		}

		return 1;
	}

	/**
	 * Insert payment salary record
	 *
	 * @param string $ref         Payment reference
	 * @param string $datep       Payment date (Y-m-d)
	 * @param float  $amount      Amount
	 * @param int    $typepayment Payment type ID
	 * @param string $label       Label
	 * @param string $datesp      Start date (Y-m-d)
	 * @param string $dateep      End date (Y-m-d)
	 * @param int    $userId      User ID
	 * @param int    $bankId      Bank transaction ID
	 * @param int    $salaryId    Salary ID
	 * @param string $numPayment  Payment notation displayed as the reference (payment_salary.num_payment)
	 * @return int Payment salary ID on success, <0 on error
	 */
	public function insertPaymentSalary($ref, $datep, $amount, $typepayment, $label, $datesp, $dateep, $userId, $bankId, $salaryId, $numPayment = '')
	{
		$entity = $this->conf->entity;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."payment_salary";
		$sql .= " (ref, num_payment, datep, amount, fk_typepayment, label, datesp, dateep, fk_user, fk_bank, fk_salary, fk_user_author, entity)";
		$sql .= " VALUES (";
		$sql .= "'".$this->db->escape($ref)."',";
		$sql .= "'".$this->db->escape($numPayment)."',";
		$sql .= "'".$this->db->escape($datep)."',";
		$sql .= floatval($amount).",";
		$sql .= intval($typepayment).",";
		$sql .= "'".$this->db->escape($label)."',";
		$sql .= "'".$this->db->escape($datesp)."',";
		$sql .= "'".$this->db->escape($dateep)."',";
		$sql .= intval($userId).",";
		$sql .= intval($bankId).",";
		$sql .= intval($salaryId).",";
		$sql .= intval($this->user->id).",";
		$sql .= intval($entity);
		$sql .= ")";

		$result = $this->db->query($sql);
		if (!$result) {
			global $langs;
			$this->errors[] = $langs->trans('ErrorInsertPaymentSalary', $this->db->lasterror());
			return -1;
		}

		return $this->db->last_insert_id(MAIN_DB_PREFIX.'payment_salary');
	}

	/**
	 * Move PDF file to salary directory and index it
	 *
	 * Note: a database indexing failure is non-blocking (the file is already in the
	 * right directory), so it is only logged and does not affect the return value.
	 *
	 * @param string $pdfPath   Source PDF file path
	 * @param int    $salaryId  Salary ID
	 * @return int 1 on success (including non-blocking index failure), <0 on file move error
	 */
	public function movePdfToSalary($pdfPath, $salaryId)
	{
		global $langs;

		if (empty($pdfPath)) {
			dol_syslog("SalaryImportPersister::movePdfToSalary - No PDF path provided for salary ".$salaryId, LOG_DEBUG);
			return 1; // No PDF to move is not an error
		}

		if (!file_exists($pdfPath)) {
			dol_syslog("SalaryImportPersister::movePdfToSalary - PDF file not found: ".$pdfPath." for salary ".$salaryId, LOG_WARNING);
			$this->errors[] = $langs->trans('ErrorPdfNotFound', $pdfPath);
			return -1;
		}

		// Dolibarr Salary::fetch() sets ref = rowid, so directory uses rowid.
		// Use the module dir_output so the path stays correct under multicompany (entity >= 2).
		// Fall back to the default path if the salaries module object is not available.
		$salariesDir = isset($this->conf->salaries->dir_output) ? $this->conf->salaries->dir_output : DOL_DATA_ROOT.'/salaries';
		$destDir = $salariesDir.'/'.$salaryId;

		if (!is_dir($destDir)) {
			if (!dol_mkdir($destDir)) {
				$this->errors[] = $langs->trans('ErrorCreateDirectory', $destDir);
				return -1;
			}
		}

		$filename = basename($pdfPath);
		$destPath = $destDir.'/'.$filename;

		// Move without auto-indexing: dol_move() would create an ecm_files row with no object
		// link, which then collides on the (filepath, filename, entity) unique key with our
		// own object-aware indexing below. We do the linked indexing ourselves instead.
		if (!dol_move($pdfPath, $destPath, '0', 1, 0, 0)) {
			$this->errors[] = $langs->trans('ErrorMovePdf', $destPath);
			return -2;
		}

		// Index the file in database, linked to the salary object
		$salary = new Salary($this->db);
		$salary->id = $salaryId;
		$salary->entity = $this->conf->entity;

		$result = addFileIntoDatabaseIndex(
			$destDir,
			$filename,
			$filename,
			'uploaded',
			0,
			$salary
		);

		if ($result < 0) {
			// Indexation failure is not critical - file is already in the right directory
			dol_syslog("SalaryImportPersister::movePdfToSalary - Failed to index PDF in database for salary ".$salaryId, LOG_WARNING);
		}

		return 1;
	}

	/**
	 * Persist one salary group (all rows sharing the same notation).
	 *
	 * Creates a single salary (amount = total CHF, label = notation), then for each line of the
	 * group a bank transaction + a payment_salary + the bank links. The PDF, if any, is moved once.
	 *
	 * @param string $notation Salary notation shared by every row (e.g. "2026-05-5")
	 * @param array  $rows     Enriched rows of the group from SalaryImportUserLookup
	 * @return array Result with 'salaryId', 'salaryRef', 'notation' and 'payments', or empty on error
	 */
	public function persistGroup($notation, $rows)
	{
		$result = array();
		$this->errors = array();

		// Initialize counters if needed
		if (!$this->countersInitialized) {
			if ($this->initCounters() < 0) {
				return $result;
			}
		}

		// Sort the group by payment reference so the salary-level fields taken from the first row
		// (account, payment type) do not depend on the XLSX row order. Dates are already validated
		// identical across the group by SalaryImportValidator::validateGroups().
		usort($rows, function ($a, $b) {
			$refA = isset($a['payment_ref']) ? (string) $a['payment_ref'] : '';
			$refB = isset($b['payment_ref']) ? (string) $b['payment_ref'] : '';
			return strcmp($refA, $refB);
		});
		$first = reset($rows);

		// One salary per notation: amount = total in company currency, label = notation.
		// Date/period are the same on every row; account/type come from the first row of the
		// deterministically sorted group (they may legitimately differ between payments).
		$salaryRef = $this->getNextSalaryRef();
		$salaryId = $this->insertSalary(
			$salaryRef,
			$first['datep'],
			$first['total_salary_chf'],
			$first['typepayment'],
			$notation,
			$first['datesp'],
			$first['dateep'],
			$first['paye'],
			$first['userId'],
			$first['account']
		);

		if ($salaryId < 0) {
			return $result;
		}

		$companyCurrency = $this->conf->currency;
		$payments = array();

		// One bank transaction + payment_salary + links per line of the group
		foreach ($rows as $row) {
			// amount_main_currency only when the account currency differs from the company currency
			$accountCurrency = isset($row['account_currency']) ? $row['account_currency'] : $companyCurrency;
			$amountMainCurrency = ($accountCurrency !== $companyCurrency) ? $row['amount_chf'] : null;

			$bankId = $this->insertBankTransaction(
				$row['datep'],
				$row['amount_nominal'],
				$row['account'],
				$row['typepaymentcode'],
				$amountMainCurrency
			);

			if ($bankId < 0) {
				return $result;
			}

			// Insert payment salary BEFORE bank_url (we need paymentId for the link).
			// amount = CHF (company currency); num_payment = the payment notation.
			$paymentRef = $this->getNextPaymentRef();
			$paymentId = $this->insertPaymentSalary(
				$paymentRef,
				$row['datep'],
				$row['amount_chf'],
				$row['typepayment'],
				$row['label'],
				$row['datesp'],
				$row['dateep'],
				$row['userId'],
				$bankId,
				$salaryId,
				$row['payment_ref']
			);

			if ($paymentId < 0) {
				return $result;
			}

			// Insert bank URLs - link to payment_salary (not salary)
			$urlResult = $this->insertBankUrl(
				$bankId,
				$paymentId,
				'/salaries/payment_salary/card.php?id=',
				'(paiement)',
				'payment_salary'
			);

			if ($urlResult < 0) {
				return $result;
			}

			$urlResult = $this->insertBankUrl(
				$bankId,
				$row['userId'],
				'/user/card.php?id=',
				$row['userName'],
				'user'
			);

			if ($urlResult < 0) {
				return $result;
			}

			$payments[] = array(
				'paymentId' => $paymentId,
				'paymentRef' => $paymentRef,
				'num_payment' => $row['payment_ref'],
				'bankId' => $bankId
			);
		}

		// Move PDF once per salary (first line of the group that carries one)
		$pdfPath = '';
		foreach ($rows as $row) {
			if (!empty($row['pdf'])) {
				$pdfPath = $row['pdf'];
				break;
			}
		}
		if (!empty($pdfPath)) {
			$pdfResult = $this->movePdfToSalary($pdfPath, $salaryId);
			if ($pdfResult < 0) {
				// Collect as warning with context (employee name, salary ID)
				$context = $first['userName'].' (Salary #'.$salaryId.')';
				foreach ($this->errors as $error) {
					$this->warnings[] = $context.': '.$error;
				}
				dol_syslog("SalaryImportPersister::persistGroup - Failed to move PDF for ".$context.": ".implode(', ', $this->errors), LOG_ERR);
				$this->errors = array(); // Clear errors so they don't block
			}
		}

		$result = array(
			'salaryId' => $salaryId,
			'salaryRef' => $salaryRef,
			'notation' => $notation,
			'payments' => $payments
		);

		return $result;
	}

	/**
	 * Persist all enriched salary data rows, grouped by notation.
	 *
	 * Rows sharing the same notation form a single salary paid in N payments.
	 *
	 * @param array $enrichedRows Array of enriched data rows from SalaryImportUserLookup
	 * @return array Array of results keyed by notation
	 */
	public function persistAll($enrichedRows)
	{
		global $langs;
		$this->errors = array();
		$results = array();
		$groupErrors = array();

		// Initialize counters once for all rows
		if ($this->initCounters() < 0) {
			return $results;
		}

		// Group rows by salary notation (preserve first-seen order)
		$groups = array();
		foreach ($enrichedRows as $index => $data) {
			$notation = isset($data['salary_notation']) ? $data['salary_notation'] : ('row_'.$index);
			$groups[$notation][] = $data;
		}

		foreach ($groups as $notation => $rows) {
			$result = $this->persistGroup($notation, $rows);

			if (empty($result)) {
				$groupErrors[] = $langs->trans('ErrorPersistGroup', $notation, implode(', ', $this->errors));
			} else {
				$results[$notation] = $result;
			}
		}

		$this->errors = $groupErrors;

		return $results;
	}

	/**
	 * Check if persistence passed without errors
	 *
	 * @return bool True if no errors
	 */
	public function isValid()
	{
		return empty($this->errors);
	}
}
