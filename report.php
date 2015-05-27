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
 * This file defines the quiz manual grading report class.
 *
 * @package    quiz
 * @subpackage grading
 * @copyright  2006 Gustav Delius
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/default.php');
require_once($CFG->dirroot . '/mod/quiz/report/papercopy/lib.php');
require_once($CFG->dirroot . '/mod/quiz/report/papercopy/createcopies_form.php');
require_once($CFG->dirroot . '/mod/quiz/report/papercopy/importgrades_form.php');
require_once($CFG->dirroot . '/mod/quiz/report/papercopy/associateusers_form.php');

//simple, internal exceptions- FIXME: add descriptions?
class quiz_papercopy_could_not_identify_exception extends exception {}
class quiz_papercopy_invalid_usage_id_exception extends exception {}
class quiz_papercopy_attempt_exists_exception extends exception {}
class quiz_papercopy_conflicting_users_exception extends exception {}
class quiz_papercopy_not_first_attempt_when_required extends exception {}
class quiz_papercopy_benign_row_exception extends exception {}

/**
 * Quiz report to allowing an instructor to create and manage paper copies of a Moodle quiz.
 *
 * @copyright  2011 Binghamton University
 * @author     Kyle Temkin <ktemkin@binghamton.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_papercopy_report extends quiz_default_report 
{
    /**
     *  The table used for storing information about batches of produced papercopies.
     */
    const BATCH_TABLE = 'quiz_papercopy_batches';

    /**
     * @var stdClass $cm The course-module for the active quiz.
     */
    protected $cm;

    /**
     * @var stdClass $quiz The database row for the active quiz.
     */
    protected $quiz;

    /**
     * @var moodle_quiz $quizobj The quiz object for the active quiz.
     */
    protected $quizobj;

    /**
     * @var stdClass $context   The context for the current report.
     */
    protected $context;

    /**
     * Displays the papercopy report for a given quiz.
     *
     * @param stdClass     $quiz       An associative array of quiz data, for which this report should be rendered.
     * @param stdClass     $cm         A data set representing the given course-module.
     * @param stdClass     $course     A data set representing the given course.
     */
    public function display($quiz, $cm, $course)
    {
        //access the global configuration, database, and page renderer
        global $CFG, $DB, $PAGE;

        //store local copies of the quiz, course, module and context
        $this->quiz = $quiz;
        $this->cm = $cm;
        $this->course = $course;
        $this->context = context_module::instance($cm->id);
        
        //get a reference to the current quiz
        $this->quizobj = $this->get_quiz_object();

        //and load the questions into memory
        $this->quizobj->preload_questions();
        $this->quizobj->load_questions();

        //get the current action, if one has been specified
        $action = optional_param('action', false, PARAM_ALPHA);

        //start output
        $this->print_header_and_tabs($cm, $course, $quiz, 'grading');

        //--- display the report:

        //if there are no questions in the quiz, display an error message
        if (!$this->quizobj->get_questions() ) {
            echo quiz_no_questions_message($quiz, $cm, $this->context);
        //otherwise, if we have no action, display the index page
        } else if (!$action)
        {
            $this->maintain_batches();
            $this->display_index();
        }

        //if we have an action, trigger the appropriate handler
        else
            switch($action)
            {
                //create new copies based on the upload data
                case 'create':

                    //ensure we have a valid session; i.e. prevent CSRF
                    confirm_sesskey();

                    //and handle the create action
                    $this->handle_action_create();
                    break;

                //upload a file, and determine how to use it to get response content
                case 'upload':

                    //ensure we have a valid session; i.e. prevent CSRF
                    confirm_sesskey();

                    //and handle the upload
                    $this->handle_action_upload();
                    break;

                case 'associate':

                    //ensure we have a valid sesseion
                    confirm_sesskey();

                    //handle associations
                    $this->handle_action_associate();

                    //roll through to view/edit

                case 'viewedit':
                    $this->maintain_batches();
                    $this->handle_action_view_edit();
                    break;

                case 'deletebatches':

                    //ensure we have a valid session
                    confirm_sesskey();
                    $this->handle_action_delete_batches();
                    $this->maintain_batches();

                    //roll into the index display

                default:
                    $this->display_index();

            }


        return true;
    }


    /**
     * Handles a form-driven request to delete one or more batches.
     */
    protected function handle_action_delete_batches()
    {
        //retrieve the list of batches to be deleted, if any
        $to_delete = optional_param_array('delete', array(), PARAM_INT);

        //delete each batch
        foreach($to_delete as $batch_id)
            $this->delete_batch($batch_id);

    }

    /**
     * Deletes a batch of paper copies, optionally including the question_usage_by_actvity objects contained within.
     *
     * @param int   $batch_id               The ID number of the batch to be deleted.
     * @param bool  $preserve_usages        If this is set, then the paper copies contained (quesiton_usage_by_activity objects) will not be deleted.
     * @param bool  $preserve_associated    If this is set, then any question_usage_by_activity objects also associated with a quiz attempt will not be deleted.
     *                                      If this is not set, any existing quiz attempts for this question_usage_by_activity will be deleted as well.
     */
    protected function delete_batch($batch_id, $preserve_usages = false, $preserve_associated = true)
    {
        global $DB;

        //get the given batch
        $batch = $this->get_batch_by_id($batch_id);    

        //ensure that this batch belongs to our quiz
        if($batch->quiz != $this->quiz->id)
            throw new coding_error('Internal consistency error: tried to delete another quiz\'s batch!');

        //get an array of paper copies which were part of this batch
        $usages = explode(',', $batch->usages); 

        //if we haven't been instructed to preserve usages, delete the given paper copy
        if(!$preserve_usages)
        {
            //delete each usage in the batch
            foreach($usages as $usage)
                $this->delete_usage($usage, $preserve_associated);
        }
        
        //if the batch has a pre-rendered copy
        if($batch->prerendered)
        {
            //get a reference to the Moodle file storage engine
            $fs = get_file_storage();

            //get a reference to the Moodle file object for this batch
            $file = $fs->get_file_by_hash($batch->prerendered);

            //then, delete it
            if($file)
                $file->delete();
        }

        //finally, delete the batch
        $DB->delete_records(self::BATCH_TABLE, array('id' => $batch_id));
    }


    /**
     * Disassociates a given paper copy (question_usage_by_activity) from a user by deleting the corresponding quiz attempt.
     * Leaves the question_usabe_by_activity object intact.
     *
     * @param int   $usage_id    The ID of the question_usage_by_activity to be disassociated.
     */
    protected function disassociate_usage($usage_id)
    {
        global $DB;
        //TODO: possibly re-open?

        //verify that the given record belongs to our quiz, before we delete it
        if(!$DB->record_exists('quiz_attempts', array('uniqueid' => $usage_id, 'quiz' => $this->quiz->id)))
            throw new coding_exception('Tried to disassociate an attempt ('.$usage_id.') from a quiz ('.$this->quiz->id.') that doesn\'t exist. (Internal consistency error?)');

        //delete the association
        $DB->delete_records('quiz_attempts', array('uniqueid' => $usage_id, 'quiz' => $this->quiz->id)); 
    }

    /**
     * Maintains the list of usages in each of the batches by removing any usages which have bene deleted, and deletes any empty batches. 
     */
    protected function maintain_batches()
    {
        global $DB;

        //get a record set of all batches belonging to this quiz
        $batches = $DB->get_recordset(self::BATCH_TABLE, array('quiz' => $this->quiz->id));

        //verify each batch individually
        foreach($batches as $batch)
        {
            //get a list of usages in the batch
            $usages = explode(',', $batch->usages);

            //remove any usages which do not exist in the database (possibly due to an associated quiz being deleted)
            $new_usages = array_filter($usages, function($id) use ($DB) { return is_numeric($id) && $DB->record_exists('question_usages', array('id' => $id)); } ); 

            //if no elements remain, delete the batch
            if(count($new_usages) == 0)
                $DB->delete_records(self::BATCH_TABLE, array('id' => $batch->id));

            //if we've removed elements, update the database
            elseif(count($new_usages) < count($usages))
                $DB->set_field(self::BATCH_TABLE, 'usages', implode(',', $new_usages), array('id' => $batch->id));

        }

        //close the recordset
        $batches->close();
    }

    /**
     * Handles the forced association of a given quiz object.
     */
    protected function handle_action_associate()
    {
        //get the batch / usage for which we are forcing an association
        $batch_id = required_param('batch', PARAM_INT);

        //and get the list of associations to be made, associations to be unmade, and deletions to perform
        $associations = optional_param_array('associate', array(), PARAM_INT);
        $disassociations = optional_param_array('disassociate', array(), PARAM_INT);
        $deletions = optional_param_array('delete', array(), PARAM_INT);

        //try to perform each of the desired associations
        foreach($associations as $usage_id => $user_id)
            if($user_id > 0)
                $this->set_manual_grade_association($usage_id, $user_id);

        //perform each of the desired diassociations
        foreach($disassociations as $usage_id => $selected)
            if($selected)
                $this->disassociate_usage($usage_id);

        //and delete the relevant usages
        foreach($deletions as $usage_id => $selected)
            if($selected)
                $this->delete_usage($usage_id);

    }

    /**
     * Returns true iff the a quiz attempt exists utilizing the paper copy with the given QUBA id.
     *
     * @param int   $usage_id   The QUBA of interest's ID.
     *
     * @return bool             Returns true iff the given QUBA is being used by a quiz_attempts.
     */
    protected function is_associated($usage_id)
    {
        global $DB;

        //return true if a given usage is associated with a quiz
        return $DB->record_exists('quiz_attempts', array('uniqueid' => $usage_id));
    }

    /**
     * Deletes a given paper copy question_usage_by_id; enforcing permissions and removing quiz_attempts, if applicable.
     * If preserve_associated is not set, and the paper copy QUBA is associated with a quiz attempt, the quiz attempt will be deleted.
     *
     * @param int   $usage_id               The ID of the QUBA to be deleted.
     * @param bool  $preserve_associated    If true, then any quiz with an associated quiz_attempt will not be deleted.
     *
     */
    protected function delete_usage($usage_id, $preserve_associated = false)
    {
        //require the user to be able to delete quizzes
        require_capability('mod/quiz:deleteattempts', $this->context);

        //if the usage is associated with a quiz object, remove the association before deleting
        if($this->is_associated($usage_id))
        {
            //if we've been instructed to preserve associated usages, then return without deleting the usage
            if($preserve_associated)
                return;

            //otherwise, disassociate the usage
            $this->disassociate_usage($usage_id);
        }

        //delete the usage
        question_engine::delete_questions_usage_by_activity($usage_id);
    }

    /**
     * Associates a given paper copy's QUBA with a user by creating a quiz_attempt, and marking all questions as requiring manual grading.
     * 
     * This is useful for giving out a paper quiz, and entering grades and feedback into Moodle without uploading the user's responses. 
     * If you'd like to upload user responses, consider using the "mass upload" method instead.
     *
     * @param int   $usage_id       The ID of the QUBA to be associated with a user and quiz attempt.
     * @param int   $user_id        The ID of the user for which the association is to be created.
     * @param bool  $finish_after   If true, all questions will be finished, and the created quiz attempt will be closed, afterwards.
     *
     */
    protected function set_manual_grade_association($usage_id, $user_id, $finish_after = false)
    {
        global $DB;

        //TODO: consistency checks- ensure usage belongs to batch, and batch belongs to this quiz

        //force all question in this usage to be manually graded
        $DB->set_field('question_attempts', 'behaviour', 'manualgraded', array('questionusageid' => $usage_id));

        //load the usage we're trying to associate
        $usage = question_engine::load_questions_usage_by_activity($usage_id);

        //get the question list for the usage
        $slots = $usage->get_slots();

        //for each quesiton in the paper copy
        foreach($slots as $slot) 
        {
            //create an instance of the question to be graded, and ask it what data it expects
            $question = $usage->get_question($slot);
            $expected_response  = $question->get_expected_data();

            //generate an "empty response" of the appropriate type, so we can trigger the "needs grading" state
            $empty_response = self::generate_empty_response($expected_response);

            //submit an empty answer, to mark this quesiton as "needs grading"
            $usage->process_action($slot, $empty_response);
        } 

        //wrap it with a new quiz attempt, and finish the quiz
        return $this->build_attempt_from_usage($usage, $user_id, $finish_after, true);
 
    }

    /**
     * Generates an empty response, to force a user's submission to require grading; 
     * used for questions in the manual behaviour question type.
     *
     * @param array     $expected_data  An associative array containing the expected response, as recieved from the get_expected_data() method of a question object.
     *
     * @return array    An array of innocuous response data, designed to trigger manual grading without appearing as a real user response. 
     */
    static function generate_empty_response($expected_data)
    {
        //for each expected field, generate an "empty" response, according to type
        foreach($expected_data as $field => &$data)
            $data = self::empty_parameter($data);

        //and return the data
        return $expected_data;

    }

    /**
     * Returns an innocuous parameter for the given response type, as would be included in the get_expected_data() array.
     *
     * @TODO: A better implemention of this should be written sometime in the future.
     * @TODO: We're operating on the assumption that every core question type accepts as '' as an "incomplete" response.
     */
    static function empty_parameter($param_type)
    {
        return '';
    }

    /**
     * Handles the viewing or editing of a batch, after a view/edit link has been clicked.
     */
    protected function handle_action_view_edit()
    {
        //get the batch with which we're currently working
        $batch_id = required_param('batch', PARAM_INT);  

        //get the batch object requested
        $batch = $this->get_batch_by_id($batch_id);
    
        //split the batch into usages
        $usages = explode(',',  $batch->usages);

        $mform = new quiz_papercopy_associate_users($this->quiz->id, $this->cm, $batch, $this->context, $usages);
        $mform->display();

    }

    /**
     * Handle the mass upload of attempts upon submission of the Mass Upload form.
     */
    protected function handle_action_upload()
    {
        //require the user to be able to grade quizzes
        require_capability('mod/quiz:grade', $this->context);

        //entry options
        $overwrite = optional_param('overwrite', 0, PARAM_INT);
        $allow_cross_user = optional_param('allowcrossuser', 0, PARAM_INT);

        //advanced paramters: 
        $error_if_not_first = optional_param('errorifnotfirst', false, PARAM_BOOL);
        $force_first = optional_param('forcefirst', false, PARAM_BOOL);
        $do_not_finish = optional_param('donotclose', false, PARAM_BOOL);

        //TODO: support multiple upload types, multiple delimiters, mapping, etc.

        //create a new Moodle Form, and use it to get the CSV data that was uploaded
        $mform = new quiz_papercopy_import_form($this->cm->id);
        $csv = $mform->get_file_content('gradedata');

        //parse the uploaded CSV file
        $response_sets = self::parse_scantron_csv($csv, true);

        //keep track of successes and failures
        $success = array();
        $errors = array();

        //parse each of the response sets
        foreach($response_sets as $set)
        {
            try
            {
                //attempt to grade the response
                $grade = $this->enter_scantron_responses($set, $overwrite, $allow_cross_user);
                $success[] = array(self::get_user_name($grade['user']), format_float($grade['grade'], 2));
            }
            catch(quiz_papercopy_could_not_identify_exception $e)
            {
                //if we failed to identify the user, add the event to our error log
                $errors[] = array($set['Student Name'].' / '.$set['ID'].' (Test '.$set['Special Codes'].')', get_string('couldnotidentify', 'quiz_papercopy'));
            }
            catch(quiz_papercopy_conflicting_users_exception $e)
            {
                //if we failed to identify the user's test, add the event to our error log
                $errors[] = array($set['Student Name'].' / '.$set['ID'].' (Test '.$set['Special Codes'].')', get_string('conflictinguser', 'quiz_papercopy'));
            }
            catch(quiz_papercopy_attempt_exists_exception $e)
            {
                //if we failed to identify the user's test, add the event to our error log
                $errors[] = array($set['Student Name'].' / '.$set['ID'].' (Test '.$set['Special Codes'].')', get_string('attemptexists', 'quiz_papercopy'));
            }
            catch(quiz_papercopy_invalid_usage_id_exception $e)
            {
                //if we failed to identify the user's test, add the event to our error log
                $errors[] = array($set['Student Name'].' / '.$set['ID'].' (Test '.$set['Special Codes'].')', get_string('invalidtest', 'quiz_papercopy'));
            }
            catch(quiz_papercopy_benign_row_exception $e)
            {
            }
        }

        //display the results
        $this->display_import_result($success, $errors);

        //add a continue link, and break
        echo html_writer::link($this->base_url(), get_string('continue', 'quiz_papercopy'));

    }


    /**
     * Handles the submission of the Create Copies form.
     */
    protected function handle_action_create()
    {
        global $DB;

        //get the required fields
        $copies = required_param('numcopies', PARAM_INT);
        $entry_mode = required_param('entrymode', PARAM_INT);
        $shuffle_mode = required_param('shufflemode', PARAM_INT);
        $fix_descriptions = required_param('fixinfos', PARAM_BOOL);
        $fix_first = required_param('fixfirst', PARAM_INT);
        $fix_last = required_param('fixlast', PARAM_INT);
        $include_key = optional_param('includekey', false, PARAM_BOOL);

        //TODO: handle multi-zip mode
        $delivery_mode = 0;

        //--create the actual quiz attempts (which are stored in the database, until we need to print them)

        //create an empty array which will contain the various printable copy "usage" objects
        $usages = array();

        //create the requested amount of paper copies
        $usages = $this->create_printable_copies($copies, $entry_mode, $shuffle_mode, $fix_descriptions, $fix_first, $fix_last);

        //create a new object, which stores information about the current batch
        $record = new stdClass();
        $record->usages = implode(',', $usages);
        $record->delivery_method = 1; //TODO
        $record->include_key = $include_key; 
        $record->quiz = $this->quiz->id;

        //and insert that information into the database
        $id = $DB->insert_record(self::BATCH_TABLE, $record);

        // Get a URL at which this paper copy can be viewed.
        $url = self::get_paper_copy_url(array('id' => $this->cm->id, 'batch' => $id, 'zip' => ($copies != 1)));

        //add a refresh tag, so the given URL will automatically download
        echo html_writer::empty_tag('meta', array('http-equiv' => 'Refresh', 'content' => '0;'.$url->out(false)));

        //and display the index
        $this->display_index();
    }

    /**
     * Returns a URL at which the given paper copy can be viewed.
     * 
     * @param array $params     A list of get-parameters which should be included in the URL.
     * @return moodle_url       The URL at which the paper copy can be viewed/downloaded.
     */
    public static function get_paper_copy_url($params = array()) {

        //build the URL to the paper copy PDF (or zip)
        return new moodle_url('/mod/quiz/report/papercopy/printable.php', $params);
    }


    /**
     * Returns a given user's name.
     */
    public static function get_user_name($user_id, $default = 'EMPTY')
    {
        global $DB;

        //get the user's data
        $user = $DB->get_record('user', array('id' => $user_id), 'lastname, firstname');

        //if no data was returned, return the default
        if(!$user)
            return $default;

        //and return their first/last name
        return fullname($user);
    }

    /**
     * Processes a set of Scantron-formatted responses, creating a quiz attempt, as though the user had entered these answers into Moodle directly.
     *
     * @param array   $set              An associative array of data read off of a Scantron form. Known to work for the scantron form 223127; likely works for others.
     * @param bool    $overwrite        If set, imported responses will be allowed to overwrite existsing quiz attempts with the same unique id (QUBA id).
     * @param bool    $allow_cross_user If set, allows a quiz attempt to move from one user to another (i.e. if the student had entered in the wrong ID number.)
     */
    protected function enter_scantron_responses($set, $overwrite = false, $allow_cross_user = false, $finish = true, $error_if_not_first = false, $force_first = false)
    {
        global $DB;

        //if no usage ID has been specified, throw an exception
        if(!array_key_exists('Special Codes', $set))
        {
            if(array_key_exists('Student Name', $set) || array_key_exists('ID', $set))
                throw new quiz_papercopy_invalid_usage_id_exception();
            else
                throw new quiz_papercopy_benign_row_exception();
        }

        //get the usage ID from the Special Codes field on the scantron
        $usage_id = intval($set['Special Codes']);

        //get the ID for the attempt that would be created
        $new_id = $this->user_id_from_scantron($set);

        //if we need this to be the first attempt, check for an existing attempt by this user at the current quiz
        if($error_if_not_first || $force_first)
        {
            //if an attempt exists with both this user_id and quiz ID
            $existing_attempt = $DB->get_record('quiz_attempts', array('userid' => $new_id, 'quiz' => $this->quiz->id));

            //if this isn't allowed to be a subsequent attempt, throw an exception
            if($error_if_not_first)
                throw new quiz_papercopy_not_first_attempt_when_required();

            //if we've been instructed to force this to be the first attempt, delete all prior attempts by the student
            elseif($force_first)
                $DB->delete_records('quiz_attempts', array('userid' => $new_id, 'quiz' => $this->quiz->id));
        }

        //check for any attempt that uses this usage
        $existing_record = $DB->get_record('quiz_attempts', array('uniqueid' => $usage_id), 'userid', IGNORE_MISSING);
        
        //if one exists, handle the overwrite cases
        if($existing_record)
        {
            //if we're trying to assign the same record to a different usage, and we haven't explicitly allowed cross-user overwrites, throw an exception
            if($new_id != $existing_record->userid && !$allow_cross_user)
                throw new quiz_papercopy_conflicting_users_exception();

            //if overwrite is enabled, remove the existing attempt
            if($overwrite)
                $DB->delete_records('quiz_attempts', array('uniqueid' => $usage_id));
            //otherwise, throw an exception
            else
                throw new quiz_papercopy_attempt_exists_exception();
        }

        try
        {
            //get a usage object from the Special Codes usage ID
            $usage = question_engine::load_questions_usage_by_activity($usage_id);
        }
        catch(coding_exception $e)
        {
            //if we couldn't load that usage, throw an "invalid usage id" error
            throw new quiz_papercopy_invalid_usage_id_exception();
        }

        //get an associative array, which indicates the order in which questions were 
        $slots = $usage->get_slots();

        //enter the student's answers for each of the questions
        foreach($slots as $slot)
            $usage->process_action($slot, array('answer' => $set['Question'.$slot] - 1));

        //set the attempt's owner to reflect the student who filled out the scantron
        $target_user = $this->user_id_from_scantron($set);

        //create a new attempt object, if requested, immediately close it, grading the attempt
        $attempt = $this->build_attempt_from_usage($usage, $target_user, $finish, true);

        //return the user's grade and id, on success
        return array('grade' => $attempt->sumgrades, 'user' => $attempt->userid);

    }

    /**
     * Attempts to get a Moodle user_id, given the information a user has provided.
     *
     * @param array   $set    An associative array of data read off of a Scantron form. Known to work for the scantron form 223127; likely works for others.
     * 
     * @return int    The moodle user ID, if it could be determined. Throws an exception if this could not be determined.
     */
    protected function user_id_from_scantron($set)
    {
        //get a list of enrolled users
        $users = get_enrolled_users($this->context);

        //for each enrolled user, try to match again the user's ID Number
        foreach($users as $user)
        {
            //if the given user matches the scantron's ID number, return that user's ID
            if(!empty($user->idnumber) && $user->idnumber == $set['ID'])
                return $user->id;
        }

        foreach($users as $user)
        {
            //if the given user's name matches the name entered in the scantron, return that user's ID
            if(self::names_match($set['Student Name'], $user->lastname, $user->firstname))
                return $user->id;
        }

        //if we haven't identified the user, throw an exception
        throw new quiz_papercopy_could_not_identify_exception();
    }

    /**
     * Determine if a Scantron name (FIRST, LAST, MI) matches a given Moodle-style first and last name.
     *
     * @param string    $student_name   The name which the student entered on their Scantron (or similar).
     * @param string    $last           The student's surname, in Moodle format.
     * @param string    $first          The student's first name, in Moodle format. Depending on the authentication method, this may contain the user's middle name.
     *
     * @return  bool    Returns true if and only if there's a _very_ high probability that the names match. 
     */
    static function names_match($student_name, $last, $first)
    {
        //remove all non-alphanumeric letters from the first/last name, and convert them all to uppercase, for comparison
        $student_name = strtoupper(preg_replace('#[^A-Za-z\s]#', '', $student_name));
        $last = strtoupper(preg_replace('#[^A-Za-z\s]#', '', $last));
        $first = strtoupper(preg_replace('#[^A-Za-z\s]#', '', $first));

        //split each of the names into words
        $student_name = explode(' ', $student_name);
        $last = explode(' ', $last);
        $first = explode(' ', $first);

        //if the student's middle name was included in both places, use it in the match
        if(count($student_name) == 3 && count($first) == 2)
            return $last[0] == $student_name[0] && $first[0] == $student_name[1] && $first[1] == $student_name[2];

        //otherwise, match the student without it
        else
            return $last[0] == $student_name[0] && $first[0] == $student_name[1];
    }

    /**
     * Creates a normal quiz attempt from a (typically paper copy) usage, so the student can view the results of a paper test
     * as though they had taken it on Moodle, including feedback. This also allows one to theoretically allow subsequent attempts
     * at the same quiz on Moodle, using options such as "each attempt buiilds on the last", building on the paper copies.
     *
     * @param question_usage_by_actvitity   $usage      The question_usage_by_activity object which composes the paper copy.
     * @param int                           $user_id    If provided, the attempt will be owned by the user with the given ID, instead of the current user.
     * @param bool                          $finished   If set, the attempt will finished and committed to the database as soon as it is created; this assumes the QUBA has already been populated with responses.
     * @param bool                          $commit     If set, the attempt will be committed to the database after creation. If $finished is set, the value of $commit will be ignored, and the row will be committed regardless.
     *
     * @return array      Returns the newly created attempt's raw data. (In other words, does not return a quiz_attempt object.)
     */
    protected function build_attempt_from_usage($usage, $user_id = null, $finished = false, $commit = false, $attempt_number = null)
    {
        global $DB, $USER;

        //get the current time
        $time_now = time();

        //start a new attempt
        $attempt = new stdClass();
        $attempt->quiz = $this->quiz->id;
        $attempt->preview = 0;
        $attempt->timestart = $time_now;
        $attempt->timefinish = 0;
        $attempt->timemodified = $time_now;

        //associate the attempt with the usage
        $attempt->uniqueid = $usage->get_id();

        //and set the attempt's owner, if specified
        if($user_id !== null)
            $attempt->userid = $user_id;
        //otherwise, use the current user
        else
            $attempt->userid = $USER->id;

        //if no attempt number was specified, automatically detect one
        if($attempt_number === null)
        {
            //determine the maximum attempt value for that user/quiz combo
            $max_attempt = $DB->get_records_sql('SELECT max("attempt") AS max FROM {quiz_attempts} WHERE "userid" = ? AND "quiz" = ?', array($user_id, $this->quiz->id));
            $max_attempt = reset($max_attempt);

            //if no attempts exist, let this be the first attempt
            if($max_attempt->max == null)
                $attempt_number = 1;

            //otherwise, use the next available attempt number
            else
                $attempt_number = $max_attempt->max + 1;
        }

        //set the attempt number
        $attempt->attempt = $attempt_number;

        //build the attempt layout 
        $attempt->layout = implode(',', $usage->get_slots()); 

        //if requested, commit the attempt to the database
        if($commit || $finished)
        {
            //and use it to save the usage and attempt
            question_engine::save_questions_usage_by_activity($usage);
            $attempt->id = $DB->insert_record('quiz_attempts', $attempt);
        }

        //if requested, finish the attempt immediately
        if($finished)
        {
            $raw_course = $DB->get_record('course', array('id' => $this->course->id));

            //wrap the attempt data in an quiz_attempt object, and ask it to finish
            $attempt_object = new quiz_attempt($attempt, $this->quiz, $this->cm, $this->course, true);
            $attempt_object->finish_attempt($time_now);
        }


        //return the attempt object
        return $attempt; 
    }


    /** 
     * Parses a Scantron-generated CSV file into an array of associative response arrays.
     * Known to work with at least Scantron form 223127.
     *
     * @param  string   $csv_text       The raw text of the CSV file, including headers.
     * @param  bool     $omit_sparse    If true, any reponse which was ommitted on the scantron form (value -1) will not appear in the associative array.
     *
     * @return array    An array of multiple associative response arrays. 
     */
    static function parse_scantron_csv($csv_text, $omit_sparse = false)
    {
        //break the CSV file into lines
        $lines = explode("\n", $csv_text);

        //parse the file into raw CSV data
        foreach($lines as $num => $line)
            $raw_data[$num] = str_getcsv($line);

        //array of data rows
        $data = array();

        //get an easy reference to the header row
        $header =& $raw_data[0];

        //process each of the data columns, skipping the first row, as it contains the headers
        for($row = 1; $row < count($raw_data); ++$row)
        {
            //replace each column number with its name
            foreach($raw_data[$row] as $column => $content)
                if(!$omit_sparse || $content != -1)
                    $data[$row][$header[$column]] = trim($content);
        }
                
        //return the new array of associative response arrays
        return $data;
    }

    /**
     * Creates multiple printable copies of the given quiz.
     *
     * @return  An array of question usage IDs, which indicate the paper copies.
     * 
     */
    protected function create_printable_copies($copies, $entry_mode, $shuffle_mode, $fix_descriptions, $fix_first, $fix_last)
    {
        //create an empty array of copies
        $usages = array();

        //and populate it with the given amount of new printable copies
        for($x = 0; $x < $copies; $x++)
            $usages[] = $this->create_printable_copy($shuffle_mode, $fix_descriptions, $fix_first, $fix_last);
      
        //return the array of copy IDs
        return $usages;
    }


    /**
     * Creates a single printable copy of the given quiz.
     *
     * @return  The ID of the created printable question usage.
     *
     */
    protected function create_printable_copy($shuffle_mode = quiz_papercopy_shuffle_modes::MODE_SHUFFLE_IGNORE_PAGES, $fix_descriptions = false, $fix_first = false, $fix_last = false)
    {
        //get a reference to the current user
        global $USER;

        //create a new usage object, which will allow us to create a psuedoquiz in the same context as the online quiz
        $usage = question_engine::make_questions_usage_by_activity('mod_quiz', $this->context);

        //and set the grading mode to "deferred feedback", the standard for paper quizzes
        //this makes sense, since our paradigm is duriven by the idea that feedback is only offered once a paper quiz has been uploaded/graded
        $usage->set_preferred_behaviour('deferredfeedback');

        //get an array of questions in the current quiz
        $quiz_questions = $this->quizobj->get_questions();

        //randomize the question order, as requested
        $quiz_questions = self::shuffle_questions($quiz_questions, $this->quiz->questions, $shuffle_mode, $fix_descriptions, $fix_first, $fix_last);

        //for each question in our online quiz
        foreach($quiz_questions as $slot => $qdata)
        {
            $question = question_bank::make_question($qdata);

            //add the new question instance to our new printable copy, keeping the maximum grade from the quiz
            //TODO: respect maximum marks
            $usage->add_question($question);
        }

        //initialize each of the questions
        $usage->start_all_questions();

        //save the usage to the database
        question_engine::save_questions_usage_by_activity($usage);

        //return the ID of the newly created questions usage
        return $usage->get_id();
    }

    /**
     * Shuffles the given question set according to the rules specified by the user. See {@link quiz_papercopy_shuffle_modes}. 
     *
     * @param array $questions          An associative array, whose keys represent slots, and whose values represent quesiton data.
     * @param array $pagination         An array of Moodle-formatted question order and pagination information. Page boundaries are denoted by null (0) QIDs.
     * @param int   $shuffle_mode       An integer from the enumeration quiz_papercopy_shuffle_modes which determines the method by which the questions are shuffled.
     * @param bool  $fix_descriptions   If true, description elements which are the first element on a page will be fixed in place.
     *
     * @return array    An associative array of questions to be included in the quiz.
     */
    static function shuffle_questions($questions, $pagination = array(), $shuffle_mode = quiz_papercopy_shuffle_modes::MODE_SHUFFLE_IGNORE_PAGES, $fix_descriptions = false, $fix_first = false, $fix_last = false)
    {

        //break the questions into a list of pages
        $pages = self::split_questions_into_pages($questions, explode(',', $pagination)); 

        //and shuffle according to the shuffle mode
        switch($shuffle_mode)
        {
            //simple case: no shuffling
            case quiz_papercopy_shuffle_modes::MODE_NOSHUFFLE:
                return $questions;

            //second simplest case: shuffle ignoring pages altogether
            case quiz_papercopy_shuffle_modes::MODE_SHUFFLE_IGNORE_PAGES:
                return self::shuffle_assoc($questions);

            //shuffle both pages, and the questions within them
            case quiz_papercopy_shuffle_modes::MODE_SHUFFLE_ALL:

                //shuffle the pages, fixing the first and/or last pages, if desired
                $pages = self::shuffle_assoc($pages, $fix_first, $fix_last);

            //shuffle within pages, leaving pages in their original order
            case quiz_papercopy_shuffle_modes::MODE_SHUFFLE_WITHIN_PAGE:


                //for each page in the quiz, shuffle the page's contents:
                foreach($pages as $num => $page)
                {
                    //get the first question of the quiz
                    $first = reset($page);

                    //determine if we should fix the first element- i.e, if the first element is a description, and fix_descriptions is set
                    $fix_first_question = $fix_descriptions && ($first->qtype == 'description');

                    //and shuffle the page's contents
                    $pages[$num] = self::shuffle_assoc($page, $fix_first_question);
                }

                //and return a list of questions, now shuffled by page
                return self::merge_pages($pages);

            //shuffle pages, but leave their contents unshuffled
            case quiz_papercopy_shuffle_modes::MODE_SHUFFLE_PAGES:

                //shuffle the pages, fixing the first and/or last pages, if desired
                $pages = self::shuffle_assoc($pages, $fix_first, $fix_last);

                //and return a list of questions, now shuffled by page
                return self::merge_pages($pages);
        }
    }

    /**
     * Splits an array of question data into several smaller arrays according to the quiz'z pagination.
     *
     * @param array $questions          An associative array, whose keys represent slots, and whose values represent quesiton data.
     * @param array $pagination         An array of Moodle-formatted question order and pagination information. Page boundaries are denoted by null (0) QIDs.
     * 
     * @return array                    An array of associative question arrays, sorted by page. 
     */
    static function split_questions_into_pages($questions, $pagination)
    {
        //create a new array which will store the given pages
        $pages = array();

        //start off by filling in page 0
        $current_page = 0;

        //for each question, sorted by pagination
        foreach($pagination as $item)
        {
            //if the item is a page-break, increment the page counter
            if($item == 0)
                ++$current_page;

            //otherwise, add the question with the corresponding ID to the appropriate page
            else
            {
                $slot = self::array_search_callback($questions, function($needle) use ($item) { return $item == $needle->id; });
                $pages[$current_page][$slot] = $questions[$slot]; 
            }
        }

        return $pages;
    }

    /**
     * Merges a large collection of pages into a single list of question objects.
     *
     * @param array     $pages  An array of associative arrays, which represent pages in a quiz layout.
     *
     * @return array    A single array of question numbers, which indicates the order in which they should be added to a quiz.
     */
    static function merge_pages($pages)
    {
        //merge all of the pages into a single, large array of questions
        return array_reduce($pages, 'array_merge', array());
    }


    /**
     * Finds the first element of an array for which the given callback evaluates to true, and returns its key.
     *
     * @param array $haystack     The array to search through.
     * @param callback $callback  A callback which identifies the desired element (the 'needle'.)
     *
     * @return                    The first key for which the given callback returns true.
     */
    static function array_search_callback($haystack, $callback)
    {
        //for each element in our array, if the callback evaluates to true, return the key
        foreach($haystack as $key => $value)
            if($callback($value))
                return $key;
    }

    /**
     * Shuffle the order of elements in an associative array, maintaining key-value relations.
     *
     * @param array $list       The array to be shuffled, which will not be modified.
     * @param bool  $fix_first  If set, the first element will be fixed in place, and remain first after shuffling.
     * @param bool  $fix_last   If set, the last element will be fixed in place, and remain last after shuffling.
     *
     * @param array An array containing the same key-value relations as $list, but with the elements shuffled randomly.
     */
    static function shuffle_assoc($list, $fix_first = false, $fix_last = false) 
    {
        //if we're preforming a useless shuffle, just return the list
        if(count($list) < 2 || count($list) == 2 && ($fix_first || $fix_last) || count($list) && $fix_first && $fix_last)
            return $list;

        //get a list of array keys
        $keys = array_keys($list);

        //if fix_first is set, pull the first element out of the array, for later
        //and do the same for the last element, if fix_last is set
        if($fix_first)
            $first = array_shift($keys);
        if($fix_last)
            $last = array_pop($keys);

        //shuffle the elements of the array
        shuffle($keys); 

        //if we took the first or last element off, put it back
        if($fix_first)
            array_unshift($first);
        if($fix_last)
            array_push($last);

        //create a new, array to be filled with randomized values
        $random = array(); 

        //add each element to the new array in the order determined by the 
        foreach ($keys as $key) 
            $random[$key] = $list[$key]; 

        //return the new randomized list    
        return $random; 
    } 

    /**
     * Returns a new quiz object for this quiz.
     *
     * @return  A new quiz object for the quiz this report represents.
     */
    private function get_quiz_object()
    {
        return new quiz($this->quiz, $this->cm, $this->course);
    }

    /**
     * Get the URL of the front page of the report.
     *
     * @param $includeauto if not given, use the current setting, otherwise, force a paricular value of includeauto in the URL.
     *
     * @return string the URL.
     */
    protected function base_url() 
    {
        return new moodle_url('/mod/quiz/report.php', array('id' => $this->cm->id, 'mode' => 'papercopy'));
    }

    /**
     * Displays two tables, as necessary, indicating the result of an import operation.
     *
     * @param array $successes  An array summarizing the result of a successful import.
     * @param array $failures   An array summarizing the reason for a failed import.
     */
    protected function display_import_result($successes, $failures)
    {
   
        //if nothing happened, indicate so
        if(!empty($failure) && !empty($success))
            echo get_string('nooperation', 'quiz_papercopy');

        //if there were failures
        if(!empty($failures))
        {
            //header
            echo html_writer::tag('strong', get_string('failuresoccurred', 'quiz_papercopy'));

            //create a new table
            $table = new html_table();
            $table->class = 'generaltable'; //TODO: make more dramatic?
            $table->id = 'errors';
            $table->width = '97%';

            //headers
            $table->head[] = get_string('scantroninfo', 'quiz_papercopy');
            $table->head[] =  get_string('errordescription', 'quiz_papercopy');

            //and list the failures
            $table->data = $failures;

            //display the table
            echo html_writer::table($table);
        }

        if(!empty($successes))
        {
            //header
            echo html_writer::tag('strong', get_string('successesoccurred', 'quiz_papercopy', count($successes))); 
    
            //create a new table
            $table = new html_table();
            $table->class = 'generaltable'; //TODO: make more dramatic?
            $table->id = 'successes';
            $table->width = '97%';

            //headers
            $table->head[] = get_string('username', 'quiz_papercopy'); 
            $table->head[] = get_string('rawgrade', 'quiz_papercopy');
 
            //and list the successes
            $table->data = $successes;

            //display the table
            echo html_writer::table($table);

        }


    }

    /**
     * Returns an array of all paper copy batches associated with this quiz.
     *
     * @return  An array of all paper copy batches associated with this quiz.
     */
    protected function get_batches()
    {
        global $DB;

        //get the appropriate batches from the database
        return $DB->get_records(self::BATCH_TABLE, array('quiz' => $this->quiz->id));
    }

    /**
     * Returns the batch database row with the given $batch_id.
     * The batch must belong to the current quiz.
     *
     * @param int   $batch_id   The ID of the paper copy batch to retrieve.
     * 
     * @return stdClass The batch object, as retrieved from the database.
     */
    protected function get_batch_by_id($batch_id)
    {
        global $DB;

        //get the batch with the correct ID from the database
        return $DB->get_record(self::BATCH_TABLE, array('quiz' => $this->quiz->id, 'id' => $batch_id));
    }

    /**
     * Display a table listing each of the paper copy batches, and linking to opeations which can be performed on them.
     */
    protected function display_batches()
    {
        //work with the database
        global $DB, $USER;

        //get all batches which correspond to the given quiz
        $batches = $this->get_batches();

        //create a new table object, which will display the batches
        $table = new html_table();
        $table->class = 'generaltable';
        $table->id = 'batches';
        $table->width = '97%';

        //add headers to the table
        $table->head[] = '';
        $table->head[] = get_string('createdon', 'quiz_papercopy');
        $table->head[] = get_string('batchcount', 'quiz_papercopy');
        $table->head[] = get_string('assignusers', 'quiz_papercopy');
        $table->head[] = get_string('answerkey_s', 'quiz_papercopy');
        $table->head[] = get_string('download', 'quiz_papercopy');

        //center all elements
        for($i = 0; $i < count($table->head); ++$i)
            $table->align[] = 'center';

        //start off with an empty table
        $table->data = array();

        //set the table data
        foreach($batches as $batch)
        {
           //count the amount of usages in the batch
           $usage_count = self::usage_count($batch);

           //compose the URL for editing a given batch
           $edit_url = $this->base_url();
           $edit_url->params(array('action' => 'viewedit', 'batch' => (string)$batch->id));

           //and compose the answer key display URL
           $answer_url = new moodle_url('/mod/quiz/report/papercopy/printable.php', array('id' => $this->cm->id, 'batch' => $batch->id, 'mode' => 'key' ));

           //compose the download URL
           $download_url = new moodle_url('/mod/quiz/report/papercopy/printable.php', array('id' => $this->cm->id, 'batch' => $batch->id));
           $download_with_keys_url = new moodle_url('/mod/quiz/report/papercopy/printable.php', array('id' => $this->cm->id, 'batch' => $batch->id, 'mode' => 'withkey' ));

           //and add a table row
           $table->data[] = 
                array
                (
                    html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => 'delete['.$batch->id.']', 'value' => $batch->id )),
                    '',//$batch->created, //TODO
                    html_writer::link($edit_url, $usage_count.' '.get_string($usage_count == 1 ? 'copy' : 'copies', 'quiz_papercopy')),
                    html_writer::link($edit_url, get_string('assignusers', 'quiz_papercopy')), 
                    html_writer::link($answer_url, get_string('answerkey', 'quiz_papercopy')), 
                    html_writer::link($download_url, get_string('download', 'quiz_papercopy')).' ('.html_writer::link($download_with_keys_url, get_string('withanswerkey', 'quiz_papercopy')).')',
                );
        }

        $url = new moodle_url($this->base_url());
        $url->params(array('action' => 'deletebatches'));

        //start a new form
        $output = html_writer::start_tag('form', array('action' => $url, 'method' => 'post'));

        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => $USER->sesskey));

        //display the table
        $output .= html_writer::table($table);

        //add a "delete" button
        $output .= html_writer::start_tag('center');
        $confirm_script = 'return confirm("'.addslashes_js(get_string('confirmdelete', 'quiz_papercopy')).'");';
        $output .= html_writer::empty_tag('input', array('type' => 'submit', 'value' => get_string('deletebatches', 'quiz_papercopy'), 'onClick' => $confirm_script));
        $output .= html_writer::start_tag('center');
        
        //and end the form
        $output .= html_writer::end_tag('form');

        //return the output
        return $output;
    }

    /**
     * Determine the amount of usages ("paper copies") in a given batch, given its database entry.
     *
     * @param array $batch_row    A database row containing information about a batch of paper copies.
     *
     * @return int                The amount of paper copies in the batch.
     */
    static function usage_count($batch_row)
    {
        return count(explode(',', $batch_row->usages));
    }

    /**
     * Displays the core ("index") page for the Paper Copy report.
     */
    protected function display_index() 
    {
        global $OUTPUT;

        //calculate the class's enrollment; this determines the default number of papercopies to create
        $enrollment = count_enrolled_users($this->context);

        //output the header for "mass upload grades"
        echo $OUTPUT->heading(get_string('massuploadattempts', 'quiz_papercopy'));
        $mform = new quiz_papercopy_import_form($this->cm->id);
        $mform->display();

        //output the header for "create paper copies"
        echo $OUTPUT->heading(get_string('createcopies', 'quiz_papercopy'));
        $mform = new quiz_papercopy_create_form($this->cm->id, $enrollment);     
        $mform->display();
    
        //output the batch display view
        echo $OUTPUT->heading(get_string('modifyattempts', 'quiz_papercopy'));
        echo html_writer::empty_tag('br');
        echo $this->display_batches();

        return true;

    }
}
           
