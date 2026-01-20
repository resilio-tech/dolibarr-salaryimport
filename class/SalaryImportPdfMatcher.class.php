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
 * \file       class/SalaryImportPdfMatcher.class.php
 * \ingroup    salaryimport
 * \brief      Class for matching PDF files to users based on filename patterns
 */

/**
 * Class SalaryImportPdfMatcher
 *
 * Handles extraction and matching of PDF files from ZIP archives to users
 */
class SalaryImportPdfMatcher
{
	/**
	 * @var array Error messages
	 */
	public $errors = array();

	/**
	 * @var string Working directory for extracted files
	 */
	protected $workDir;

	/**
	 * Constructor
	 *
	 * @param string $workDir Working directory for file operations (default: DOL_DATA_ROOT/salaryimport)
	 */
	public function __construct($workDir = null)
	{
		if ($workDir === null) {
			if (defined('DOL_DATA_ROOT')) {
				$workDir = DOL_DATA_ROOT.'/salaryimport';
			} else {
				$workDir = sys_get_temp_dir().'/salaryimport';
			}
		}
		$this->workDir = $workDir;
	}

	/**
	 * Normalize a string for comparison (remove accents, lowercase, replace non-alphanumeric with dash)
	 *
	 * @param string $string String to normalize
	 * @return string Normalized string
	 */
	public function normalizeString($string)
	{
		return strtolower(
			trim(
				preg_replace(
					'~[^0-9a-z]+~i',
					'-',
					preg_replace(
						'~&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i',
						'$1',
						htmlentities($string, ENT_QUOTES, 'UTF-8')
					)
				),
				' -'
			)
		);
	}

	/**
	 * Check if a link segment matches a user's firstname or lastname
	 *
	 * @param string $link      Link segment from PDF filename (e.g., "jean" from "jean_dupont.pdf")
	 * @param string $firstname User's firstname
	 * @param string $lastname  User's lastname
	 * @return bool True if link matches either firstname or lastname
	 */
	public function matchesUserName($link, $firstname, $lastname)
	{
		$normalizedLink = $this->normalizeString($link);
		$normalizedFirstname = $this->normalizeString($firstname);
		$normalizedLastname = $this->normalizeString($lastname);

		return ($normalizedLink === $normalizedFirstname || $normalizedLink === $normalizedLastname);
	}

	/**
	 * Check if a link segment matches a user's firstname
	 *
	 * @param string $link      Link segment from PDF filename
	 * @param string $firstname User's firstname
	 * @return bool True if link matches firstname
	 */
	public function matchesFirstname($link, $firstname)
	{
		return $this->normalizeString($link) === $this->normalizeString($firstname);
	}

	/**
	 * Check if a link segment matches a user's lastname
	 *
	 * @param string $link     Link segment from PDF filename
	 * @param string $lastname User's lastname
	 * @return bool True if link matches lastname
	 */
	public function matchesLastname($link, $lastname)
	{
		return $this->normalizeString($link) === $this->normalizeString($lastname);
	}

	/**
	 * Extract PDF files from a ZIP archive
	 *
	 * @param string $zipPath       Path to the ZIP file
	 * @param string $extractFolder Folder name for extraction (without path)
	 * @return array Array of PDF info: ['filename' => ..., 'path' => ..., 'links' => [...]] or empty on error
	 */
	public function extractFromZip($zipPath, $extractFolder = null)
	{
		$pdfs = array();
		$this->errors = array();

		if (!file_exists($zipPath)) {
			$this->errors[] = 'ZIP file not found: '.$zipPath;
			return $pdfs;
		}

		if ($extractFolder === null) {
			$extractFolder = pathinfo($zipPath, PATHINFO_FILENAME);
		}

		$extractPath = $this->workDir.'/'.$extractFolder;

		$zip = new ZipArchive();
		if ($zip->open($zipPath) !== true) {
			$this->errors[] = 'Failed to open ZIP archive: '.$zipPath;
			return $pdfs;
		}

		if (!$zip->extractTo($extractPath)) {
			$this->errors[] = 'Failed to extract ZIP archive to: '.$extractPath;
			$zip->close();
			return $pdfs;
		}
		$zip->close();

		// Scan extracted directory for PDFs
		$pdfs = $this->scanDirectoryForPdfs($extractPath);

		return $pdfs;
	}

	/**
	 * Scan a directory for PDF files and extract link information from filenames
	 *
	 * @param string $directory Directory to scan
	 * @return array Array of PDF info
	 */
	public function scanDirectoryForPdfs($directory)
	{
		$pdfs = array();

		if (!is_dir($directory)) {
			return $pdfs;
		}

		$files = scandir($directory);
		foreach ($files as $file) {
			if ($file === '.' || $file === '..') {
				continue;
			}

			$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
			if ($ext === 'pdf') {
				$nameWithoutExt = strtolower(pathinfo($file, PATHINFO_FILENAME));
				$links = explode('_', $nameWithoutExt);
				$pdfs[] = array(
					'filename' => $file,
					'path' => $directory.'/'.$file,
					'links' => $links
				);
			}
		}

		return $pdfs;
	}

	/**
	 * Generate all possible combinations of consecutive segments
	 *
	 * For ['jean', 'pierre', 'dupont'], generates:
	 * - ['jean'] (indices 0)
	 * - ['pierre'] (indices 1)
	 * - ['dupont'] (indices 2)
	 * - ['jean', 'pierre'] (indices 0,1)
	 * - ['pierre', 'dupont'] (indices 1,2)
	 * - ['jean', 'pierre', 'dupont'] (indices 0,1,2)
	 *
	 * @param array $links Array of filename segments
	 * @return array Array of combinations with 'value' (joined string) and 'indices'
	 */
	public function generateConsecutiveCombinations($links)
	{
		$combinations = array();
		$count = count($links);

		for ($start = 0; $start < $count; $start++) {
			for ($len = 1; $len <= $count - $start; $len++) {
				$segments = array_slice($links, $start, $len);
				$indices = range($start, $start + $len - 1);
				$combinations[] = array(
					'value' => implode('-', $segments),
					'indices' => $indices
				);
			}
		}

		return $combinations;
	}

	/**
	 * Check if two arrays of indices overlap
	 *
	 * @param array $indices1 First array of indices
	 * @param array $indices2 Second array of indices
	 * @return bool True if indices overlap
	 */
	public function indicesOverlap($indices1, $indices2)
	{
		return count(array_intersect($indices1, $indices2)) > 0;
	}

	/**
	 * Find a matching PDF for a given user from a list of PDFs
	 *
	 * The PDF filename must contain both the firstname AND lastname to match.
	 * Supports compound names (Jean-Pierre) by joining consecutive segments.
	 * Ensures firstname and lastname match different segments (handles "Martin Martin" case).
	 *
	 * Examples:
	 * - "jean_dupont.pdf" matches "Jean Dupont"
	 * - "jean_pierre_dupont.pdf" matches "Jean-Pierre Dupont"
	 * - "martin.pdf" does NOT match "Martin Martin" (needs 2 distinct segments)
	 * - "martin_martin.pdf" matches "Martin Martin"
	 *
	 * @param string $firstname User's firstname
	 * @param string $lastname  User's lastname
	 * @param array  $pdfs      Array of PDF info from extractFromZip() or scanDirectoryForPdfs()
	 * @return string|null Path to matching PDF or null if not found
	 */
	public function findPdfForUser($firstname, $lastname, $pdfs)
	{
		$normalizedFirstname = $this->normalizeString($firstname);
		$normalizedLastname = $this->normalizeString($lastname);

		foreach ($pdfs as $pdf) {
			// Generate all consecutive segment combinations
			$combinations = $this->generateConsecutiveCombinations($pdf['links']);

			// Find all combinations that match firstname or lastname
			$firstnameMatches = array();
			$lastnameMatches = array();

			foreach ($combinations as $combo) {
				$normalizedCombo = $this->normalizeString($combo['value']);

				if ($normalizedCombo === $normalizedFirstname) {
					$firstnameMatches[] = $combo['indices'];
				}
				if ($normalizedCombo === $normalizedLastname) {
					$lastnameMatches[] = $combo['indices'];
				}
			}

			// Check if we can find non-overlapping matches for both firstname and lastname
			foreach ($firstnameMatches as $fnIndices) {
				foreach ($lastnameMatches as $lnIndices) {
					if (!$this->indicesOverlap($fnIndices, $lnIndices)) {
						return $pdf['path'];
					}
				}
			}
		}

		return null;
	}

	/**
	 * Clean up extracted files and directories
	 *
	 * @param string $folderName Folder name to clean up (relative to workDir)
	 * @param string $zipName    Optional ZIP file name to clean up (relative to workDir)
	 * @return int 1 on success, <0 on error
	 */
	public function cleanup($folderName, $zipName = null)
	{
		$result = 1;

		// Remove ZIP file if specified
		if ($zipName !== null) {
			$zipPath = $this->workDir.'/'.$zipName;
			if (file_exists($zipPath)) {
				if (!unlink($zipPath)) {
					$this->errors[] = 'Failed to delete ZIP file: '.$zipPath;
					$result = -1;
				}
			}
		}

		// Remove extracted folder
		$folderPath = $this->workDir.'/'.$folderName;
		if (is_dir($folderPath)) {
			$files = scandir($folderPath);
			foreach ($files as $file) {
				if ($file !== '.' && $file !== '..') {
					$filePath = $folderPath.'/'.$file;
					if (is_file($filePath)) {
						if (!unlink($filePath)) {
							$this->errors[] = 'Failed to delete file: '.$filePath;
							$result = -1;
						}
					}
				}
			}
			if (!rmdir($folderPath)) {
				$this->errors[] = 'Failed to delete folder: '.$folderPath;
				$result = -1;
			}
		}

		return $result;
	}

	/**
	 * Get the working directory
	 *
	 * @return string Working directory path
	 */
	public function getWorkDir()
	{
		return $this->workDir;
	}
}
