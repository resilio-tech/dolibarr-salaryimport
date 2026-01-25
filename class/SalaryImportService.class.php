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
 * \file       class/SalaryImportService.class.php
 * \ingroup    salaryimport
 * \brief      Main orchestration service for salary import
 */

require_once __DIR__.'/SalaryImportParser.class.php';
require_once __DIR__.'/SalaryImportValidator.class.php';
require_once __DIR__.'/SalaryImportUserLookup.class.php';
require_once __DIR__.'/SalaryImportPdfMatcher.class.php';
require_once __DIR__.'/SalaryImportPersister.class.php';

/**
 * Class SalaryImportService
 *
 * Main orchestration service that coordinates all salary import components
 */
class SalaryImportService
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
	 * @var SalaryImportParser Parser instance
	 */
	protected $parser;

	/**
	 * @var SalaryImportValidator Validator instance
	 */
	protected $validator;

	/**
	 * @var SalaryImportUserLookup User lookup instance
	 */
	protected $userLookup;

	/**
	 * @var SalaryImportPdfMatcher PDF matcher instance
	 */
	protected $pdfMatcher;

	/**
	 * @var SalaryImportPersister Persister instance
	 */
	protected $persister;

	/**
	 * @var array Error messages
	 */
	public $errors = array();

	/**
	 * @var string Working directory
	 */
	protected $workDir;

	/**
	 * @var array Parsed and validated data ready for preview
	 */
	protected $previewData = array();

	/**
	 * @var array PDF files extracted from ZIP
	 */
	protected $pdfs = array();

	/**
	 * @var string Name of extracted ZIP folder (for cleanup)
	 */
	protected $extractedFolderName;

	/**
	 * @var string Name of uploaded XLSX file (for cleanup)
	 */
	protected $uploadedXlsxName;

	/**
	 * @var string Name of uploaded ZIP file (for cleanup)
	 */
	protected $uploadedZipName;

	/**
	 * Constructor with dependency injection
	 *
	 * @param DoliDB                      $db         Database handler
	 * @param User                        $user       Current user
	 * @param SalaryImportParser|null     $parser     Optional parser instance (for mocking)
	 * @param SalaryImportValidator|null  $validator  Optional validator instance (for mocking)
	 * @param SalaryImportUserLookup|null $userLookup Optional user lookup instance (for mocking)
	 * @param SalaryImportPdfMatcher|null $pdfMatcher Optional PDF matcher instance (for mocking)
	 * @param SalaryImportPersister|null  $persister  Optional persister instance (for mocking)
	 */
	public function __construct(
		$db,
		$user,
		$parser = null,
		$validator = null,
		$userLookup = null,
		$pdfMatcher = null,
		$persister = null
	) {
		$this->db = $db;
		$this->user = $user;
		$this->workDir = DOL_DATA_ROOT.'/salaryimport';

		// Create work directory if needed
		if (!is_dir($this->workDir)) {
			dol_mkdir($this->workDir);
		}

		// Initialize components with dependency injection or defaults
		$this->parser = $parser !== null ? $parser : new SalaryImportParser();
		$this->validator = $validator !== null ? $validator : new SalaryImportValidator();
		$this->userLookup = $userLookup !== null ? $userLookup : new SalaryImportUserLookup($db);
		$this->pdfMatcher = $pdfMatcher !== null ? $pdfMatcher : new SalaryImportPdfMatcher($this->workDir);
		$this->persister = $persister !== null ? $persister : new SalaryImportPersister($db, $user);
	}

	/**
	 * Handle uploaded XLSX file
	 *
	 * @param array $fileData $_FILES array entry for the XLSX file
	 * @return int 1 on success, <0 on error
	 */
	public function handleXlsxUpload($fileData)
	{
		$this->errors = array();

		if (empty($fileData) || empty($fileData['name'])) {
			$this->errors[] = 'Aucun fichier XLSX fourni';
			return -1;
		}

		if ($fileData['error'] != 0) {
			$this->errors[] = 'Erreur lors de l\'envoi du fichier de salaire';
			return -2;
		}

		$filename = $fileData['name'];
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		if ($ext !== 'xlsx') {
			$this->errors[] = 'Le fichier de salaire doit être au format xlsx';
			return -2;
		}

		$destPath = $this->workDir.'/'.$filename;

		if (!dol_move_uploaded_file($fileData['tmp_name'], $destPath, 1, 0, 0)) {
			$this->errors[] = 'Erreur lors de l\'envoi du fichier de salaire';
			return -3;
		}

		$this->uploadedXlsxName = $filename;
		return 1;
	}

	/**
	 * Handle uploaded ZIP file
	 *
	 * @param array $fileData $_FILES array entry for the ZIP file
	 * @return int 1 on success, <0 on error, 0 if no ZIP provided
	 */
	public function handleZipUpload($fileData)
	{
		// No ZIP file provided is not an error
		if (!$fileData || $fileData['size'] == 0) {
			return 0;
		}

		if ($fileData['error'] != 0) {
			$this->errors[] = 'Erreur lors de l\'envoi du fichier zip de PDF';
			return -1;
		}

		$filename = $fileData['name'];
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		if ($ext !== 'zip') {
			$this->errors[] = 'Le fichier de PDF doit être au format zip';
			return -2;
		}

		$destPath = $this->workDir.'/'.$filename;

		if (!dol_move_uploaded_file($fileData['tmp_name'], $destPath, 1, 0, 0)) {
			$this->errors[] = 'Erreur lors de l\'envoi du fichier zip de PDF';
			return -3;
		}

		$this->uploadedZipName = $filename;

		// Extract ZIP and get PDFs
		$this->extractedFolderName = pathinfo($filename, PATHINFO_FILENAME);
		$this->pdfs = $this->pdfMatcher->extractFromZip($destPath, $this->extractedFolderName);

		if (!empty($this->pdfMatcher->errors)) {
			$this->errors = array_merge($this->errors, $this->pdfMatcher->errors);
			return -4;
		}

		return 1;
	}

	/**
	 * Process uploaded files and prepare preview data
	 *
	 * @return int 1 on success, <0 on error
	 */
	public function processForPreview()
	{
		$this->errors = array();
		$this->previewData = array();

		if (empty($this->uploadedXlsxName)) {
			$this->errors[] = 'No XLSX file uploaded';
			return -1;
		}

		$xlsxPath = $this->workDir.'/'.$this->uploadedXlsxName;

		// Parse XLSX
		$parseResult = $this->parser->parseFile($xlsxPath);
		if ($parseResult < 0) {
			$this->errors = array_merge($this->errors, $this->parser->errors);
			return -2;
		}

		// Validate data
		$validatedRows = $this->validator->validateAll($this->parser->getLines());

		if (!$this->validator->isValid()) {
			$this->errors = array_merge($this->errors, $this->validator->errors);
			return -3;
		}

		// Enrich with database lookups
		$enrichedRows = $this->userLookup->enrichAll($validatedRows);

		if (!$this->userLookup->isValid()) {
			$this->errors = array_merge($this->errors, $this->userLookup->errors);
			return -4;
		}

		// Match PDFs to users
		foreach ($enrichedRows as $index => &$row) {
			$pdfPath = $this->pdfMatcher->findPdfForUser(
				$row['firstname'],
				$row['lastname'],
				$this->pdfs
			);
			$row['pdf'] = $pdfPath ? $pdfPath : '';

			if (!empty($pdfPath)) {
				$row['pdf_display'] = basename($pdfPath);
			} else {
				$row['pdf_display'] = 'Aucun';
			}
		}

		$this->previewData = $enrichedRows;
		return 1;
	}

	/**
	 * Get preview data for display
	 *
	 * @return array Array of preview data rows
	 */
	public function getPreviewData()
	{
		return $this->previewData;
	}

	/**
	 * Get headers for preview table
	 *
	 * @return array Array of header names
	 */
	public function getPreviewHeaders()
	{
		return $this->parser->getHeaders();
	}

	/**
	 * Get original parsed lines (for display purposes)
	 *
	 * @return array Array of original lines
	 */
	public function getParsedLines()
	{
		return $this->parser->getLines();
	}

	/**
	 * Execute the import (persist all data)
	 *
	 * @param array $data Data to import (usually from session/hidden fields)
	 * @return int Number of successfully imported rows, <0 on error
	 */
	public function executeImport($data)
	{
		$this->errors = array();

		if (empty($data)) {
			$this->errors[] = 'No data to import';
			return -1;
		}

		$results = $this->persister->persistAll($data);

		if (!$this->persister->isValid()) {
			$this->errors = array_merge($this->errors, $this->persister->errors);
		}

		return count($results);
	}

	/**
	 * Clean up uploaded and extracted files
	 *
	 * @return int 1 on success, <0 on error
	 */
	public function cleanup()
	{
		$result = 1;

		// Clean up XLSX
		if (!empty($this->uploadedXlsxName)) {
			$xlsxPath = $this->workDir.'/'.$this->uploadedXlsxName;
			if (file_exists($xlsxPath)) {
				if (!unlink($xlsxPath)) {
					$this->errors[] = 'Failed to delete XLSX file';
					$result = -1;
				}
			}
		}

		// Clean up ZIP and extracted folder
		if (!empty($this->extractedFolderName)) {
			$cleanupResult = $this->pdfMatcher->cleanup(
				$this->extractedFolderName,
				$this->uploadedZipName
			);
			if ($cleanupResult < 0) {
				$this->errors = array_merge($this->errors, $this->pdfMatcher->errors);
				$result = -2;
			}
		}

		return $result;
	}

	/**
	 * Serialize preview data for form submission
	 *
	 * @return array Serializable data array
	 */
	public function serializeForForm()
	{
		$serialized = array();

		foreach ($this->previewData as $index => $row) {
			$serialized[$index] = array(
				'userId' => $row['userId'],
				'userName' => $row['userName'],
				'datep' => $row['datep'],
				'amount' => $row['amount'],
				'typepayment' => $row['typepayment'],
				'typepaymentcode' => $row['typepaymentcode'],
				'label' => $row['label'],
				'datesp' => $row['datesp'],
				'dateep' => $row['dateep'],
				'paye' => $row['paye'],
				'account' => $row['account'],
				'pdf' => $row['pdf']
			);
		}

		return $serialized;
	}

	/**
	 * Get parser instance (for testing)
	 *
	 * @return SalaryImportParser
	 */
	public function getParser()
	{
		return $this->parser;
	}

	/**
	 * Get validator instance (for testing)
	 *
	 * @return SalaryImportValidator
	 */
	public function getValidator()
	{
		return $this->validator;
	}

	/**
	 * Get user lookup instance (for testing)
	 *
	 * @return SalaryImportUserLookup
	 */
	public function getUserLookup()
	{
		return $this->userLookup;
	}

	/**
	 * Get PDF matcher instance (for testing)
	 *
	 * @return SalaryImportPdfMatcher
	 */
	public function getPdfMatcher()
	{
		return $this->pdfMatcher;
	}

	/**
	 * Get persister instance (for testing)
	 *
	 * @return SalaryImportPersister
	 */
	public function getPersister()
	{
		return $this->persister;
	}

	/**
	 * Get working directory
	 *
	 * @return string Working directory path
	 */
	public function getWorkDir()
	{
		return $this->workDir;
	}

	/**
	 * Set preview data directly (for restoring from session)
	 *
	 * @param array $data Preview data array
	 * @return void
	 */
	public function setPreviewData($data)
	{
		$this->previewData = $data;
	}
}
