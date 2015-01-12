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
 * This file defines the setting form for the quiz grading report.
 *
 * @package    quiz
 * @subpackage grading
 * @copyright  2010 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');


/**
 * "Enumeration" which describes the various methods of importing grades.
 */
class quiz_papercopy_upload_method
{
    /**
     * CSV; responses are uploaded via a CSV, which usually comes from a Scantron machine.
     */
    const METHOD_CSV = 0;

    /**
     * Scanned images: Scans of student work are uploaded; and respones are identified using QR codes for manual grading.
     * In a future implementation, some quesitons may be automatically graded using image recognition and scan-tron like selection areas.
     *
     */
    const METHOD_MANUAL_SCANS = 1;

}

/**
 * Quiz grading report settings form.
 *
 * @copyright  2011 Binghamton University
 * @author     Kyle Temkin <ktemkin@binghamton.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_papercopy_import_form extends moodleform 
{
    protected $includeauto;
    protected $hidden = array();
    protected $counts;
    protected $shownames;
    protected $showidnumbers;

    /**
     * Creates a new Import Responses form.
     *
     * @param int   $id  The quiz ID for which this form is being created.
     */
    public function __construct($id) 
    {
        global $CFG;

        parent::__construct($CFG->wwwroot . '/mod/quiz/report.php?id='.$id.'&mode=papercopy', null, 'post');
    }

    /**
     * Specifies the form definition; i.e. the order and type of fields to be displayed.
     */
    protected function definition() 
    {
        //get a quick reference to the core moodleform
        $mform =& $this->_form;

        //header for paper grading options
        $mform->addElement('header', 'importmethod', get_string('importmethod', 'quiz_papercopy'));

        //file upload form
        $mform->addElement('filepicker', 'gradedata', get_string('gradedata', 'quiz_papercopy'), array('accepted_types' => '*.csv'));
        $mform->addRule('gradedata', get_string('missingfile', 'quiz_papercopy'), 'required', null, 'server');

        //attachments form
        $mform->addElement('filemanager', 'attachments', get_string('attachedfiles', 'quiz_papercopy'), array('subdirs' => false));

        //add an option to select from the various entry modes
        $entry_modes = 
            array
            (
                quiz_papercopy_upload_method::METHOD_CSV => get_string('csvfile', 'quiz_papercopy'),
                quiz_papercopy_upload_method::METHOD_MANUAL_SCANS => get_string('scanfiles', 'quiz_papercopy')
            );
        $mform->addElement('select', 'fileformat', get_string('fileformat', 'quiz_papercopy'), $entry_modes);

        //overwrite existing
        $mform->addElement('advcheckbox', 'overwrite', '', get_string('overwrite', 'quiz_papercopy'), null, array(0, 1));
        $mform->addElement('advcheckbox', 'allowcrossuser', '', get_string('allowcrossuser', 'quiz_papercopy'), null, array(0, 1));
        $mform->disabledIf('allowcrossuser', 'overwrite');

        //advanced options
        $mform->addElement('header', 'advupload', get_string('advanced'));
        $mform->addElement('advcheckbox', 'errorifnotfirst', '', get_string('errorifnotfirst', 'quiz_papercopy'), null, array(0, 1));
        $mform->addElement('advcheckbox', 'forcefirst', '', get_string('forcefirst', 'quiz_papercopy'), null, array(0, 1));
        $mform->addElement('advcheckbox', 'donotclose', '', get_string('donotclose', 'quiz_papercopy'), null, array(0, 1));

        //"don't allow subsequent" and "overwrite subsequent" are mutually exclusive, as they both handle the same thing in different ways
        $mform->disabledIf('forcefirst', 'errorifnotfirst', 'checked');
        $mform->disabledIf('errorifnotfirst', 'forcefirst', 'checked');

        //set the advanced 
        $mform->setAdvanced('advupload');

        //submit button
        $mform->addElement('header', 'submitform', get_string('generate', 'quiz_papercopy'));
        $mform->addElement('hidden', 'action', 'upload');
        $mform->setType('action', PARAM_TEXT);
        $mform->addElement('submit', 'submitbutton', get_string('importdata', 'quiz_papercopy'));
    }
}
