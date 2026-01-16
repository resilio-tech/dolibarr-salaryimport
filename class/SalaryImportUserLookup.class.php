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
 * \file       class/SalaryImportUserLookup.class.php
 * \ingroup    salaryimport
 * \brief      Class for looking up users, payment types, and bank accounts
 */

/**
 * Class SalaryImportUserLookup
 *
 * Handles database lookups for users, payment types, and bank accounts
 */
class SalaryImportUserLookup
{
	/**
	 * @var DoliDB Database handler
	 */
	protected $db;

	/**
	 * @var array Error messages
	 */
	public $errors = array();

	/**
	 * @var array Cache for user lookups
	 */
	protected $userCache = array();

	/**
	 * @var array Cache for payment type lookups
	 */
	protected $paymentTypeCache = array();

	/**
	 * @var array Cache for bank account lookups
	 */
	protected $bankAccountCache = array();

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Find user by firstname and lastname
	 *
	 * @param string $firstname User's firstname
	 * @param string $lastname  User's lastname
	 * @return array|false Array with 'rowid' and 'name' on success, false if not found
	 */
	public function findUserByName($firstname, $lastname)
	{
		$cacheKey = strtolower($firstname.'|'.$lastname);

		// Check cache
		if (isset($this->userCache[$cacheKey])) {
			return $this->userCache[$cacheKey];
		}

		$sql = "SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."user";
		$sql .= " WHERE lastname = '".$this->db->escape($lastname)."'";
		$sql .= " AND firstname = '".$this->db->escape($firstname)."'";

		$result = $this->db->query($sql);
		if (!$result) {
			$this->errors[] = 'Database error: '.$this->db->lasterror();
			return false;
		}

		if ($this->db->num_rows($result) === 0) {
			$this->userCache[$cacheKey] = false;
			return false;
		}

		$obj = $this->db->fetch_object($result);
		$userData = array(
			'rowid' => $obj->rowid,
			'name' => $obj->firstname.' '.$obj->lastname
		);

		$this->userCache[$cacheKey] = $userData;
		return $userData;
	}

	/**
	 * Find payment type by code
	 *
	 * @param string $code Payment type code (e.g., 'VIR', 'CHQ')
	 * @return array|false Array with 'id', 'code', and 'libelle' on success, false if not found
	 */
	public function findPaymentType($code)
	{
		$cacheKey = strtoupper($code);

		// Check cache
		if (isset($this->paymentTypeCache[$cacheKey])) {
			return $this->paymentTypeCache[$cacheKey];
		}

		$sql = "SELECT id, code, libelle FROM ".MAIN_DB_PREFIX."c_paiement";
		$sql .= " WHERE code = '".$this->db->escape($code)."'";

		$result = $this->db->query($sql);
		if (!$result) {
			$this->errors[] = 'Database error: '.$this->db->lasterror();
			return false;
		}

		if ($this->db->num_rows($result) === 0) {
			$this->paymentTypeCache[$cacheKey] = false;
			return false;
		}

		$obj = $this->db->fetch_object($result);
		$paymentData = array(
			'id' => $obj->id,
			'code' => $obj->code,
			'libelle' => $obj->libelle
		);

		$this->paymentTypeCache[$cacheKey] = $paymentData;
		return $paymentData;
	}

	/**
	 * Find bank account by ref or label
	 *
	 * @param string $refOrLabel Bank account ref or label
	 * @return array|false Array with 'rowid', 'ref', and 'label' on success, false if not found
	 */
	public function findBankAccount($refOrLabel)
	{
		$cacheKey = strtolower($refOrLabel);

		// Check cache
		if (isset($this->bankAccountCache[$cacheKey])) {
			return $this->bankAccountCache[$cacheKey];
		}

		$sql = "SELECT rowid, ref, label FROM ".MAIN_DB_PREFIX."bank_account";
		$sql .= " WHERE ref = '".$this->db->escape($refOrLabel)."'";
		$sql .= " OR label = '".$this->db->escape($refOrLabel)."'";

		$result = $this->db->query($sql);
		if (!$result) {
			$this->errors[] = 'Database error: '.$this->db->lasterror();
			return false;
		}

		if ($this->db->num_rows($result) === 0) {
			$this->bankAccountCache[$cacheKey] = false;
			return false;
		}

		$obj = $this->db->fetch_object($result);
		$accountData = array(
			'rowid' => $obj->rowid,
			'ref' => $obj->ref,
			'label' => $obj->label
		);

		$this->bankAccountCache[$cacheKey] = $accountData;
		return $accountData;
	}

	/**
	 * Enrich validated row data with database lookups
	 *
	 * @param array $validatedRow Validated row data from SalaryImportValidator
	 * @param int   $rowNum       Row number for error messages (1-based)
	 * @return array Enriched data array or empty array on error
	 */
	public function enrichRowData($validatedRow, $rowNum)
	{
		$enriched = $validatedRow;
		$rowErrors = array();

		// Look up user
		if (isset($validatedRow['firstname']) && isset($validatedRow['lastname'])) {
			$user = $this->findUserByName($validatedRow['firstname'], $validatedRow['lastname']);
			if ($user === false) {
				$rowErrors[] = 'Utilisateur non trouvé à la ligne '.$rowNum;
			} else {
				$enriched['userId'] = $user['rowid'];
				$enriched['userName'] = $user['name'];
			}
		}

		// Look up payment type
		if (isset($validatedRow['typepayment_code'])) {
			$paymentType = $this->findPaymentType($validatedRow['typepayment_code']);
			if ($paymentType === false) {
				$rowErrors[] = 'Type de paiement non trouvé à la ligne '.$rowNum;
			} else {
				$enriched['typepayment'] = $paymentType['id'];
				$enriched['typepaymentcode'] = $paymentType['code'];
				$enriched['typepayment_label'] = $paymentType['libelle'];
			}
		}

		// Look up bank account
		if (isset($validatedRow['account_ref'])) {
			$account = $this->findBankAccount($validatedRow['account_ref']);
			if ($account === false) {
				$rowErrors[] = 'Compte bancaire non trouvé à la ligne '.$rowNum;
			} else {
				$enriched['account'] = $account['rowid'];
				$enriched['account_label'] = $account['label'];
			}
		}

		// Add errors to class errors
		$this->errors = array_merge($this->errors, $rowErrors);

		// Return empty array if there were errors
		if (count($rowErrors) > 0) {
			return array();
		}

		return $enriched;
	}

	/**
	 * Enrich all validated rows with database lookups
	 *
	 * @param array $validatedRows Array of validated rows from SalaryImportValidator
	 * @return array Array of enriched data rows
	 */
	public function enrichAll($validatedRows)
	{
		$this->errors = array();
		$enrichedRows = array();

		foreach ($validatedRows as $index => $row) {
			$rowNum = $index + 2; // +2 because row 1 is headers and arrays are 0-indexed
			$enriched = $this->enrichRowData($row, $rowNum);
			if (!empty($enriched)) {
				$enrichedRows[$index] = $enriched;
			}
		}

		return $enrichedRows;
	}

	/**
	 * Clear all caches
	 *
	 * @return void
	 */
	public function clearCache()
	{
		$this->userCache = array();
		$this->paymentTypeCache = array();
		$this->bankAccountCache = array();
	}

	/**
	 * Check if lookups passed without errors
	 *
	 * @return bool True if no errors
	 */
	public function isValid()
	{
		return empty($this->errors);
	}
}
