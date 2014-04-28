<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Quiz grading report version information.
 *
 * @package    quiz
 * @subpackage papercopy
 * @copyright  2012 Binghamton Universtiy
 * @author     Kyle J. Temkin <ktemkin@binghamton.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

include 'phpqrcode/qrlib.php';

// Only run if we have the necessary params.
if(empty($_GET['quba']) || empty($_GET['q']) || empty($_GET['qa'])) {
    die('Missing params.');
}

// Generate a QR Code with the relevant information.
QRCode::png($_GET['quba'] . '|' . $_GET['q'] . '|' . $_GET['qa'], false, 'Q', 5);

