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
 * Strings for component 'quiz_grading', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package    quiz
 * @subpackage grading
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['quiz_papercopy'] = $string['pluginname'] = $string['papercopy'] = 'Paper Copies';
$string['massuploadattempts'] = 'Mass Upload Attempts';
$string['createcopies'] = 'Create Copies';
$string['modifyattempts'] = 'Grade, Modify, & Delete Attempts';
$string['papergrading'] = 'Paper Grading';

$string['entrymode'] = 'Entry Method';
$string['manualentry'] = 'Manual/Scantron';
$string['scannedentry'] = 'Scanned Entries';

$string['coreoptions'] = 'Copies';
$string['numbercopies'] = 'Number of Copies';

$string['deliverymethod'] = 'Download as';
$string['onepdf'] = 'One large PDF';
$string['zippdf'] = 'Multiple PDFs (zipped)';

$string['layoutoptions'] = 'Layout Options';
$string['shufflemode'] = 'Question Order';
$string['shufflenone'] = 'Don\'t shuffle questions or pages.';
$string['shuffleall'] = 'Shuffle pages, and the questions within them.';
$string['shufflequestions'] = 'Shuffle questions within pages, but leave page order intact.';
$string['shufflepages'] = 'Shuffle the order of pages, but don\'t shuffle questions within them.';
$string['shuffleignore'] = 'Shuffle all questions, ignoring pagination.';

$string['fixinfo'] = 'If the first element in a page is a description, it should remain the first element.';
$string['fixfirstpage'] = 'The first page of this quiz should remain the first page after shuffling.';
$string['fixlastpage'] = 'The last page of this quiz should remain the last page after shuffling.';

$string['gendownload'] = 'Generate & Download';
$string['generate'] = 'Generate Copies';

$string['mustbenumeric'] = 'You must provide a numeric number of copies.';

$string['createdon'] = 'Created On';
$string['batchcount'] = 'Copy Count';
$string['answerkey_s'] = 'Answer Key(s)';
$string['answerkey'] = 'Answer Key';
$string['answerkeys'] = 'Answer Keys';
$string['answerkeyfortestid'] = 'Answer Key for Test ID: {$a}';
$string['deletecopy'] = 'Delete Copy';
$string['delete'] = 'Delete';
$string['assignusers'] = 'Assign to Users';
$string['copy'] = 'copy';
$string['copies'] = 'copies';
$string['download'] = 'Download';
$string['withanswerkey'] = 'with answer keys';

$string['copynumber'] = 'Copy #{$a}';
$string['answerkeynumber'] = 'Answer Key #{$a}';


$string['importmethod'] = 'Upload Method';
$string['fileformat'] = 'File Format';
$string['csvfile'] = 'Response Data (Scantron CSV)';
$string['scanfiles'] = 'Hand-Graded Scans';
$string['gradedata'] = 'Response Data';
$string['attachedfiles'] = 'Attachments/Scans';
$string['importdata'] = 'Import Responses';

$string['missingfile'] = 'You must specify a file containing responses to upload!';
$string['overwrite'] = 'Overwrite prior attempts with the provided IDs, if they exist.';
$string['errorifnotfirst'] = 'Don\'t allow uploaded scores to be considered subsequent attempts.';
$string['forcefirst'] = 'Delete any prior attempts at this quiz by a user once his/her score is uploaded.';
$string['allowcrossuser'] = 'Allow attempts to be moved from one user to another.';
$string['donotclose'] = 'Don\'t close the quiz after the user\'s responses have been uploaded.';

$string['invalidusage'] = 'Test ID provided was invalid!';
$string['invalidformat'] = 'The File ID provided did not correspond to a valid Usage & question!';
$string['couldnotidentify'] = 'Could not determine the user to which this test belongs.';
$string['attemptexists'] = 'A grade was already entered for this user (and overwrite was not set.)';
$string['conflictinguser'] = 'A grade was already entered for this exam; but it belonged to a different user (and cross-user-overwrite was not set.)';

$string['continue'] = 'Continue';
$string['nooperation'] = 'No operation was performed.';

$string['failuresoccurred'] = 'Errors occurred in one or more operations:';
$string['successesoccurred'] = '{$a} attempts were graded succesfully:';


$string['scantroninfo'] = 'Scantron Info';
$string['errordescription'] = 'Error Description';

$string['username'] = 'User Name';
$string['associateduser'] = 'Associated User (for Manual Grading)';
$string['rawgrade'] = 'Raw Grade';

$string['notassociated'] = 'Not Associated';
$string['disassociate'] = 'disassociate';
$string['undoassociate'] = 'Disassociate';
$string['closeduringassociate'] = 'Close after Associate';
$string['testid'] = 'Test ID';

$string['submitassociations'] = 'Make Changes';
$string['confirmcommit'] = 'Are you sure you want to permenantly modify the selected attempts?';
$string['resetassociations'] = 'Reset';
$string['allnone'] = 'all/none';

$string['deletebatches'] = 'Delete Selected';
$string['confirmdelete'] = 'Are you sure you want to permenantly delete the selected sets of paper copies?';


$string['papercopybatch'] = 'Paper Copies (batch created on {$a})';

//TODO: allow this to be customized?
$string['name'] = 'Name ______________________________________';

$string['scanattached'] = 'Graded scan is attached below:';
$string['autograde'] = 'Grade was automatically extracted from the scan above.';
