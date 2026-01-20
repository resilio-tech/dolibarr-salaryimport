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
		// Get last salary ref
		$sql = "SELECT ref FROM ".MAIN_DB_PREFIX."salary ORDER BY CAST(ref AS UNSIGNED) DESC LIMIT 1";
		$result = $this->db->query($sql);
		if (!$result) {
			$this->errors[] = 'Error getting last salary ref: '.$this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($result);
		$this->salaryRefCounter = $obj ? intval($obj->ref) : 0;

		// Get last payment ref
		$sql = "SELECT ref FROM ".MAIN_DB_PREFIX."payment_salary ORDER BY CAST(ref AS UNSIGNED) DESC LIMIT 1";
		$result = $this->db->query($sql);
		if (!$result) {
			$this->errors[] = 'Error getting last payment ref: '.$this->db->lasterror();
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
			$this->errors[] = 'Error inserting salary: '.$this->db->lasterror();
			return -1;
		}

		return $this->db->last_insert_id(MAIN_DB_PREFIX.'salary');
	}

	/**
	 * Insert bank transaction record
	 *
	 * @param string $datep           Transaction date (Y-m-d)
	 * @param float  $amount          Amount (will be negated for salary payment)
	 * @param int    $accountId       Bank account ID
	 * @param string $typepaymentcode Payment type code
	 * @return int Bank transaction ID on success, <0 on error
	 */
	public function insertBankTransaction($datep, $amount, $accountId, $typepaymentcode)
	{
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank";
		$sql .= " (datec, datev, dateo, amount, label, fk_account, fk_user_author, fk_type)";
		$sql .= " VALUES (";
		$sql .= "'".$this->db->escape($datep)."',";
		$sql .= "'".$this->db->escape($datep)."',";
		$sql .= "'".$this->db->escape($datep)."',";
		$sql .= floatval(-$amount).","; // Negative for expense
		$sql .= "'(SalaryPayment)',";
		$sql .= intval($accountId).",";
		$sql .= intval($this->user->id).",";
		$sql .= "'".$this->db->escape($typepaymentcode)."'";
		$sql .= ")";

		$result = $this->db->query($sql);
		if (!$result) {
			$this->errors[] = 'Error inserting bank transaction: '.$this->db->lasterror();
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
			$this->errors[] = 'Error inserting bank URL: '.$this->db->lasterror();
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
	 * @return int Payment salary ID on success, <0 on error
	 */
	public function insertPaymentSalary($ref, $datep, $amount, $typepayment, $label, $datesp, $dateep, $userId, $bankId, $salaryId)
	{
		$entity = $this->conf->entity;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."payment_salary";
		$sql .= " (ref, datep, amount, fk_typepayment, label, datesp, dateep, fk_user, fk_bank, fk_salary, fk_user_author, entity)";
		$sql .= " VALUES (";
		$sql .= "'".$this->db->escape($ref)."',";
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
			$this->errors[] = 'Error inserting payment salary: '.$this->db->lasterror();
			return -1;
		}

		return $this->db->last_insert_id(MAIN_DB_PREFIX.'payment_salary');
	}

	/**
	 * Move PDF file to salary directory and index it
	 *
	 * @param string $pdfPath  Source PDF file path
	 * @param int    $salaryId Salary ID
	 * @return int 1 on success, <0 on error
	 */
	public function movePdfToSalary($pdfPath, $salaryId)
	{
		if (empty($pdfPath) || !file_exists($pdfPath)) {
			return 1; // No PDF to move is not an error
		}

		$destDir = DOL_DATA_ROOT.'/salaries/'.$salaryId;

		if (!is_dir($destDir)) {
			if (!dol_mkdir($destDir)) {
				$this->errors[] = 'Failed to create directory: '.$destDir;
				return -1;
			}
		}

		$filename = basename($pdfPath);
		$destPath = $destDir.'/'.$filename;

		if (!dol_move($pdfPath, $destPath)) {
			$this->errors[] = 'Failed to move PDF file to: '.$destPath;
			return -2;
		}

		// Index the file in database
		$salary = new Salary($this->db);
		$salary->id = $salaryId;

		$result = addFileIntoDatabaseIndex(
			$destDir,
			$filename,
			$filename,
			'uploaded',
			0,
			$salary
		);

		if ($result < 0) {
			$this->errors[] = 'Failed to index PDF file in database';
			return -3;
		}

		return 1;
	}

	/**
	 * Persist a single row of enriched salary data
	 *
	 * @param array $data Enriched data from SalaryImportUserLookup
	 * @return array Result with 'salaryId', 'paymentId', 'bankId' on success, or empty with errors
	 */
	public function persistRow($data)
	{
		$result = array();
		$this->errors = array();

		// Initialize counters if needed
		if (!$this->countersInitialized) {
			if ($this->initCounters() < 0) {
				return $result;
			}
		}

		// Generate references
		$salaryRef = $this->getNextSalaryRef();
		$paymentRef = $this->getNextPaymentRef();

		// Insert salary
		$salaryId = $this->insertSalary(
			$salaryRef,
			$data['datep'],
			$data['amount'],
			$data['typepayment'],
			$data['label'],
			$data['datesp'],
			$data['dateep'],
			$data['paye'],
			$data['userId'],
			$data['account']
		);

		if ($salaryId < 0) {
			return $result;
		}

		// Insert bank transaction
		$bankId = $this->insertBankTransaction(
			$data['datep'],
			$data['amount'],
			$data['account'],
			$data['typepaymentcode']
		);

		if ($bankId < 0) {
			return $result;
		}

		// Insert payment salary BEFORE bank_url (we need paymentId for the link)
		$paymentId = $this->insertPaymentSalary(
			$paymentRef,
			$data['datep'],
			$data['amount'],
			$data['typepayment'],
			$data['label'],
			$data['datesp'],
			$data['dateep'],
			$data['userId'],
			$bankId,
			$salaryId
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
			$data['userId'],
			'/user/card.php?id=',
			$data['userName'],
			'user'
		);

		if ($urlResult < 0) {
			return $result;
		}

		// Move PDF if present
		if (!empty($data['pdf'])) {
			$pdfResult = $this->movePdfToSalary($data['pdf'], $salaryId);
			if ($pdfResult < 0) {
				// Log error but don't fail the whole import
				// PDF errors are non-critical
			}
		}

		$result = array(
			'salaryId' => $salaryId,
			'salaryRef' => $salaryRef,
			'paymentId' => $paymentId,
			'paymentRef' => $paymentRef,
			'bankId' => $bankId
		);

		return $result;
	}

	/**
	 * Persist all enriched salary data rows
	 *
	 * @param array $enrichedRows Array of enriched data rows from SalaryImportUserLookup
	 * @return array Array of results for each row
	 */
	public function persistAll($enrichedRows)
	{
		$this->errors = array();
		$results = array();

		// Initialize counters once for all rows
		if ($this->initCounters() < 0) {
			return $results;
		}

		foreach ($enrichedRows as $index => $data) {
			$rowNum = $index + 2; // +2 because row 1 is headers and arrays are 0-indexed
			$result = $this->persistRow($data);

			if (empty($result)) {
				$this->errors[] = 'Error persisting row '.$rowNum.': '.implode(', ', $this->errors);
			} else {
				$results[$index] = $result;
			}
		}

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
