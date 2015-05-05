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
 * An "enumeration" of all of the possible entry modes for a paper quiz.
 */
class quiz_papercopy_entry_modes
{
    /**
     * Manual mode: entry via upload of a CSV containing the student's responses. 
     *
     * This will typically be the output of a scantron machine.
     */
    const MODE_MANUAL = 0;

    /**
     * Scanned mode- FUTURE FEATURE
     *
     * Paper copies of the test are scanned, and then automatically interpreted by Moodle.
     */
    const MODE_SCANNED = 1;
}

/**
 * An "enumeration" of all possible shuffling modes for a paper quiz.
 */
class quiz_papercopy_shuffle_modes
{
    /**
     * Do not shuffle; quesitons are left in their original order.
     */
    const MODE_NOSHUFFLE = 0;

    /**
     * Shuffle both pages and the questions within pages; quesitons are still grouped by page.
     */
     const MODE_SHUFFLE_ALL = 1;

    /**
     * Shuffle the questions within a page, but do not alter the order of pages.
     */
    const MODE_SHUFFLE_WITHIN_PAGE = 2;

    /**
     * Shuffle the order of the quiz's pages, but do not alter the order of the quesitons within them.
     */
    const MODE_SHUFFLE_PAGES = 3;

    /**
     * Shuffle all questions, ignoring pages. Questions will not be grouped by pages.
     */
    const MODE_SHUFFLE_IGNORE_PAGES = 4;
}


/**
 * Quiz grading report settings form.
 *
 * @copyright  2011 Binghamton University
 * @author     Kyle Temkin <ktemkin@binghamton.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_papercopy_create_form extends moodleform 
{
    protected $includeauto;
    protected $hidden = array();
    protected $counts;
    protected $shownames;
    protected $showidnumbers;

    
    /**
     * Construct a new Create Copies form.
     *
     * @param int   $id         The current quiz's ID.
     * @param int   $enrollment The (possibly estimated) enrollment of the current course. Used as the default number of created copies.
     */
    public function __construct($id, $enrollment = 100) 
    {
        global $CFG;

        //store the (possibly approximate) course enrollment
        $this->enrollment = $enrollment;

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
        $mform->addElement('header', 'grading', get_string('coreoptions', 'quiz_papercopy'));


        //add an option to select from the various entry modes
        $entry_modes = 
            array
            (
                quiz_papercopy_entry_modes::MODE_MANUAL => get_string('manualentry', 'quiz_papercopy'),
                quiz_papercopy_entry_modes::MODE_SCANNED => get_string('scannedentry', 'quiz_papercopy'),

            );
        $mform->addElement('select', 'entrymode', get_string('entrymode', 'quiz_papercopy'), $entry_modes);

        //entry for the number of copies
        $mform->addElement('text', 'numcopies', get_string('numbercopies', 'quiz_papercopy'), 'size="4"');
        $mform->setType('numcopies', PARAM_INT);
        $mform->setDefault('numcopies', $this->enrollment);
        $mform->addRule('numcopies', get_string('mustbenumeric', 'quiz_papercopy'), 'numeric');

        //header for the layout options
        $mform->addElement('header', 'options', get_string('layoutoptions', 'quiz_papercopy'));


        //select the question ordering mode
        $shuffle_modes =
            array
            (
                quiz_papercopy_shuffle_modes::MODE_NOSHUFFLE => get_string('shufflenone', 'quiz_papercopy'),
                quiz_papercopy_shuffle_modes::MODE_SHUFFLE_IGNORE_PAGES => get_string('shuffleignore', 'quiz_papercopy'),
                quiz_papercopy_shuffle_modes::MODE_SHUFFLE_WITHIN_PAGE => get_string('shufflequestions', 'quiz_papercopy'),
                quiz_papercopy_shuffle_modes::MODE_SHUFFLE_PAGES  => get_string('shufflepages', 'quiz_papercopy'),
                quiz_papercopy_shuffle_modes::MODE_SHUFFLE_ALL => get_string('shuffleall', 'quiz_papercopy'),
            );
        $mform->addElement('select', 'shufflemode', get_string('shufflemode', 'quiz_papercopy'), $shuffle_modes);

        //checkbox to select "fixed descriptions"
        $mform->addElement('advcheckbox', 'fixinfos', '', get_string('fixinfo', 'quiz_papercopy'), null, array(0, 1));
        $mform->addElement('advcheckbox', 'fixfirst', '', get_string('fixfirstpage', 'quiz_papercopy'), null, array(0, 1));
        $mform->addElement('advcheckbox', 'fixlast', '', get_string('fixlastpage', 'quiz_papercopy'), null, array(0, 1));

        //TODO: get disabledIfs working for the options here?

        //submit button
        // $mform->addElement('header', 'submitform', get_string('generate', 'quiz_papercopy'));
        $mform->addElement('hidden', 'action', 'create');
        $mform->setType('action', PARAM_TEXT);
        $mform->addElement('submit', 'submitbutton', get_string('gendownload', 'quiz_papercopy'));
    }
}
