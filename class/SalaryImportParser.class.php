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
 * \file       class/SalaryImportParser.class.php
 * \ingroup    salaryimport
 * \brief      Class for parsing XLSX files for salary import
 */

// IMPORTANT: Load our patched File class BEFORE PhpSpreadsheet autoloader
// This fixes open_basedir issues with PhpSpreadsheet 1.12.0
// The patch prevents file_exists() calls on internal ZIP paths like "/xl/worksheets/sheet1.xml"
require_once __DIR__.'/../lib/PhpSpreadsheetFileFix.php';

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\File;

/**
 * Class SalaryImportParser
 *
 * Handles parsing of XLSX files containing salary data
 */
class SalaryImportParser
{
	/**
	 * @var array Error messages
	 */
	public $errors = array();

	/**
	 * @var array Headers from the first row
	 */
	protected $headers = array();

	/**
	 * @var array Parsed data rows
	 */
	protected $lines = array();

	/**
	 * @var string Path to the parsed file
	 */
	protected $filePath;

	/**
	 * @var array Column name mapping (French and English to internal keys)
	 */
	protected $columnMapping = array(
		// French
		'prénom' => 'Prénom',
		'nom' => 'Nom',
		'date de paiement' => 'Date de paiement',
		'montant' => 'Montant',
		'libellé' => 'Libellé',
		'date de début' => 'Date de début',
		'date de fin' => 'Date de fin',
		'type de paiement' => 'Type de paiement',
		'payé' => 'Payé',
		'compte bancaire' => 'Compte bancaire',
		// English
		'first name' => 'Prénom',
		'firstname' => 'Prénom',
		'last name' => 'Nom',
		'lastname' => 'Nom',
		'payment date' => 'Date de paiement',
		'amount' => 'Montant',
		'label' => 'Libellé',
		'start date' => 'Date de début',
		'end date' => 'Date de fin',
		'payment type' => 'Type de paiement',
		'paid' => 'Payé',
		'bank account' => 'Compte bancaire',
	);

	/**
	 * Constructor
	 */
	public function __construct()
	{
	}

	/**
	 * Normalize a column header to internal key
	 *
	 * @param string $header Raw header from Excel
	 * @return string Normalized internal key or original header if not found
	 */
	protected function normalizeHeader($header)
	{
		if (!is_string($header)) {
			return $header;
		}

		$normalized = strtolower(trim($header));

		if (isset($this->columnMapping[$normalized])) {
			return $this->columnMapping[$normalized];
		}

		// Return original if no mapping found
		return $header;
	}

	/**
	 * Fix double UTF-8 encoding issues
	 *
	 * Some Excel files may have double-encoded UTF-8 characters
	 * (e.g., "Ã©" instead of "é")
	 *
	 * @param mixed $value Value to fix
	 * @return mixed Fixed value or original if not a string
	 */
	public function fixEncoding($value)
	{
		// Convert RichText objects to string
		if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
			$value = $value->getPlainText();
		}

		if (!is_string($value)) {
			return $value;
		}

		// Detect double UTF-8 encoding (e.g. "Ã©" instead of "é")
		$fixed = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $value);
		if ($fixed !== false && $fixed !== $value) {
			// Check if reverse conversion gives valid UTF-8
			$test = @iconv('ISO-8859-1', 'UTF-8', $fixed);
			if ($test !== false && mb_check_encoding($test, 'UTF-8')) {
				return $test;
			}
		}
		return $value;
	}

	/**
	 * Parse an XLSX file
	 *
	 * @param string $filePath Path to the XLSX file
	 * @return int 1 on success, <0 on error
	 */
	public function parseFile($filePath)
	{
		$this->errors = array();
		$this->headers = array();
		$this->lines = array();
		$this->filePath = $filePath;

		// Check file exists and is readable
		if (!file_exists($filePath)) {
			$this->errors[] = 'File not found: '.$filePath;
			return -1;
		}

		if (!is_readable($filePath)) {
			$this->errors[] = 'File is not readable: '.$filePath;
			return -2;
		}

		// Check file extension
		$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
		if ($ext !== 'xlsx') {
			$this->errors[] = 'File must be in XLSX format, got: '.$ext;
			return -3;
		}

		try {
			// Ensure we use upload temp directory for any temp files PhpSpreadsheet creates
			File::setUseUploadTempDirectory(true);

			$reader = new Xlsx();

			if (!$reader->canRead($filePath)) {
				$this->errors[] = 'Cannot read XLSX file: '.$filePath;
				return -4;
			}

			$spreadsheet = $reader->load($filePath);
			$sheet = $spreadsheet->getActiveSheet();

			$rowCount = $sheet->getHighestRow();
			$highestColumn = $sheet->getHighestColumn();
			$countColumns = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

			// Extract headers from first row (skip columns with empty headers)
			for ($col = 1; $col <= $countColumns; $col++) {
				$header = $this->fixEncoding($sheet->getCellByColumnAndRow($col, 1)->getValue());
				if ($header !== null && $header !== '') {
					// Normalize header to internal key (supports French and English)
					$this->headers[$col] = $this->normalizeHeader($header);
				}
			}

			// Extract data rows (only for columns with valid headers)
			for ($row = 2; $row <= $rowCount; $row++) {
				$line = array();
				$hasData = false;

				foreach ($this->headers as $col => $header) {
					$value = $this->fixEncoding($sheet->getCellByColumnAndRow($col, $row)->getValue());
					$line[$header] = $value;
					if ($value !== null && $value !== '') {
						$hasData = true;
					}
				}

				// Only add non-empty rows
				if ($hasData) {
					$this->lines[] = $line;
				}
			}

			if (count($this->lines) === 0) {
				$this->errors[] = 'No data rows found in file';
				return -5;
			}

			return 1;
		} catch (Exception $e) {
			$this->errors[] = 'Error parsing XLSX file: '.$e->getMessage();
			return -6;
		}
	}

	/**
	 * Get the parsed headers
	 *
	 * @return array Headers array (1-indexed)
	 */
	public function getHeaders()
	{
		return $this->headers;
	}

	/**
	 * Get the parsed data lines
	 *
	 * @return array Array of associative arrays (header => value)
	 */
	public function getLines()
	{
		return $this->lines;
	}

	/**
	 * Get the number of data rows parsed
	 *
	 * @return int Number of rows
	 */
	public function getRowCount()
	{
		return count($this->lines);
	}

	/**
	 * Get a specific line by index
	 *
	 * @param int $index 0-based index
	 * @return array|null Line data or null if not found
	 */
	public function getLine($index)
	{
		return isset($this->lines[$index]) ? $this->lines[$index] : null;
	}

	/**
	 * Get a value from a specific line and column
	 *
	 * @param int    $rowIndex 0-based row index
	 * @param string $column   Column header name
	 * @return mixed Value or null if not found
	 */
	public function getValue($rowIndex, $column)
	{
		if (!isset($this->lines[$rowIndex])) {
			return null;
		}
		return isset($this->lines[$rowIndex][$column]) ? $this->lines[$rowIndex][$column] : null;
	}

	/**
	 * Check if a column exists in the headers
	 *
	 * @param string $column Column header name
	 * @return bool True if column exists
	 */
	public function hasColumn($column)
	{
		return in_array($column, $this->headers);
	}

	/**
	 * Get the file path that was parsed
	 *
	 * @return string|null File path or null if not yet parsed
	 */
	public function getFilePath()
	{
		return $this->filePath;
	}
}
