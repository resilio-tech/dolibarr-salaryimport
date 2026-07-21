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
	 * Maximum length of a salary label, matching llx_salary.label and llx_payment_salary.label,
	 * both declared varchar(255).
	 */
	const LABEL_MAX_LENGTH = 255;

	/**
	 * A salary carrying this notation was created by a previous run of this import.
	 */
	const STATUS_IMPORTED = 'imported';

	/**
	 * The notation is taken by a salary this import did not create, so it can be neither imported
	 * nor silently skipped.
	 */
	const STATUS_CONFLICT = 'conflict';

	/**
	 * Maximum length of a payment reference, matching llx_payment_salary.num_payment varchar(50).
	 *
	 * Kept in sync with SalaryImportValidator::PAYMENT_REF_MAX_LENGTH, which rejects an oversized
	 * reference at preview time with the offending row number.
	 */
	const PAYMENT_REF_MAX_LENGTH = 50;

	/**
	 * Maximum length of a salary ref, matching llx_salary.ref declared varchar(30).
	 *
	 * Kept in sync with SalaryImportValidator::NOTATION_MAX_LENGTH, which rejects an oversized
	 * notation at preview time with the offending row number.
	 */
	const REF_MAX_LENGTH = 30;

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
	 * Initialize the payment reference counter by fetching the last ref from database
	 *
	 * Salaries do not use a counter: their ref is the salary notation (see persistGroup).
	 *
	 * @return int 1 on success, <0 on error
	 */
	public function initCounters()
	{
		global $langs;

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
	 * Find, among the given salary notations, those that already exist in this entity, and say why.
	 *
	 * A notation identifies one salary, so re-importing it would create a duplicate. The lookup
	 * matches the ref, but also a label equal to the notation: salaries imported by 2.2.0 were
	 * stored with a counter as ref and the bare notation as label. Salaries imported before 2.2.0
	 * stored no notation at all and cannot be detected.
	 *
	 * Matching is done by the database, not in PHP, so it follows the column collation exactly. A
	 * PHP-side equality would disagree with an accent-insensitive collation and let through a
	 * duplicate the database had already matched.
	 *
	 * Each hit is classified, because the two cases must not be treated alike:
	 *
	 *  - STATUS_IMPORTED: the salary was created by this module for that notation. Skipping it on
	 *    re-import is safe, it is already in the database.
	 *  - STATUS_CONFLICT: the value is taken by a salary this import did not create, typically a
	 *    counter ref left by 2.2.0 that happens to look like the notation. Importing is impossible
	 *    (uk_salary_ref) but skipping would silently drop a payroll entry that was never imported,
	 *    so this always has to be raised to the user.
	 *
	 * The result is a list, never a map keyed by notation: PHP turns a decimal-integer-like key
	 * into an int, and a notation such as "2026" would then stop comparing equal to its own string.
	 *
	 * @param array $notations Salary notations to look for
	 * @return array|null List of array('notation' => string, 'status' => STATUS_*), or null on error
	 */
	public function findExistingSalaryRefs($notations)
	{
		global $langs;

		$wanted = $this->normalizeNotations($notations);
		if (empty($wanted)) {
			return array();
		}

		// One SELECT per notation, unioned: it lets the database report which notation a row matched
		// (and whether it matched on ref or on label) under its own collation rules.
		$selects = array();
		foreach ($wanted as $notation) {
			$escaped = $this->db->escape($notation);
			$select = "SELECT '".$escaped."' AS notation,";
			$select .= " CASE WHEN ref = '".$escaped."' THEN 1 ELSE 0 END AS ref_match,";
			$select .= " CASE WHEN label = '".$escaped."' THEN 1 ELSE 0 END AS label_match,";
			$select .= " ref, label";
			$select .= " FROM ".MAIN_DB_PREFIX."salary";
			$select .= " WHERE entity = ".intval($this->conf->entity);
			$select .= " AND (ref = '".$escaped."' OR label = '".$escaped."')";
			$selects[] = $select;
		}

		$result = $this->db->query(implode(' UNION ALL ', $selects));
		if (!$result) {
			$this->errors[] = $langs->trans('ErrorCheckSalaryRefs', $this->db->lasterror());
			return null;
		}

		$statuses = array();
		while ($obj = $this->db->fetch_object($result)) {
			$notation = (string) $obj->notation;
			$label = ($obj->label === null) ? '' : (string) $obj->label;

			$ref = ($obj->ref === null) ? '' : (string) $obj->ref;

			$status = $this->classifySalaryMatch(!empty($obj->ref_match), !empty($obj->label_match), $ref, $label, $notation);
			if ($status === '') {
				continue;
			}
			// A conflict outranks an import: if any row makes the value unusable, say so.
			$key = array_search($notation, array_column($statuses, 'notation'), true);
			if ($key === false) {
				$statuses[] = array('notation' => $notation, 'status' => $status);
			} elseif ($status === self::STATUS_CONFLICT) {
				$statuses[$key]['status'] = $status;
			}
		}

		return $statuses;
	}

	/**
	 * Deduplicate notations and turn them into strings.
	 *
	 * Notations reach the persister as array keys in places, and PHP normalises a decimal-integer
	 * key to an int, so "2026" must be cast back before it is compared or sent to the database.
	 *
	 * @param array $notations Raw notations
	 * @return array List of unique non-empty notation strings
	 */
	public function normalizeNotations($notations)
	{
		$wanted = array();

		foreach ((array) $notations as $notation) {
			$notation = (string) $notation;
			if ($notation !== '' && !in_array($notation, $wanted, true)) {
				$wanted[] = $notation;
			}
		}

		return $wanted;
	}

	/**
	 * Read the status of a notation out of a findExistingSalaryRefs() result.
	 *
	 * @param array  $existing List returned by findExistingSalaryRefs()
	 * @param string $notation Notation to look up
	 * @return string STATUS_IMPORTED, STATUS_CONFLICT, or '' when the notation is not in the list
	 */
	public function statusForNotation($existing, $notation)
	{
		$notation = (string) $notation;

		foreach ((array) $existing as $entry) {
			if (isset($entry['notation']) && (string) $entry['notation'] === $notation) {
				return isset($entry['status']) ? $entry['status'] : '';
			}
		}

		return '';
	}

	/**
	 * Decide what an existing salary row means for a notation we are about to import.
	 *
	 * Dolibarr itself never writes llx_salary.ref: Salary::create() omits the column, update() and
	 * setPaid() leave it alone, and fetch() only overwrites the property in memory with the rowid.
	 * A stored ref equal to the notation therefore comes from this module, and the only question is
	 * whether it identifies this very salary or is a counter left by 2.2.0 that happens to look
	 * like the notation. Only a purely numeric notation can collide with such a counter, so any
	 * other notation is claimed as ours even when the label was edited afterwards.
	 *
	 * @param bool   $refMatches   Whether the database matched the notation on llx_salary.ref
	 * @param bool   $labelMatches Whether the database matched the notation on llx_salary.label
	 * @param string $ref          Salary ref read from the database ('' when NULL)
	 * @param string $label        Salary label read from the database ('' when NULL)
	 * @param string $notation     Notation being imported
	 * @return string STATUS_IMPORTED, STATUS_CONFLICT, or '' when the row does not match
	 */
	public function classifySalaryMatch($refMatches, $labelMatches, $ref, $label, $notation)
	{
		// strlen and not mb_strlen: strncasecmp counts bytes, so the length must be in bytes too,
		// otherwise a multibyte notation compares short and the trailing space is never checked.
		$labelIsPrefixed = (strncasecmp($label, $notation.' ', strlen($notation) + 1) === 0);

		if ($refMatches) {
			if ($labelMatches || $labelIsPrefixed) {
				return self::STATUS_IMPORTED; // current shape: notation in both columns
			}

			// The label carries no trace of the notation. Ours anyway, but it may be a 2.2.0 counter
			// pointing at a different salary, and skipping that one would drop a real payroll entry.
			return ctype_digit($notation) ? self::STATUS_CONFLICT : self::STATUS_IMPORTED;
		}

		if ($labelMatches) {
			// 2.2.0 shape: bare notation as label, counter as ref. Every release of this module has
			// written a ref, and Dolibarr itself never writes one, so an empty ref means the row was
			// created from the UI and merely happens to be labelled like the notation. Its ref is
			// NULL, so the notation is still free: not our import, and not a duplicate either.
			return ($ref !== '') ? self::STATUS_IMPORTED : '';
		}

		return '';
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
	 * Build the label of a salary: the notation followed by the label imported from the XLSX.
	 *
	 * Dolibarr does not display llx_salary.ref (Salary::fetch() replaces it with the rowid), so the
	 * notation has to live in the label to stay visible on the card and searchable in the list.
	 * An empty imported label degrades to the notation alone, and a label already prefixed with the
	 * notation is left untouched so re-imported data is not prefixed twice. "Already prefixed" means
	 * the whole notation followed by a space (or nothing), so notation "2026-05-5" is still prefixed
	 * onto a label starting with "2026-05-50".
	 *
	 * The result is truncated to llx_salary.label's varchar(255): the prefix makes the stored label
	 * longer than the imported one, and a label that long is a display string, not something worth
	 * failing a whole import for.
	 *
	 * @param string $notation Salary notation (e.g. "2026-05-5")
	 * @param string $label    Label imported from the XLSX
	 * @return string Label to store in llx_salary.label
	 */
	public function buildSalaryLabel($notation, $label)
	{
		$label = trim((string) $label);
		$notation = trim((string) $notation);

		if ($label === '') {
			$built = $notation;
		} elseif ($label === $notation || strpos($label, $notation.' ') === 0) {
			$built = $label;
		} else {
			$built = $notation.' '.$label;
		}

		return mb_substr($built, 0, self::LABEL_MAX_LENGTH);
	}

	/**
	 * Persist one salary group (all rows sharing the same notation).
	 *
	 * Creates a single salary (ref = notation, amount = total CHF, label = notation + imported
	 * label), then for each line of the group a bank transaction + a payment_salary + the bank
	 * links. The PDF, if any, is moved once.
	 *
	 * @param string $notation      Salary notation shared by every row (e.g. "2026-05-5")
	 * @param array  $rows          Enriched rows of the group from SalaryImportUserLookup
	 * @param array  $knownExisting Result of findExistingSalaryRefs() for the whole batch, to avoid
	 *                              one lookup per group; null to let this method run its own
	 * @return array Result with 'salaryId', 'salaryRef', 'notation' and 'payments', or empty on error
	 */
	public function persistGroup($notation, $rows, $knownExisting = null)
	{
		global $langs;

		$notation = (string) $notation;

		$result = array();
		$this->errors = array();

		// Initialize counters if needed
		if (!$this->countersInitialized) {
			if ($this->initCounters() < 0) {
				return $result;
			}
		}

		// persistAll works on data POSTed from the confirmation form, which never goes back through
		// the validator, so the width of llx_salary.ref is enforced again here. Letting an oversized
		// notation through would have the database silently truncate the ref under a non-strict SQL
		// mode, while the duplicate check above ran on the untruncated value.
		if (mb_strlen($notation) > self::REF_MAX_LENGTH) {
			$this->errors[] = $langs->trans('ErrorSalaryRefTooLong', $notation, self::REF_MAX_LENGTH);
			return $result;
		}

		// The notation becomes the salary ref, so it must not already exist. Checked here (and not
		// only at preview time) because the confirmation form can be replayed after a first import.
		// persistAll passes the whole batch it already looked up, so this costs no extra query; a
		// direct caller gets its own lookup.
		if ($knownExisting === null) {
			$knownExisting = $this->findExistingSalaryRefs(array($notation));
			if ($knownExisting === null) {
				return $result;
			}
		}
		$status = $this->statusForNotation($knownExisting, $notation);
		if ($status !== '') {
			$this->errors[] = ($status === self::STATUS_CONFLICT)
				? $langs->trans('ErrorSalaryRefConflict', $notation)
				: $langs->trans('ErrorSalaryAlreadyImported', $notation);
			return $result;
		}

		// All inserts of a group (salary + N bank/payment/url rows) are wrapped in a single
		// transaction so a mid-loop failure never leaves a salary half-persisted.
		$this->db->begin();

		// Sort the group by payment reference so the salary-level fields taken from the first row
		// (account, payment type) do not depend on the XLSX row order. Dates are already validated
		// identical across the group by SalaryImportValidator::validateGroups().
		usort($rows, function ($a, $b) {
			$refA = isset($a['payment_ref']) ? (string) $a['payment_ref'] : '';
			$refB = isset($b['payment_ref']) ? (string) $b['payment_ref'] : '';
			return strcmp($refA, $refB);
		});
		$first = reset($rows);

		// One salary per notation: the notation is the ref and the amount is the total in company
		// currency. The label is prefixed with the notation because Dolibarr never displays
		// llx_salary.ref (Salary::fetch() overwrites it with the rowid), so the label is the only
		// place where the notation stays visible and searchable in the salary list.
		// Date/period are the same on every row; label/account/type come from the first row of the
		// deterministically sorted group (they may legitimately differ between payments).
		$salaryRef = $notation;
		$importedLabel = isset($first['label']) ? (string) $first['label'] : '';
		$salaryLabel = $this->buildSalaryLabel($notation, $importedLabel);
		$salaryId = $this->insertSalary(
			$salaryRef,
			$first['datep'],
			$first['total_salary_chf'],
			$first['typepayment'],
			$salaryLabel,
			$first['datesp'],
			$first['dateep'],
			$first['paye'],
			$first['userId'],
			$first['account']
		);

		if ($salaryId < 0) {
			$this->db->rollback();
			return $result;
		}

		$companyCurrency = $this->conf->currency;
		$payments = array();

		// One bank transaction + payment_salary + links per line of the group
		foreach ($rows as $row) {
			// amount_main_currency only when the account currency differs from the company currency.
			// An empty account currency (NULL/'' in llx_bank_account.currency_code) is unknown, so we
			// assume the company currency and leave amount_main_currency NULL.
			$accountCurrency = !empty($row['account_currency']) ? $row['account_currency'] : $companyCurrency;
			$amountMainCurrency = ($accountCurrency !== $companyCurrency) ? $row['amount_chf'] : null;

			$bankId = $this->insertBankTransaction(
				$row['datep'],
				$row['amount_nominal'],
				$row['account'],
				$row['typepaymentcode'],
				$amountMainCurrency
			);

			if ($bankId < 0) {
				$this->db->rollback();
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
				mb_substr(isset($row['label']) ? (string) $row['label'] : '', 0, self::LABEL_MAX_LENGTH),
				$row['datesp'],
				$row['dateep'],
				$row['userId'],
				$bankId,
				$salaryId,
				mb_substr(isset($row['payment_ref']) ? (string) $row['payment_ref'] : '', 0, self::PAYMENT_REF_MAX_LENGTH)
			);

			if ($paymentId < 0) {
				$this->db->rollback();
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
				$this->db->rollback();
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
				$this->db->rollback();
				return $result;
			}

			$payments[] = array(
				'paymentId' => $paymentId,
				'paymentRef' => $paymentRef,
				'num_payment' => $row['payment_ref'],
				'bankId' => $bankId
			);
		}

		// All DB inserts of the group succeeded: commit before the (non-transactional, non-blocking)
		// PDF filesystem move below.
		$this->db->commit();

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

		// Group rows by salary notation (preserve first-seen order).
		// persistAll receives user-submitted confirm data: a missing notation means malformed or
		// tampered input, so we fail fast instead of silently creating a "row_N" labelled salary.
		$groups = array();
		foreach ($enrichedRows as $index => $data) {
			if (!isset($data['salary_notation']) || $data['salary_notation'] === '') {
				$rowLabel = !empty($data['userName']) ? $data['userName'] : ('#'.($index + 1));
				$this->errors[] = $langs->trans('ErrorMissingSalaryNotation', $rowLabel);
				return $results; // empty: abort the whole import
			}
			$notation = $data['salary_notation'];
			$groups[$notation][] = $data;
		}

		// One lookup for the whole batch, so the persist loop makes a single round-trip instead of
		// one query per group. (The scan count is unchanged: matching on the unindexed label column
		// costs one scan per notation either way.)
		$existing = $this->findExistingSalaryRefs(array_keys($groups));
		if ($existing === null) {
			return $results; // empty: the duplicate guard is not optional
		}

		foreach ($groups as $notation => $rows) {
			// PHP turns a decimal-integer-like array key into an int, so a notation such as "2026"
			// would otherwise reach persistGroup (and the database) as an int.
			$result = $this->persistGroup((string) $notation, $rows, $existing);

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
