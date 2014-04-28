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
require_once($CFG->dirroot . '/mod/quiz/report/papercopy/report.php');

/**
 * Quiz grading report settings form.
 *
 * @copyright  2011 Binghamton University
 * @author     Kyle Temkin <ktemkin@binghamton.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or latee
 */
class quiz_papercopy_associate_users_form extends moodleform 
{
    protected $usages;
    protected $quiz_id;
    protected $batch_id;
    protected $batch;
    protected $cm;
    protected $context;

    public function __construct($id, $cm, $batch, $context, $usages) 
    {
        global $CFG, $DB;

        //store the fields
        $this->quiz_id = $id;
        $this->cm = $cm;
        $this->usages = $usages;
        $this->context = $context;
        $this->batch = $batch;

        parent::__construct($CFG->wwwroot . '/mod/quiz/report.php?id='.$this->cm->id.'&mode=papercopy&batch='.$this->batch->id, null, 'post');
    }

    /**
     * Specifies the form definition; i.e. the order and type of fields to be displayed.
     */
    protected function definition() 
    {
        //get a quick reference to the core moodleform
        $mform =& $this->_form;

        //get the list of enrolled usernames
        $enrolled_names = $this->get_enrolled_names();

        //add "not associated" as a default
        $enrolled = array(-1 => get_string('notassociated', 'quiz_papercopy'), 0 => '-------');

        //copy the enrolled student to the default array 
        foreach($enrolled_names as $uid => $name)
            $enrolled[$uid] = $name;

        //add a header
        $mform->addElement('header', 'papercopyedit', get_string('papercopybatch', 'quiz_papercopy', $this->batch->created));

        //unfortunately, we can't use html_table objects with moodleforms, to my knowledge:
        $mform->addElement('html', html_writer::start_tag('table', array('class' => 'generaltable generalbox', 'width' => '50%', 'align' => 'center', 'style' => 'text-align: center;')));

        //table headers
        $mform->addElement('html', html_writer::start_tag('thead'));
        $mform->addElement('html', html_writer::start_tag('tr'));
        $mform->addElement('html', html_writer::tag('th', get_string('testid', 'quiz_papercopy'), array('class' => 'header')));
        $mform->addElement('html', html_writer::tag('th', get_string('associateduser', 'quiz_papercopy'), array('class' => 'header')));
        $mform->addElement('html', html_writer::tag('th', get_string('closeduringassociate', 'quiz_papercopy'), array('class' => 'header')));
        $mform->addElement('html', html_writer::tag('th', get_string('undoassociate', 'quiz_papercopy'), array('class' => 'header')));
        $mform->addElement('html', html_writer::tag('th', get_string('delete', 'quiz_papercopy'), array('class' => 'header')));
        $mform->addElement('html', html_writer::end_tag('tr'));
        $mform->addElement('html', html_writer::end_tag('thead'));

        //start the table body
        $mform->addElement('html', html_writer::start_tag('tbody'));

        //keep track of the row's "parity", which determines its background color
        $parity = 1;

        //create a row for each usage
        foreach($this->usages as $usage)
        {
            //switch partiy
            $parity = 1 - $parity;

            //get the associated user, if any
            $associated_user = $this->associated_uid($usage);

            //start a row
            $mform->addElement('html', html_writer::start_tag('tr', array('class' => 'r'.$parity)));

            //test id
            $mform->addElement('html', html_writer::tag('td', $usage));
        
            //associate/disassociate prompt
            $mform->addElement('html', html_writer::start_tag('td', array('align' => 'center')));
            $this->display_associate_prompt($mform, $usage, $associated_user, $enrolled);
            $mform->addElement('html', html_writer::end_tag('td'));

            //closeafter checkbox 
            $mform->addElement('html', html_writer::start_tag('td'));

            //if no user has been associated with this Test ID,  _and_ the test is not already closed
            //display a checkbox for "close after associate"
            if(!$associated_user)
                $mform->addElement('advcheckbox', 'closeafter['.$usage.']', '', '', array('group' => 1), array(0, 1));

            $mform->addElement('html', html_writer::end_tag('td'));

            
            //disassociate checkbox 
            $mform->addElement('html', html_writer::start_tag('td'));

            //if the usage is associated with a user, display the disassociate checkbox
            if($associated_user)
                $mform->addElement('advcheckbox', 'disassociate['.$usage.']', '', '', array('group' => 2), array(0, 1));

            $mform->addElement('html', html_writer::end_tag('td'));

            //delete checkbox 
            $mform->addElement('html', html_writer::start_tag('td'));
            $mform->addElement('advcheckbox', 'delete['.$usage.']', '', '', array('group' => 3), array(0, 1));
            $mform->addElement('html', html_writer::end_tag('td'));


            //end the row
            $mform->addElement('html', html_writer::end_tag('tr'));

        }

        
        $mform->addElement('html', html_writer::end_tag('tbody'));


        $mform->addElement('html', html_writer::start_tag('tfoot'));

        $mform->addElement('html', html_writer::start_tag('tr'));
        $mform->addElement('html', html_writer::tag('td', ''));
        $mform->addElement('html', html_writer::tag('td', ''));
        
        $mform->addElement('html', html_writer::start_tag('td'));
        $this->add_checkbox_controller(1, get_string('allnone', 'quiz_papercopy'));
        $mform->addElement('html', html_writer::end_tag('td'));

        $mform->addElement('html', html_writer::start_tag('td'));
        $this->add_checkbox_controller(2, get_string('allnone', 'quiz_papercopy'));
        $mform->addElement('html', html_writer::end_tag('td'));

        $mform->addElement('html', html_writer::start_tag('td'));
        $this->add_checkbox_controller(3, get_string('allnone', 'quiz_papercopy'));
        $mform->addElement('html', html_writer::end_tag('td'));

        $mform->addElement('html', html_writer::end_tag('tr'));
        $mform->addElement('html', html_writer::end_tag('tfoot'));

        $mform->addElement('html', html_writer::end_tag('table'));



        //submit button
        $confirmation_message = addslashes_js(get_string('confirmcommit', 'quiz_papercopy')); 
        $mform->addElement('hidden', 'action', 'associate');
        $mform->addElement('submit', 'submitbutton', get_string('submitassociations', 'quiz_papercopy'), array('onClick' => 'return confirm("'.$confirmation_message.'")'));
    }

    protected function display_associate_prompt(&$mform, $usage_id, $associated_user, $enrolled_students)
    {
        global $DB;

        //if a user is associated, display their name, and allow for disassociation
        if($associated_user !== false)
        {
            //get the user's name
            // TODO: get rid of this dependency?
            $username = quiz_papercopy_report::get_user_name($associated_user);

            //and output the disassociation prompt
            $mform->addElement('html', $username);
        }
        else
        {
           //and display a list of names to select
            $mform->addElement('select', 'associate['.$usage_id.']', '', $enrolled_students);
        }
    
    }

    protected function get_enrolled_names()
    {
        //get a list of all enrolled users
        $enrollment = get_enrolled_users($this->context);    

        //start a new list of enrolled usernames
        $enrolled = array();

        //build an associative array, which maps userid to (last, first) name
        foreach($enrollment as $enrolled_user)
            $enrolled[$enrolled_user->id] = $enrolled_user->lastname . ' ' . $enrolled_user->firstname;

        //return the new array
        return $enrolled;
    } 

    protected function associated_uid($usage_id)
    {
        global $DB;

        //try to find the quiz attempt for the given UID
        $attempt = $DB->get_record('quiz_attempts', array('uniqueid' => $usage_id), 'userid, quiz', IGNORE_MISSING);

        //check consistency
        if($attempt !== false && $attempt->quiz != $this->quiz_id)
            throw new coding_error('Internal consistency error: a quiz attempt has this usage associated with a different quiz than the batch it belongs to.');

        //if we found a quiz attempt, return its associated UID- otherwise, return false
        if($attempt)
            return $attempt->userid;
        else
            return false;
    }

}
