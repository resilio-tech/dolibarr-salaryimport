<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2015      Jean-François Ferry	<jfefe@aternatik.fr>
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
 *	\file       salaryimport/salaryimportfile.php
 *	\ingroup    salaryimport
 *	\brief      File upload and preview page for salary import
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

// IMPORTANT: Load our patched File class BEFORE PhpSpreadsheet autoloader
// This fixes open_basedir issues with PhpSpreadsheet 1.12.0
// The patch prevents file_exists() calls on internal ZIP paths like "/xl/worksheets/sheet1.xml"
require_once __DIR__.'/lib/PhpSpreadsheetFileFix.php';

require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
require_once PHPEXCELNEW_PATH.'Spreadsheet.php';
require_once PHPEXCELNEW_PATH.'Reader/Xlsx.php';

// Load service classes
require_once __DIR__.'/class/SalaryImportService.class.php';

// Load translation files required by the page
$langs->loadLangs(array("salaryimport@salaryimport"));

$action = GETPOST('action', 'aZ09');

$max = 5;
$now = dol_now();

// Security check - Protection if external user
$socid = GETPOST('socid', 'int');
if (isset($user->socid) && $user->socid > 0) {
	$action = '';
	$socid = $user->socid;
}

// Security check (enable the most restrictive one)
if ($user->socid > 0) accessforbidden();
if ($user->socid > 0) $socid = $user->socid;
if (!isModEnabled('salaryimport')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('salaryimport', 'import', 'read')) {
	accessforbidden();
}
if (empty($user->admin)) {
	accessforbidden('Must be admin');
}

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("SalaryImportArea"));

print load_fiche_titre($langs->trans("SalaryImportStep2"), '', 'salaryimport.png@salaryimport');

$file = $_FILES['file'];
$zip = $_FILES['zip'];

try {
	// Initialize service
	$service = new SalaryImportService($db, $user);

	// Handle XLSX upload
	$xlsxResult = $service->handleXlsxUpload($file);
	if ($xlsxResult < 0) {
		throw new Exception(implode('<br />', $service->errors));
	}

	// Handle ZIP upload (optional)
	$zipResult = $service->handleZipUpload($zip);
	if ($zipResult < 0) {
		throw new Exception(implode('<br />', $service->errors));
	}

	// Process files and prepare preview
	$processResult = $service->processForPreview();
	if ($processResult < 0) {
		throw new Exception(implode('<br />', $service->errors));
	}

	// Get data for display
	$previewData = $service->getPreviewData();
	$headers = $service->getPreviewHeaders();
	$lines = $service->getParsedLines();

	print dol_get_fiche_head(array(), '');

	// Summary message
	print '<div class="opacitymedium marginbottomonly">';
	print $langs->trans("RowsDetected", count($previewData));
	print '</div>';

	// Add PDF column to headers for display
	$displayHeaders = $headers;
	$displayHeaders[count($displayHeaders) + 1] = $langs->trans("PdfFile");

	// Display preview table
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	foreach ($displayHeaders as $header) {
		print '<th class="left">'.htmlspecialchars($header).'</th>';
	}
	print '</tr>';

	foreach ($previewData as $index => $row) {
		print '<tr class="oddeven">';

		// Display original line data
		$line = $lines[$index];
		foreach ($headers as $col => $header) {
			$value = isset($line[$header]) ? $line[$header] : '';

			// Format dates for display
			if ($header === 'Date de paiement' && isset($row['datep_display'])) {
				$value = $row['datep_display'];
			} elseif ($header === 'Date de début' && isset($row['datesp_display'])) {
				$value = $row['datesp_display'];
			} elseif ($header === 'Date de fin' && isset($row['dateep_display'])) {
				$value = $row['dateep_display'];
			} elseif ($header === 'Type de paiement' && isset($row['typepayment_label'])) {
				$value = $row['typepayment_label'];
			}

			print '<td>'.htmlspecialchars($value).'</td>';
		}

		// PDF column
		$pdfDisplay = !empty($row['pdf_display']) ? $row['pdf_display'] : $langs->trans("NoPdfAttached");
		print '<td>'.htmlspecialchars($pdfDisplay).'</td>';
		print '</tr>';
	}
	print '</table>';

	print dol_get_fiche_end();

	// Build confirmation form
	$formData = $service->serializeForForm();

	print '<form method="POST" action="'.dol_buildpath('/custom/salaryimport/salaryimportconfirm.php', 1).'" enctype="multipart/form-data">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="confirm">';

	foreach ($formData as $index => $row) {
		foreach ($row as $key => $value) {
			print '<input type="hidden" name="t_data['.$index.']['.$key.']" value="'.htmlspecialchars($value).'">';
		}
	}

	print '<div class="center">';
	print '<input type="submit" class="button" value="'.$langs->trans("ConfirmImport").'">';
	print ' &nbsp; ';
	print '<a class="button button-cancel" href="'.dol_buildpath('/custom/salaryimport/salaryimportindex.php', 1).'">'.$langs->trans("Cancel").'</a>';
	print '</div>';

	print '</form>';

} catch (Exception $e) {
	// Cleanup on error
	if (isset($service)) {
		$service->cleanup();
	}

	setEventMessages($langs->trans("ErrorDuringImport").': '.$e->getMessage(), null, 'errors');

	print '<div class="center">';
	print '<a class="button" href="'.dol_buildpath('/custom/salaryimport/salaryimportindex.php', 1).'">'.$langs->trans("BackToImport").'</a>';
	print '</div>';
}

// End of page
llxFooter();
$db->close();
