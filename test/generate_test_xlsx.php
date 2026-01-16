<?php
/**
 * Script pour générer un fichier XLSX de test pour le module salaryimport
 * Usage: php generate_test_xlsx.php
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails\n");
}

require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// En-têtes
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

// Données de test
// Note: Les dates Excel sont des nombres (jours depuis 1900-01-01, avec le bug du 29 février 1900)
// Pour convertir une date: (timestamp / 86400) + 25569

function dateToExcel($dateStr) {
	$timestamp = strtotime($dateStr);
	return ($timestamp / 86400) + 25569;
}

$testData = [
	[
		'Jean',
		'Dupont',
		dateToExcel('2024-01-31'),
		2500.00,
		'VIR',  // Virement - code standard Dolibarr
		'Salaire Janvier 2024',
		dateToExcel('2024-01-01'),
		dateToExcel('2024-01-31'),
		'oui',
		'COMPTE1'  // À adapter selon ton compte bancaire
	],
	[
		'Marie',
		'Martin',
		dateToExcel('2024-01-31'),
		2800.50,
		'VIR',
		'Salaire Janvier 2024',
		dateToExcel('2024-01-01'),
		dateToExcel('2024-01-31'),
		'oui',
		'COMPTE1'
	],
	[
		'Pierre',
		'Durand',
		dateToExcel('2024-01-31'),
		2200.00,
		'VIR',
		'Salaire Janvier 2024',
		dateToExcel('2024-01-01'),
		dateToExcel('2024-01-31'),
		'oui',
		'COMPTE1'
	],
	[
		'Sophie',
		'Lefebvre',
		dateToExcel('2024-02-29'),
		3100.75,
		'CHQ',  // Chèque
		'Salaire Février 2024',
		dateToExcel('2024-02-01'),
		dateToExcel('2024-02-29'),
		'non',
		'COMPTE1'
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

// Sauvegarder
$outputPath = DOL_DATA_ROOT.'/salaryimport/test_salaires.xlsx';
if (!is_dir(DOL_DATA_ROOT.'/salaryimport')) {
	dol_mkdir(DOL_DATA_ROOT.'/salaryimport');
}

$writer = new Xlsx($spreadsheet);
$writer->save($outputPath);

echo "Fichier généré: ".$outputPath."\n";
echo "\nDonnées de test créées:\n";
echo "- 4 employés: Jean Dupont, Marie Martin, Pierre Durand, Sophie Lefebvre\n";
echo "- Types de paiement: VIR (virement), CHQ (chèque)\n";
echo "- Compte bancaire: COMPTE1 (à créer/adapter dans Dolibarr)\n";
echo "\nN'oublie pas de créer ces utilisateurs dans Dolibarr avec les mêmes noms/prénoms!\n";
