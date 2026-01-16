<?php
/**
 * Script to generate test fixtures for the salaryimport PHPUnit tests
 * Usage: php generate_fixtures.php
 *
 * This script creates:
 * - valid_import.xlsx: A valid XLSX file with test salary data
 * - test_pdfs.zip: A ZIP file containing test PDF files
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res && file_exists("../../../../../main.inc.php")) {
	$res = @include "../../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails\n");
}

require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Convert date string to Excel serial date
 *
 * @param string $dateStr Date in Y-m-d format
 * @return float Excel serial date
 */
function dateToExcel($dateStr) {
	$timestamp = strtotime($dateStr);
	return ($timestamp / 86400) + 25569;
}

$fixturesDir = __DIR__;

// ============================================
// Generate valid_import.xlsx
// ============================================

echo "Generating valid_import.xlsx...\n";

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Headers
$headers = [
	'Prénom',
	'Nom',
	'Date de paiement',
	'Montant',
	'Type de paiement',
	'Libellé',
	'Date de début',
	'Date de fin',
	'Payé',
	'Compte bancaire'
];

$col = 1;
foreach ($headers as $header) {
	$sheet->setCellValueByColumnAndRow($col, 1, $header);
	$col++;
}

// Test data
$testData = [
	[
		'Jean',
		'Dupont',
		dateToExcel('2026-01-31'),
		2500.00,
		'VIR',
		'Salaire Janvier 2024',
		dateToExcel('2026-01-01'),
		dateToExcel('2026-01-31'),
		'oui',
		'POSTE_CH'
	],
	[
		'Marie',
		'Martin',
		dateToExcel('2026-01-31'),
		2800.50,
		'VIR',
		'Salaire Janvier 2024',
		dateToExcel('2026-01-01'),
		dateToExcel('2026-01-31'),
		'oui',
		'POSTE_CH'
	],
	[
		'Pierre',
		'Durand',
		dateToExcel('2026-01-31'),
		2200.00,
		'VIR',
		'Salaire Janvier 2024',
		dateToExcel('2026-01-01'),
		dateToExcel('2026-01-31'),
		'oui',
		'POSTE_CH'
	],
	[
		'Sophie',
		'Lefebvre',
		dateToExcel('2026-02-29'),
		3100.75,
		'VIR',
		'Salaire Février 2024',
		dateToExcel('2026-02-01'),
		dateToExcel('2026-02-29'),
		'non',
		'POSTE_CH'
	],
];

$row = 2;
foreach ($testData as $data) {
	$col = 1;
	foreach ($data as $value) {
		$sheet->setCellValueByColumnAndRow($col, $row, $value);
		$col++;
	}
	$row++;
}

// Auto-size columns
foreach (range('A', 'J') as $columnID) {
	$sheet->getColumnDimension($columnID)->setAutoSize(true);
}

// Save
$xlsxPath = $fixturesDir.'/valid_import.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($xlsxPath);

echo "Created: $xlsxPath\n";

// ============================================
// Generate test_pdfs.zip
// ============================================

echo "Generating test_pdfs.zip...\n";

$zipPath = $fixturesDir.'/test_pdfs.zip';
$tempDir = sys_get_temp_dir().'/salaryimport_fixtures_'.uniqid();
mkdir($tempDir, 0755, true);

// Create simple test PDF files (just empty files for testing)
$pdfFiles = [
	'jean_dupont.pdf',
	'marie_martin.pdf',
	'pierre_durand.pdf',
	'sophie_lefebvre.pdf'
];

foreach ($pdfFiles as $pdfFile) {
	// Create a minimal PDF-like file (not a real PDF, but enough for filename matching tests)
	$pdfContent = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n>>\nendobj\ntrailer\n<<\n/Root 1 0 R\n>>\n%%EOF";
	file_put_contents($tempDir.'/'.$pdfFile, $pdfContent);
}

// Create ZIP
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
	foreach ($pdfFiles as $pdfFile) {
		$zip->addFile($tempDir.'/'.$pdfFile, $pdfFile);
	}
	$zip->close();
	echo "Created: $zipPath\n";
} else {
	echo "ERROR: Failed to create ZIP file\n";
}

// Cleanup temp directory
foreach ($pdfFiles as $pdfFile) {
	@unlink($tempDir.'/'.$pdfFile);
}
@rmdir($tempDir);

// ============================================
// Summary
// ============================================

echo "\n";
echo "=== Fixtures Generated ===\n";
echo "valid_import.xlsx - XLSX file with 4 test salary records\n";
echo "test_pdfs.zip - ZIP file with 4 matching PDF files\n";
echo "\n";
echo "Test users (must exist in Dolibarr for full integration tests):\n";
echo "- Jean Dupont\n";
echo "- Marie Martin\n";
echo "- Pierre Durand\n";
echo "- Sophie Lefebvre\n";
echo "\n";
echo "Required payment types: VIR (Virement)\n";
echo "Required bank account: POSTE_CH (ref or label)\n";
