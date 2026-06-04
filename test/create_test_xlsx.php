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

// Headers (must match expected format). One row = one payment, grouped by the "Salaire" notation.
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

// Write headers
foreach ($headers as $col => $header) {
	$sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
}

// Test data - employees and bank account that exist in the test database.
// Mono-payment salaries on a CHF account: paid amount == CHF amount == total.
$data = [
	['2026-01-1', '2026-01-1-CHF', 'Jean', 'Dupont', '2026-01-15', 'VIR', 'POSTE_CH', 4500.00, 4500.00, 4500.00, 'Salaire janvier 2026', '2026-01-01', '2026-01-31', 'oui'],
	['2026-01-2', '2026-01-2-CHF', 'Marie', 'Martin', '2026-01-15', 'VIR', 'POSTE_CH', 4200.00, 4200.00, 4200.00, 'Salaire janvier 2026', '2026-01-01', '2026-01-31', 'oui'],
	['2026-01-3', '2026-01-3-CHF', 'Pierre', 'Durand', '2026-01-15', 'VIR', 'POSTE_CH', 3800.00, 3800.00, 3800.00, 'Salaire janvier 2026', '2026-01-01', '2026-01-31', 'oui'],
	['2026-01-4', '2026-01-4-CHF', 'Sophie', 'Lefebvre', '2026-01-15', 'VIR', 'POSTE_CH', 4000.00, 4000.00, 4000.00, 'Salaire janvier 2026', '2026-01-01', '2026-01-31', 'oui'],
];

// Write data
foreach ($data as $rowIndex => $row) {
	foreach ($row as $colIndex => $value) {
		$sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 2, $value);
	}
}

// Auto-size columns
foreach (range('A', 'N') as $col) {
	$sheet->getColumnDimension($col)->setAutoSize(true);
}

// Save file
$outputPath = __DIR__.'/salaires_test.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($outputPath);

echo "Test file created: ".$outputPath."\n";
echo "Contains ".count($data)." salary entries for testing.\n";
