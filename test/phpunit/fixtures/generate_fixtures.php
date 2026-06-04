<?php
/**
 * Script to generate test fixtures for the salaryimport PHPUnit tests
 * Usage: php generate_fixtures.php
 *
 * This script creates:
 * - valid_import.xlsx: A valid XLSX file with test salary data
 * - empty_headers.xlsx: An XLSX file with some empty headers (for testing)
 * - test_pdfs.zip: A ZIP file containing test PDF files
 */

// Load PhpSpreadsheet from Dolibarr includes
$basePaths = array(
	__DIR__.'/../../../../../includes',
	__DIR__.'/../../../../../../includes',
	'/var/www/html/includes',
);

$loaded = false;
foreach ($basePaths as $basePath) {
	$spreadsheetPath = $basePath.'/phpoffice/phpspreadsheet/src/autoloader.php';
	$psrPath = $basePath.'/Psr/autoloader.php';

	if (file_exists($spreadsheetPath) && file_exists($psrPath)) {
		require_once $psrPath;
		require_once $spreadsheetPath;
		$loaded = true;
		break;
	}
}

if (!$loaded) {
	die("Error: Could not load PhpSpreadsheet or Psr. Tried base paths:\n".implode("\n", $basePaths)."\n");
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

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

// Headers (new format: one row = one payment, grouped by the "Salaire" notation)
$headers = [
	'Salaire',
	'Réf paiement',
	'Prénom',
	'Nom',
	'Date de paiement',
	'Type de paiement',
	'Compte bancaire',
	'Montant payé',
	'Montant CHF',
	'Salaire total CHF',
	'Libellé',
	'Date de début',
	'Date de fin',
	'Payé'
];

$col = 1;
foreach ($headers as $header) {
	$sheet->setCellValueByColumnAndRow($col, 1, $header);
	$col++;
}

// Test data - mono-payment salaries on a CHF account (paid == CHF == total)
$testData = [
	['2026-01-1', '2026-01-1-CHF', 'Jean', 'Dupont', dateToExcel('2026-01-31'), 'VIR', 'POSTE_CH', 2500.00, 2500.00, 2500.00, 'Salaire Janvier 2026', dateToExcel('2026-01-01'), dateToExcel('2026-01-31'), 'oui'],
	['2026-01-2', '2026-01-2-CHF', 'Marie', 'Martin', dateToExcel('2026-01-31'), 'VIR', 'POSTE_CH', 2800.50, 2800.50, 2800.50, 'Salaire Janvier 2026', dateToExcel('2026-01-01'), dateToExcel('2026-01-31'), 'oui'],
	['2026-01-3', '2026-01-3-CHF', 'Pierre', 'Durand', dateToExcel('2026-01-31'), 'VIR', 'POSTE_CH', 2200.00, 2200.00, 2200.00, 'Salaire Janvier 2026', dateToExcel('2026-01-01'), dateToExcel('2026-01-31'), 'oui'],
	['2026-02-4', '2026-02-4-CHF', 'Sophie', 'Lefebvre', dateToExcel('2026-02-28'), 'VIR', 'POSTE_CH', 3100.75, 3100.75, 3100.75, 'Salaire Février 2026', dateToExcel('2026-02-01'), dateToExcel('2026-02-28'), 'non'],
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

// Format date columns (E = Date de paiement, L = Date de début, M = Date de fin)
$dateFormat = 'DD/MM/YYYY';
$sheet->getStyle('E2:E5')->getNumberFormat()->setFormatCode($dateFormat);
$sheet->getStyle('L2:L5')->getNumberFormat()->setFormatCode($dateFormat);
$sheet->getStyle('M2:M5')->getNumberFormat()->setFormatCode($dateFormat);

// Auto-size columns
foreach (range('A', 'N') as $columnID) {
	$sheet->getColumnDimension($columnID)->setAutoSize(true);
}

// Save
$xlsxPath = $fixturesDir.'/valid_import.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($xlsxPath);
echo "Created: $xlsxPath\n";

// ============================================
// Generate empty_headers.xlsx (for testing empty header handling)
// ============================================

echo "Generating empty_headers.xlsx...\n";

$spreadsheet2 = new Spreadsheet();
$sheet2 = $spreadsheet2->getActiveSheet();

// Headers with some empty columns
$sheet2->setCellValue('A1', 'Prénom');
$sheet2->setCellValue('B1', '');  // Empty header
$sheet2->setCellValue('C1', 'Nom');
$sheet2->setCellValue('D1', null);  // Null header
$sheet2->setCellValue('E1', 'Montant');

// Data
$sheet2->setCellValue('A2', 'Jean');
$sheet2->setCellValue('B2', 'skip_this');
$sheet2->setCellValue('C2', 'Dupont');
$sheet2->setCellValue('D2', 'skip_this_too');
$sheet2->setCellValue('E2', 2500);

$sheet2->setCellValue('A3', 'Marie');
$sheet2->setCellValue('B3', 'ignored');
$sheet2->setCellValue('C3', 'Martin');
$sheet2->setCellValue('D3', 'also_ignored');
$sheet2->setCellValue('E3', 2800);

$xlsxPath2 = $fixturesDir.'/empty_headers.xlsx';
$writer2 = new Xlsx($spreadsheet2);
$writer2->save($xlsxPath2);
echo "Created: $xlsxPath2\n";

// ============================================
// Generate test_pdfs.zip
// ============================================

echo "Generating test_pdfs.zip...\n";

$zipPath = $fixturesDir.'/test_pdfs.zip';
$tempDir = sys_get_temp_dir().'/salaryimport_fixtures_'.uniqid();
mkdir($tempDir, 0755, true);

// Create simple test PDF files
$pdfFiles = [
	'jean_dupont.pdf',
	'marie_martin.pdf',
	'pierre_durand.pdf',
	'sophie_lefebvre.pdf'
];

foreach ($pdfFiles as $pdfFile) {
	// Create a minimal PDF-like file
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

echo "\n=== Fixtures Generated ===\n";
echo "valid_import.xlsx   - XLSX file with 4 test salary records\n";
echo "empty_headers.xlsx  - XLSX file with empty headers (for testing)\n";
echo "test_pdfs.zip       - ZIP file with 4 matching PDF files\n";
echo "\nTest users (for integration tests):\n";
echo "- Jean Dupont\n";
echo "- Marie Martin\n";
echo "- Pierre Durand\n";
echo "- Sophie Lefebvre\n";
