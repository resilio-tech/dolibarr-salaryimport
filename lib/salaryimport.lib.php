<?php
/* Copyright (C) 2023 SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    salaryimport/lib/salaryimport.lib.php
 * \ingroup salaryimport
 * \brief   Library files with common functions for SalaryImport
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function salaryimportAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("salaryimport@salaryimport");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/salaryimport/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/salaryimport/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	complete_head_from_modules($conf, $langs, null, $head, $h, 'salaryimport@salaryimport');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'salaryimport@salaryimport', 'remove');

	return $head;
}
