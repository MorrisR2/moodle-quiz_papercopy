<?php

class ResponseSet {

    private $attempts = array();

    protected $quiz;

    //TODO
    public function __construct($quiz) {
        $this->quiz = $quiz;
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

        $raw_data = array();

        //parse the file into raw CSV data
        foreach($lines as $line) {

            //Skip empty lines. 
            if(empty($line)) {
                continue;
            }

            $raw_data[] = str_getcsv($line);
        }

        //array of data rows
        $data = array();

        //Extract the CSV headers
        $header = array_shift($raw_data);

        //Strip all spaces from the column headers.
        foreach($header as &$column_name) {
            $column_name = trim($column_name);
        }

        //process each of the CSV entries
        foreach($raw_data as $index => $row)
        {
            //replace each column number with its name
            foreach($row as $column => $content) {
                if(!$omit_sparse || $content != -1) {

                    // Get the column's name.
                    $colname = ($header[$column]);
                
                    // And set the row's value appropriately.
                    $data[$index][$colname] = trim($content);
                }
            }
        }
                
        //return the new array of associative response arrays
        return $data;
    }

    /**
     * Associates an existing _paper_ quiz attempt (which consists of a Usage object) with a user, 
     * creating a new Moodle quiz attempt in the process.
     * 
     * @param int $usage_id The QUBA id (uniqueid) for the paper copy quiz attempt to be associated.
     * @param int $user_id The user who should recieve the quiz attempt.
     * @param bool $commit Iff true, the new attempt will be committed to the database.
     * @return void
     */
    protected function associate_attempt_with_user($usage_id, $user_id, $commit = true, $overwrite = false, $allow_cross_user = false, $error_if_not_first = false, $force_first = false) {

        global $DB;

        //if we need this to be the first attempt, check for an existing attempt by this user at the current quiz
        if($error_if_not_first || $force_first)
        {
            //if an attempt exists with both this user_id and quiz ID
            $existing_attempt = $DB->get_record('quiz_attempts', array('userid' => $user_id, 'quiz' => $this->quiz->get_quizid()));

            //if this isn't allowed to be a subsequent attempt, throw an exception
            if($error_if_not_first)
                throw new quiz_papercopy_not_first_attempt_when_required();

            //if we've been instructed to force this to be the first attempt, delete all prior attempts by the student
            elseif($force_first)
                $DB->delete_records('quiz_attempts', array('userid' => $user_id, 'quiz' => $this->quiz->get_quizid()));
        }

        //check for any attempt that uses this usage
        $existing_record = $DB->get_record('quiz_attempts', array('uniqueid' => $usage_id), 'userid', IGNORE_MISSING);
        
        //if one exists, handle the overwrite cases
        if($existing_record)
        {
            //if we're trying to assign the same record to a different usage, and we haven't explicitly allowed cross-user overwrites, throw an exception
            if($user_id != $existing_record->userid && !$allow_cross_user)
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

        //create a new attempt object
        $attempt = quiz_synchronization::build_attempt_from_usage($usage, $this->quiz, $user_id, false, $commit);

        //and return the newly created attempt
        return $attempt;
    }

}

class ScannedResponseSet extends ResponseSet {

    private $images;
    private $associations;
    private $context;
    private $usages;

    /**
     * Creates a new Scanned Batch object, which helps to process scanned paper copies.
     * 
     * @param array $associations 
     * @param array $attachments 
     * @return void
     */
    public function __construct(array $associations, array $images, quiz $quiz_object) {
        $this->associations = $associations;
        $this->images = $images;
        $this->usages = array();

        parent::__construct($quiz_object);
    }


    /**
     * Creates a new ScannedResponseSet from an uploaded set of associations and image attachments.
     * 
     * @param string $data The contents of an association-style CSV; see the documentation for more information.
     *                     TODO: Replace with array of associations?
     * @param int $attachments The draftitemid for a recently uploaded set of attachments.
     * @return ScannedRecordSet The resultant ScannedRecordSet object.
     */
    public static function create_from_uploads($data, $attachments, $quiz_object) {

        global $USER;

        //parse the uploaded CSV file, yielding a list of associations to be made
        $csv_associations = self::parse_scantron_csv($data, true);

        // Convert the CSV-style associations into a mapping of usage => user
        $associations = array();
        foreach($csv_associations as $record) {

            // Get the user for the given attempt...
            $user = self::get_user_by_username(trim($record['ID']), 'id');

            // Get the paper copy ID for the given attempt...
            $usage_id = intval(trim($record['Test']));

            // And add the pair to the mapping.
            $associations[$usage_id] = $user;
        }


        // Get a reference to the Moodle file storage engine.
        $fs = get_file_storage();

        // And get a reference to the context in which files are currently being stored.
        $context = context_user::instance($USER->id);

        // Get a reference to the attachments uploaded by the user.
        $attachments = $fs->get_area_files($context->id, 'user', 'draft', $attachments, 'id');

        // Return a new ScannedResponseSet
        return new self($associations, self::images_from_stored_files($attachments), $quiz_object);

    }

    protected function get_usage($usage_id) {

        // If the given usage has not yet been loaded into memory, do so...
        if(empty($this->usages[$usage_id])) {
            $this->usages[$usage_id] = question_engine::load_questions_usage_by_activity($usage_id);
        }

        // ...and return the (cached) value of the requested usage.
        return $this->usages[$usage_id];
    }

    public function enter_scanned_images($finish = true) {

        global $DB;

        $modified_usages = array();
        $errors = array();
        $successes = array();

        // Enter each of the images into the relevant attempt objects...
        foreach($this->images as $image) {
            
            //If we've previously encountered an error related to the given QUBA, 
            if(isset($this->errors[$image->quba_id])) {
                continue;
            }

            try 
            {
                set_time_limit(30);

                //Attempt to process the given image.
                $modified = $this->enter_scanned_image($image); 

                //If we didn't process the image correctly, raise an exception.
                //TODO: message
                if(!$modified) {
                    print_object($image);
                    throw new quiz_papercopy_invalid_usage_id_exception();
                }

                // Add the usage to our list of modified arrays.
                $modified_usages[$image->quba_id] = $modified;
            }
            catch(quiz_papercopy_could_not_identify_exception $e) {

                //Record the error message...
                $errors[$image->quba_id] = array($image->question_attempt.' (Test '.$image->quba_id.')', get_string('couldnotidentify', 'quiz_papercopy'));

                //And ensure we don't save the QUBA to the database.
                unset($modified_usages[$image->quba_id]);                
            }
            catch(quiz_papercopy_invalid_usage_id_exception $e)  {

                //Record the error message...
                $errors[$image->quba_id] = array($image->question_attempt.' (Test '.$image->quba_id.')', get_string('couldnotidentify', 'quiz_papercopy'));

                //And ensure we don't save the QUBA to the database.
                unset($modified_usages[$image->quba_id]);                
            }

            //FIXME: throw exception if not modified

        }

        // Save each of the quizzes:
        foreach($modified_usages as $usage) {

            // 1) Create a Quiz Attempt, associating the paper copy with the given user.
            $usage_id = $usage->get_id();
            $this->attempts[$usage_id] = quiz_synchronization::build_attempt_from_usage($usage, $this->quiz, $this->associations[$usage_id]->id, $finish, true);

            // 2) Add a record of the success to the sucess records
            $user = $this->associations[$usage_id];
            $successes[] = array(quiz_papercopy_report::get_user_name($user->id), format_float($usage->get_total_mark(), 2));
        }

        //Return the list of successes and errors, for reporting.
        return array($successes, $errors);

    }

    public function enter_scanned_image($image) {

        global $USER;


        // Get a handle on the global file storage engine.
        $fs = get_file_storage();

        // Get the usage that pertains to the given image.
        $usage = $this->get_usage($image->quba_id);

        //FIXME add message
        if(empty($this->associations[$image->quba_id])) {
            throw new quiz_papercopy_could_not_identify_exception();
        }
        
        // Get the user object for the student who owns this attempt.
        $user = $this->associations[$image->quba_id];

        //FIXME add message
        if(empty($user)) {
            throw new quiz_papercopy_could_not_identify_exception();
        }

        // And figure out the context in which they will upload files.
        $user_uploads_context = context_user::instance($USER->id);

        // Create a new draft record for the given item, in the same format as would have been
        // created had the user uploaded the file him/herself.
        $new_record = array(
            'contextid' => $user_uploads_context->id,
            'component' => 'user',
            'filearea' => 'draft',
            'filename' => 'Response_'.uniqid().'.jpg',
            'filepath' => '/',
            'itemid' => $image->question_attempt
        );
        //$file = $fs->create_file_from_storedfile($new_record, $image->file); 
        $file = $fs->create_file_from_string($new_record, $image->file->get_content());

        //Search each of the possible slots for the given question attempt...
        foreach($usage->get_slots() as $slot) {

            // Once we've found it,
            if($usage->get_question_attempt($slot)->get_database_id() == $image->question_attempt) {

                // Create a new "remote" file saver, which allows the submitter to be different than the respondant.
                $file_saver = new question_file_saver($file->get_itemid(), 'question', 'response_attachments');

                // Pass it the newly created file.
                $usage->process_action($slot, array('answer' => get_string('scanattached', 'quiz_papercopy'), 'answerformat' => 1, 'attachments' => $file_saver));

                // Finish the question attempt
                $usage->finish_question($slot);

                // If a grade was provided for the image, manually grade the finished question.
                if($image->grade !== null) {

                    // Determine the user's grade, weighted according to the question's weight.
                    $grade = floatval($image->grade) / 10.0 * $usage->get_question_max_mark($slot);

                    // And use it to manually grade the question.
                    $usage->manual_grade($slot, get_string('autograde', 'quiz_papercopy'), $grade);
                }

                return $usage;
            }
        }

        return false;

    }

    /**
     * Creates a collection of images from an array of stored files; extracing the meta-data from the stored filenames.
     * 
     * @param array $files An array of stored_files to be parsed into an array of image objects.
     * @return array An array of stdClass image objects.
     */
    protected static function images_from_stored_files($files) {

        $images = array();

        // Sort each of the sorted files by its filename, which _should_ contain the Usage ID,
        // Question ID, and Question Attempt ID; and potentially a grade.
        foreach($files as $file) {

            // If the filename wasn't in the correct format...
            if(!preg_match("/^U(\d+)\_Q(\d+)\_A(\d+)\_(?:G(\d+)\_)?P\d+\./i", $file->get_filename(), $matches)) {

                //... add it a list of bad files.
                $bad_files[] = $file->get_filename();

                //... and move on to the next file.
                continue;
            }

            // Create a new Image object from the captured data.
            $image = new stdClass;
            $image->quba_id = intval(trim($matches[1]));
            $image->question = intval(trim($matches[2]));
            $image->question_attempt = intval($matches[3]);
            $image->grade = (trim($matches[4]) == '') ? null : intval($matches[4]);
            $image->file = $file;

            // Add the image to our collection.
            $images[] = $image; 

        } 

        // Return the newly-created set of images.
        return $images;
    
    }

    /**
     * Returns a user record for the user with the given username.
     * 
     * @param string $username The username with which to query.
     * @param string $fields The fields of the user object which should be populated from the data
     * @return void
     */
    protected static function get_user_by_username($username, $fields=null) {
        global $DB;
        return $DB->get_record('user', array('username' => $username), $fields);
    }



}
