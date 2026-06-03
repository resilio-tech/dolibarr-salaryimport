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
 *    \file       salaryimport/salaryimporttemplate.php
 *    \ingroup    salaryimport
 *    \brief      Generate and download an empty XLSX template for salary import
 */

$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Load our patched File class BEFORE PhpSpreadsheet autoloader (open_basedir fix)
require_once __DIR__.'/lib/PhpSpreadsheetFileFix.php';
require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
require_once PHPEXCELNEW_PATH.'Spreadsheet.php';
require_once PHPEXCELNEW_PATH.'Writer/Xlsx.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Security check
if (!isModEnabled('salaryimport')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('salaryimport', 'import', 'read')) {
	accessforbidden();
}

// Canonical column headers (the parser also accepts the English equivalents).
// One row = one payment, grouped by the "Salaire" notation.
$headers = array(
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
);

// Example rows: one mono-payment salary, then one salary paid in two currencies
// (same notation on both lines). These are illustrative and meant to be replaced.
$examples = array(
	array('2026-05-1', '2026-05-1-CHF', 'Jean', 'Dupont', '2026-05-25', 'VIR', 'POSTE_CH', 4500, 4500, 4500, 'Salaire mai 2026', '2026-05-01', '2026-05-31', 'oui'),
	array('2026-05-2', '2026-05-2-EUR', 'Marie', 'Martin', '2026-05-25', 'VIR', 'Compte EUR', 2100, 2000, 5000, 'Salaire mai 2026', '2026-05-01', '2026-05-31', 'oui'),
	array('2026-05-2', '2026-05-2-CHF', 'Marie', 'Martin', '2026-05-25', 'VIR', 'POSTE_CH', 3000, 3000, 5000, 'Salaire mai 2026', '2026-05-01', '2026-05-31', 'oui'),
);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

foreach ($headers as $col => $header) {
	$sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
}

foreach ($examples as $rowIndex => $row) {
	foreach ($row as $colIndex => $value) {
		$sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 2, $value);
	}
}

$lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
foreach (range('A', $lastColumn) as $col) {
	$sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = 'salaryimport_template.xlsx';

// Stream the file as a download (no page chrome)
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$db->close();
exit;
