<?php
/* Copyright (C) 2024 SuperAdmin
 *
 * Fix for PhpSpreadsheet File::realpath() open_basedir issue
 *
 * PhpSpreadsheet 1.12.0 (bundled with Dolibarr) calls file_exists() on internal
 * ZIP paths like "/xl/worksheets/sheet1.xml" which triggers open_basedir errors.
 * See: https://github.com/PHPOffice/PhpSpreadsheet/issues/2568
 *
 * This fix patches the File class to skip file_exists() for paths that
 * look like internal ZIP archive paths.
 *
 * COMPATIBILITY:
 * - This file MUST be loaded BEFORE PhpSpreadsheet autoloader
 * - If PhpSpreadsheet is updated to a version with the fix (>= 1.15), this patch
 *   will still be loaded first but should remain compatible as it implements
 *   the same interface
 * - If a future version adds new methods to File class, this patch may need updating
 *
 * To check if patch is still needed, look for 'setTempDir' method in:
 * DOL_DOCUMENT_ROOT/includes/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Shared/File.php
 * If setTempDir exists, the version likely has the fix already.
 */

namespace PhpOffice\PhpSpreadsheet\Shared;

/**
 * Patched File class that fixes open_basedir issues with ZIP internal paths
 *
 * This class replaces PhpOffice\PhpSpreadsheet\Shared\File when loaded before
 * the PhpSpreadsheet autoloader.
 */
class File
{
	/**
	 * Use Temp or File Upload Temp for temporary files.
	 *
	 * @var bool
	 */
	protected static $useUploadTempDirectory = false;

	/**
	 * Custom temp directory (for newer PhpSpreadsheet compatibility)
	 *
	 * @var string|null
	 */
	protected static $tempDir = null;

	/**
	 * Set the flag indicating whether the File Upload Temp directory should be used for temporary files.
	 *
	 * @param bool $useUploadTempDir Use File Upload Temporary directory (true or false)
	 */
	public static function setUseUploadTempDirectory($useUploadTempDir)
	{
		self::$useUploadTempDirectory = (bool) $useUploadTempDir;
	}

	/**
	 * Get the flag indicating whether the File Upload Temp directory should be used for temporary files.
	 *
	 * @return bool Use File Upload Temporary directory (true or false)
	 */
	public static function getUseUploadTempDirectory()
	{
		return self::$useUploadTempDirectory;
	}

	/**
	 * Set the temp directory (compatibility with newer PhpSpreadsheet versions)
	 *
	 * @param string $tempDir Path to temp directory
	 */
	public static function setTempDir($tempDir)
	{
		self::$tempDir = $tempDir;
	}

	/**
	 * Get the temp directory (compatibility with newer PhpSpreadsheet versions)
	 *
	 * @return string|null
	 */
	public static function getTempDir()
	{
		return self::$tempDir;
	}

	/**
	 * Verify if a file exists.
	 *
	 * @param string $pFilename Filename
	 *
	 * @return bool
	 */
	public static function fileExists($pFilename)
	{
		if (strtolower(substr($pFilename, 0, 3)) == 'zip') {
			$zipFile = substr($pFilename, 6, strpos($pFilename, '#') - 6);
			$archiveFile = substr($pFilename, strpos($pFilename, '#') + 1);

			$zip = new \ZipArchive();
			if ($zip->open($zipFile) === true) {
				$returnValue = ($zip->getFromName($archiveFile) !== false);
				$zip->close();

				return $returnValue;
			}

			return false;
		}

		return file_exists($pFilename);
	}

	/**
	 * Returns canonicalized absolute pathname, also for ZIP archives.
	 *
	 * PATCHED: Skip file_exists() for paths that look like internal ZIP paths
	 * to avoid open_basedir restriction errors.
	 *
	 * @param string $pFilename
	 *
	 * @return string
	 */
	public static function realpath($pFilename)
	{
		$returnValue = '';

		// FIX: Skip file_exists for paths that look like internal ZIP archive paths
		// These are paths inside XLSX files (which are ZIP archives) like:
		// - /xl/worksheets/sheet1.xml
		// - xl/styles.xml
		// - _rels/.rels
		// - docProps/core.xml
		$isInternalZipPath = (
			preg_match('#^/?xl(/|$)#i', $pFilename) ||
			preg_match('#^/?_rels(/|$)#i', $pFilename) ||
			preg_match('#^/?docProps(/|$)#i', $pFilename) ||
			preg_match('#^\[Content_Types\]\.xml$#i', $pFilename) ||
			preg_match('#^\.\./.*\.xml$#i', $pFilename)
		);

		// Try using realpath() only for real filesystem paths
		if (!$isInternalZipPath && file_exists($pFilename)) {
			$returnValue = realpath($pFilename);
		}

		// Normalize path manually if realpath didn't work
		if ($returnValue == '' || ($returnValue === null)) {
			$pathArray = explode('/', $pFilename);
			while (in_array('..', $pathArray) && $pathArray[0] != '..') {
				$iMax = count($pathArray);
				for ($i = 0; $i < $iMax; ++$i) {
					if ($pathArray[$i] == '..' && $i > 0) {
						unset($pathArray[$i], $pathArray[$i - 1]);
						// Re-index array to avoid gaps after unset
						$pathArray = array_values($pathArray);
						break;
					}
				}
			}
			$returnValue = implode('/', $pathArray);
		}

		return $returnValue;
	}

	/**
	 * Get the systems temporary directory.
	 *
	 * @return string
	 */
	public static function sysGetTempDir()
	{
		// Use custom temp dir if set (newer PhpSpreadsheet compatibility)
		if (self::$tempDir !== null && is_dir(self::$tempDir)) {
			return self::$tempDir;
		}

		if (self::$useUploadTempDirectory) {
			if (ini_get('upload_tmp_dir') !== false) {
				if ($temp = ini_get('upload_tmp_dir')) {
					if (file_exists($temp)) {
						return realpath($temp);
					}
				}
			}
		}

		return realpath(sys_get_temp_dir());
	}

	/**
	 * Assert that given path is an existing file and is readable, otherwise throw exception.
	 *
	 * @param string $filename
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function assertFile($filename)
	{
		if (!is_file($filename)) {
			throw new \InvalidArgumentException('File "' . $filename . '" does not exist.');
		}

		if (!is_readable($filename)) {
			throw new \InvalidArgumentException('Could not open "' . $filename . '" for reading.');
		}
	}
}
