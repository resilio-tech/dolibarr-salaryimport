<?php
/**
 * Script to create a test XLSX file for salary import
 * Run with: php create_test_xlsx.php
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
require_once PHPEXCELNEW_PATH.'Spreadsheet.php';
require_once PHPEXCELNEW_PATH.'Writer/Xlsx.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Headers (must match expected format)
$headers = [
	'Prénom',
	'Nom',
	'Date de paiement',
	'Montant',
	'Libellé',
	'Date de début',
	'Date de fin',
	'Type de paiement',
	'Payé',
	'Compte bancaire'
];

// Write headers
foreach ($headers as $col => $header) {
	$sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
}

// Test data - employees that exist in the test database
$data = [
	['Jean', 'Dupont', '2026-01-15', 4500.00, 'Salaire janvier 2026', '2026-01-01', '2026-01-31', 'VIR', 'oui', 'POSTE_CH'],
	['Marie', 'Martin', '2026-01-15', 4200.00, 'Salaire janvier 2026', '2026-01-01', '2026-01-31', 'VIR', 'oui', 'POSTE_CH'],
	['Pierre', 'Durand', '2026-01-15', 3800.00, 'Salaire janvier 2026', '2026-01-01', '2026-01-31', 'VIR', 'oui', 'POSTE_CH'],
	['Sophie', 'Lefebvre', '2026-01-15', 4000.00, 'Salaire janvier 2026', '2026-01-01', '2026-01-31', 'VIR', 'oui', 'POSTE_CH'],
];

// Write data
foreach ($data as $rowIndex => $row) {
	foreach ($row as $colIndex => $value) {
		$sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 2, $value);
	}
}

// Auto-size columns
foreach (range('A', 'J') as $col) {
	$sheet->getColumnDimension($col)->setAutoSize(true);
}

// Save file
$outputPath = __DIR__.'/salaires_test.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($outputPath);

echo "Test file created: ".$outputPath."\n";
echo "Contains ".count($data)." salary entries for testing.\n";
