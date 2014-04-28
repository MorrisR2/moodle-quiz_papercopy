<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/question/engine/lib.php');
require_once($CFG->dirroot.'/mod/quiz/accessmanager.php');
require_once($CFG->dirroot.'/mod/quiz/attemptlib.php');
require_once($CFG->dirroot.'/theme/pdf/pdf_renderers/core_pdf_renderer.php');
include_once($CFG->dirroot . '/lib/htmlpurifier/HTMLPurifier.safe-includes.php');
include_once($CFG->dirroot .'/mod/quiz/report/papercopy/lib/zipstream.php');

/**
 * Virtual "enum" class, which specifies how a 
 * 
 * @package 
 * @version $id$
 * @copyright 2011, 2012 Binghamton University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
abstract class quiz_papercopy_batch_mode  {
    
    /**
     *  
     */
    const NORMAL = '';

    /**
     *  
     */
    const WITH_KEY = 'withkey';

    /**
     *  
     */
    const KEY_ONLY = 'key';


}


class question_display_options_pdf extends question_display_options
{
    /**
     * Creates a new question_display_options objects, then overrides the defaults to reflect
     * values appropriate for PDF generation.
     */
    public function __construct()
    {
        //hide all grading information 
        //FIXME respect usage / etc, information, and possibly display max marks?
        $this->marks = question_display_options::HIDDEN;
        $this->feedback = question_display_options::HIDDEN;
        $this->generalfeedback = question_display_options::HIDDEN;
        $this->correctness = question_display_options::HIDDEN;
        $this->flags = question_display_options::HIDDEN;
        $this->numpartscorrect = question_display_options::HIDDEN;
        $this->manualcomment = question_display_options::HIDDEN;
        $this->manualcommentlink = question_display_options::HIDDEN;
        $this->quesitonreviewlink = question_display_options::HIDDEN;
        $this->history= question_display_options::HIDDEN;
        $this->rightanswer= question_display_options::HIDDEN;
        //$this->= question_display_options::HIDDEN;
    }
}

/**
 * Instance of the ZipStream class which is compatible with 
 */
class CompatibleZipStream extends ZipStream
{

    public function __construct($name, $opts = array())
    {
        parent::__construct($name, $opts);
    }


    /**
     * Wrapper for add_file with the same signature as PHP's ZipArchive.
     */
    public function addFromString($name, $contents)
    {
        $this->add_file($name, $contents, array('method' => 'deflate'));
        flush();
        ob_flush();
    }

    /**
     * Wrapper for finish() with the same signature as PHP's close().
     */
    public function close()
    {
        return $this->finish();
    }
}

class printable_copy_helper
{
    protected $quiz;
    protected $context;
    protected $course;    

    
    /**
     *  The minimum amount of time which should elapse between "detached" pre-renders, in seconds.
     *  
     *  If a user has not requested a pre-render in the last MIN_TIME_BETWEEN_PRERENDERS seconds, then they can disconnect from the server
     *  and a pre-render will continue in the background.
     *
     */
    const MIN_TIME_BETWEEN_PRERENDERS = 600; // 10 minutes

    public function __construct($quiz, $context = null, $course = null)
    {
        $this->quiz = $quiz;
        $this->context= $context;
        $this->course = $course;
    }

    /**
     * Returns the name of the table which contains information regarding
     * batches of paper copies.
     * 
     * @return string   The table's name, without prefix.
     */
    public static function get_batch_table() {
        return 'quiz_papercopy_batches';
    }

    public static function print_attempt($attempt_id)
    {
        //create a new attempt object from the given attempt ID
        $attempt = quiz_attempt::create($attempt_id);

        //get the coursemodule for the given attempt
        $cm = $attempt->get_cm();

        //and ensure the user can access this context
        require_login($attempt->get_course(), false, $cm);

       //get the quiz object from the attempt
        $quiz = $attempt->get_quiz();

        //if the quiz doesn't allow printable PDFs, throw an error 
        if(!$quiz->allowprint)
            print_error('nopermissiontoshow');

        //set up the page for PDF rendering
        self::set_up_pdf();

        //and get the unique ID from the attempt
        $quba_id = $attempt->get_uniqueid();

        //get the current context from the coursemodule
        $context = context_module::instance($cm->id);

        //create a new print helper
        $print_helper = new self($quiz, $context);

        //and use it to print the given quiz
        $print_helper->print_quba($quba_id, quiz_papercopy_batch_mode::NORMAL, false, true);

    }

    /**
     * 
     * @global type $PAGE
     * @global type $OUTPUT
     * @global type $DB
     * @param type $cm_id
     * @return printable_copy_helper
     */
    public static function create_from_coursemodule($cm_id)
    {
        global $PAGE, $OUTPUT, $DB;

        //get a reference to the coursemodule from it's ID
        if(!$cm = get_coursemodule_from_id('quiz', $cm_id))
            print_error('invalidcoursemodule');

        //get a reference to the quiz in question
        if(!$quiz = $DB->get_record('quiz', array('id' => $cm->instance)))
            print_error('invalidcoursemodule');

        //get a reference to the course via the coursemoudle
        if(!$course = $DB->get_record('course', array('id' => $quiz->course)))
            print_error('invalidcourse');
        
        //ensure the user has permission to access this context
        require_login($course, false, $cm);

        //and get a reference to the context
        $context = context_module::instance($cm->id);

        //and return a new print helper
        return new self($quiz, $context, $course);
    }

    public static function set_up_pdf()
    {
        global $PAGE, $OUTPUT;



        static $singleton = false;

        //ensure this function is only run once
        if($singleton)
            return;
        else
            $singleton = true;

        //TODO: set time limit according to capability, for security

        //allow longer execution times for rendering pdfs
        set_time_limit(60 * 5);

        //force this page to use the PDF theme
        $PAGE->force_theme('pdf');

        //and set the page layout to "report" <-- possibly set this layout to PDF?
        $PAGE->set_pagelayout('report');

        //start rendering the PDF
        echo $OUTPUT->header();
    }

    public static function render_pdf()
    {
        global $OUTPUT;

        //
        if(!optional_param('do_not_render', 0, PARAM_INT)) {
            webkit_pdf::send_embed_headers();
        }

        //Finish rendering the PDF; and stream it to the user.
        echo $OUTPUT->footer();
    } 

    public static function insert_ids($intro, $id)
    {
        //insert any additional barcodes requested
        //TODO: replace with a barcode object
        $intro = str_replace('{testbarcode}', '<barcode type="EAN13" value="'.$id.'" label="none" style="width:20px; height: 5px;></barcode>', $intro);

        //and insert the testid, where appropriate
        return str_replace('{testid}', str_pad($id, 6, '0', STR_PAD_LEFT), $intro);
    }

    public function print_batch($batch_id, $batch_mode = quiz_papercopy_batch_mode::NORMAL, $to_zip = false)
    {
        global $DB;
       
        //set up PDF printing
        self::set_up_pdf();

        //load the batch information from the database
        $batch_info = $DB->get_record(self::get_batch_table(), array('id' => $batch_id));

        //get a list of usage IDs from the batch's information
        $usage_ids = explode(',', $batch_info->usages);

        //if a ZIP format is requested, zip all of the usages, and send them
        if($to_zip)
        {
            //zip each of the QUBAs and send them to the user
            //TODO: Switch this to keyword arguments?
            $cached_file = $this->zip_question_usage_by_activities($usage_ids, $batch_mode, true, 'quiz', $batch_id, null, true, true, $batch_info->entrymethod);

            //save the cached file for future use
            $this->save_prerendered($batch_id, $cached_file, $batch_mode);
        }
        //otherwise, send a single PDF containing the entire batch
        else
        {
            $this->print_question_usage_by_activities($usage_ids, $batch_mode, $batch_info->entrymethod);
        }
    }
   
    public function interactive_prerender($batch_id, $batch_mode, $force_rerender = false)
    {
        global $OUTPUT, $PAGE;

        //set up the page for rendering
        $PAGE->navbar->add('Paper Copies');
        $PAGE->set_pagetype('report');

        // Start rendering the main, non-PDF page.
        echo $OUTPUT->header();

        //TODO: implement
        echo html_writer::tag('div','Rendering... this might take a while.', array('id' => 'prerender'));
        flush();
        ob_flush();

        //perform the actual prerender
        $this->prerender_batch($batch_id, $force_rerender, array($this, 'interactive_prerender_callback'));

        // Finish rendering the page.
        echo $OUTPUT->footer();
    }

    public static function interactive_prerender_callback($total)
    {
        echo html_writer::tag('div', round($total*100).'%');
        flush();
        ob_flush();
    }

    /**
     * Pre-renders a batch of paper copies; intended to be run in the background.
     */
    public function prerender_batch($batch_id, $force_rerender, $progress_callback, $batch_mode = quiz_papercopy_batch_mode::NORMAL)
    {
        global $DB;

        //load the batch information from the database
        $batch_info = (array)$DB->get_record(self::get_batch_table(), array('id' => $batch_id));

        //if the prerender has been completed, and we're not forcing a rerender, quit
        if(!empty($batch_info['prerendered']) && !$force_rerender)
            return;

        //get a list of usage IDs from the batch's information
        $usage_ids = explode(',', $batch_info['usages']);

        //create a zip file containing each of the batch copies
        $file = $this->zip_question_usage_by_activities($usage_ids, $batch_mode, false, 'quiz', $batch_id, $progress_callback);

        //and store the prerendered file in the batch table
        $this->save_prerendered($batch_id, $file, $batch_mode);

        //store the file's pathnamehash (Moodle uses these like IDs) in the batch information
        //$batch_info['prerendered'] = $file->get_pathnamehash();

        //and update the database
        //$DB->update_record(self::get_batch_table(), $batch_info);
    }

    protected function save_prerendered($batch_id, $file, $batch_mode)
    {
        global $DB;

        // Update the database field which corresponds to the type of batch we generated, ensuring that it points to the most recently rendered item.
        $DB->set_field(self::get_batch_table(), self::get_prerender_filearea($batch_mode), $file->get_pathnamehash(), array('id' => $batch_id));
    }

    public function print_prerendered_batch($batch_id)
    {
        global $DB;
        
        //load the batch information from the database
        $batch_info = $DB->get_record(self::get_batch_table(), array('id' => $batch_id));

        //if the prerender has not been completed, return false
        if(empty($batch_info->prerendered))
            return;

        //otherwise, get the pre-rendered file specified
        $fs = get_file_storage();
        $file = $fs->get_file_by_hash($batch_info->prerendered);

        //if the file was invalid, return false
        if($file === false)
            return false;

        //otherwise, print the file:
        
        //set the content type to application/zip
        header('Content-Type: application/zip');

        //and apply a matching filename
        header('Content-Disposition: attachment; filename="PaperCopies.zip"');

        //then, print the file's contents
        $file->readfile();

        //indicate success
        return true;
    }


    /**
     * Creates a zip file containing the PDF for several paper copies, based on the provided Question Usages.
     * Can return the full zip file, or can stream it directly to the user.
     *
     * TODO: Switch to keyword-args-esque array?
     * 
     * @param $quba_array array An array containing the qubaids for each QUBA to be included.
     */
    public function zip_question_usage_by_activities($quba_array, $batch_mode = quiz_papercopy_batch_mode::NORMAL, $output = true, $prefix='quiz', $cache_id = 0, $progress_callback = null, $save = true, $allow_disconnect = true, $include_barcodes = false)
    {
        global $DB;

        // Get a temporary path-name which will be used for PDF output.
        $target_zip = tempnam(sys_get_temp_dir(), 'moodlepdf'); 

        //if we're outputting the zip, create a ZipStream
        if($output)
        {
            $opt = array();

            //if the save option is set, cache the file as well
            if($save)
            {
                //set up a temporary location for the file to be saved to
                $opt['save_to'] = $target_zip;

                // Ensure that the file does not exist.
                unlink($target_zip);

                // If the "allow_disconnect" option is set, then continue processing even if the user disconnects.
                if($allow_disconnect) {
                    ignore_user_abort(true);
                }

            }

            $zip = new CompatibleZipStream('PaperCopies.zip', $opt);
        }
        //otherwise, create a zip on the disk
        else
        {
            $zip = new ZipArchive();

            //open a new zip archive
            //TODO: fail on unable-to-open?
            $zip->open($target_zip, ZIPARCHIVE::CREATE);  
        }

        //store the array count
        $max = count($quba_array) - 1;
        $current = 0;

        //for each QUBA in the array
        foreach($quba_array as $i => $quba_id)
        {
            //reset the execution time counter
            set_time_limit(1024);

            //convert the QUBA into a PDF
            $pdf = $this->print_quba_to_pdf($quba_id, $batch_mode, $include_barcodes);

            //debug
            error_log("PDF copy ".$quba_id."\n");
    
            //and add the PDF to a zip file
            $zip->addFromString($prefix.'_'.$quba_id.'.pdf', $pdf);

            //if a progress callback was specified, maintain it
            if(is_callable($progress_callback))
                call_user_func($progress_callback, ++$current / ++$max);
        }
       
        //close the currently open temporary zip file
        $zip->close();
      
       //if output is off, or save is on, save the file as a Moodle file
        if(!$output || $save) {

            //get a new Moodle file storage area
            $fs = get_file_storage();

            // Delete any existing pre-rendered copies for this batch which have the same cache-id.
            $fs->delete_area_files($this->context->id, 'quiz_papercopy', 'prerendered', $cache_id);

           //populate the file information
            $file_info =
                array
                (
                    'contextid' => $this->context->id,
                    'component' => 'quiz_papercopy',
                    'filearea' => 'prerendered',
                    'itemid' =>  $cache_id,//$this->quiz->id,
                    'filepath' => '/',
                    'filename' => $prefix.'.zip'
                );

                        // ... copy the file into the Moodle datastore.
            $file = $fs->create_file_from_pathname($file_info, $target_zip);

            //remove the temporary copy
            unlink($target_zip);

            //and return the newly created file
            return $file;

        } else {

            //finally, remove the zip file
            @unlink($target_zip);
        }

    }

    /**
     * Get the file-area which should store the pre-rendered version of this file.
     * 
     * @param string $key_mode  A member of the qtype_papercopy_batch pseudo-enumeration 
     * @return string           The file-area name in which the given file should be stored.
     */
    public static function get_prerender_filearea($key_mode) 
    {
        switch($key_mode) {

            // Normal mode: i.e. just the batch itself, with no answer keys 
            case quiz_papercopy_batch_mode::NORMAL:
                return 'prerendered';

            // With-key mode: the batch with its answer keys.
            case quiz_papercopy_batch_mode::WITH_KEY:
                return 'prerendered_with_key';

            // Key-only mode: just the answer keys for the batch.
            case quiz_papercopy_batch_mode::KEY_ONLY:
                return 'prerendered_key_only';

            // Fail-safe case- return null, which should throw an exception when used.
            default:
                return null;

        } 
    }
     

    public function print_question_usage_by_activities($quba_array, $batch_mode = quiz_papercopy_batch_mode::NORMAL, $include_barcodes = true) 
    {
        //set up PDF printing
        self::set_up_pdf();

        //print each QUBA in the array, 
        foreach($quba_array as $quba_id)
        {
            //reset the execution time counter
            set_time_limit(1024);

            //then print the QUBA
            $this->print_question_usage_by_activity($quba_id, $batch_mode, $include_barcodes);
        }
    }

    public function print_question_usage_by_activity($quba_id, $batch_mode = quiz_papercopy_batch_mode::NORMAL, $include_barcodes = true)
    {

        //set up PDF printing
        self::set_up_pdf();

        //ensure the user has the correct permissions to print quizzes (the printing mechanism is a subsidiary of the paper copy report)
        require_capability('mod/quiz:viewreports', $this->context);
      
        //and call the internal printing method
        $this->print_quba($quba_id, $batch_mode, $include_barcodes);
    }

    static function create_pdf_header($title='Printable Copy') 
    {
        global $CFG;

        //Create a PDF header.
        $header = html_writer::start_tag('html');
        $header .= html_writer::start_tag('head');
        $header .= html_writer::tag('title', $title);
        $header .= html_writer::empty_tag('link', array(
            'href' => $CFG->wwwroot.'/theme/pdf/style/core.css',
            'rel' => 'stylesheet',
            'type' => 'text/css'
        ));
        $header .= html_writer::end_tag('head');
        $header .= html_writer::start_tag('body');
        return $header;
    }

    static function create_pdf_footer() 
    {
        return html_writer::end_tag('body');
    }

    /**
     * Prints a given QUBA to a PDF object using the core PDF renderer, and returns the PDF.
     */
    protected function print_quba_to_pdf($quba_id, $batch_mode, $include_barcodes = true, $include_intro = true)
    {
        //start output buffering
        ob_start();

        //print the actual QUBA
        $this->print_quba($quba_id, $batch_mode, $include_barcodes, $include_intro);

        //terminate output buffering, and retrieve the QUBA's data
        $contents = self::create_pdf_header($quba_id).ob_get_clean().self::create_pdf_footer();

        //TODO: document name?
        //return the newly created PDF
        return core_pdf_renderer::output_pdf($contents, true, 'quiz_'.$quba_id.'.pdf', self::render_barcode($quba_id));
    }

    protected function render_question_identifier($usage, $slot) {

        // Get the requisite information.
        $quba_id = $usage->get_id();
        $question = $usage->get_question($slot);
        $question_attempt = $usage->get_question_attempt($slot);

        // Create the URL to the Question ID image.
        $url = new moodle_url('/mod/quiz/report/papercopy/lib/questionid.php', array('quba' => $quba_id, 'q' => $question->id, 'qa' => $question_attempt->get_database_id()));

        $content = html_writer::empty_tag('img', array('src' => $url));
        $content .= $quba_id.'-'.$question->id.'-'.$question_attempt->get_database_id();

        // And return a link to the image.
        return html_writer::tag('div', $content, array('class' => 'questionid'));
    }

    protected function render_barcode($value, $text = '{barcode}') {
        global $CFG;

        //Create the core barcode to be added.
        $image = html_writer::empty_tag('img', array('src' => $CFG->wwwroot.'/mod/quiz/report/papercopy/lib/barcode.php?s='.urlencode($value)));

        //And add the header text with the barcode added.
        $barcode = html_writer::start_tag('div', array('align' => 'center', 'id' => 'pageHeader'));
        $barcode .= str_replace('{barcode}', $image, $text);
        $barcode .= html_writer::end_tag('div');

        return $barcode;
    }


    protected function print_quba($quba_id, $batch_mode, $include_barcodes = true, $include_intro = true, $include_page_numbers = true)
    {
        global $OUTPUT;

        //get the default question display information
        $options = new question_display_options_pdf();

        //get a question usage object from the database
        $usage = question_engine::load_questions_usage_by_activity($quba_id);

        //get an associative array, which indicates the questions which should be rendered
        $slots = $usage->get_slots();

        //if we're not _only_ outputting a key, output the core of the quesiton
        if($batch_mode !== quiz_papercopy_batch_mode::KEY_ONLY)
        {

            //start a new copy with the given margins
            //TODO: HTML2PDF only
            //echo html_writer::start_tag('page', array('backtop' => '9mm', 'backbottom' => '0mm', 'backleft' => '0mm', 'backright' => '8mm'));
            $header = '';

            //start of page headers 
            //if($include_barcodes)
            //{
                //echo html_writer::start_tag('page_header');
                $header .= self::render_barcode($quba_id);

                //echo html_writer::end_tag('page_header');
                $OUTPUT->header = $header;
            //}

            //bookmark, for easy access from a PDF viewer
            echo html_writer::tag('bookmark', '', array('title' => get_string('copynumber', 'quiz_papercopy', $quba_id), 'level' => '0'));

            //print the quiz's introduction
            if(!empty($this->quiz->intro) && $include_intro)
                echo html_writer::tag('div', self::insert_ids($this->quiz->intro, $quba_id), array('class' => 'introduction'));
        
            //output each question
            foreach($slots as $slot => $question)
            {
                $qbuf = '';
                
                // If "include barcodes" is on, render the question-identifier code.
                if($include_barcodes) {
                    $qbuf .= html_writer::start_tag('div', array('class' => 'qwithidentifier'));
                    $qbuf .= self::render_question_identifier($usage, $question);
                }

                // Render the question itself.
                $qbuf .= $usage->render_question($question, $options, $slot + 1);

                // If "include barcodes" is on, render the question-identifier code.
                if($include_barcodes) {
                    $qbuf .= html_writer::end_tag('div');
                }



                //if the core PDF renderer has purification turned off, purify the question locally
                if(core_pdf_renderer::$do_not_purify)
                    $qbuf = core_pdf_renderer::clean_with_htmlpurify($qbuf);

                echo $qbuf;
            }


            //echo html_writer::end_tag('page');
        }
    
        //if a key has been requested, output it as well
        if($batch_mode == quiz_papercopy_batch_mode::KEY_ONLY || $batch_mode == quiz_papercopy_batch_mode::WITH_KEY)
        {
           //start a new copy with the given margins
            echo html_writer::start_tag('page', array('backtop' => '9mm', 'backbottom' => '0mm', 'backleft' => '0mm', 'backright' => '8mm'));

            //start of page headers
            if($include_barcodes)
            { 
                echo html_writer::start_tag('page_header');
                echo html_writer::start_tag('div', array('align' => 'center'));
                echo html_writer::tag('barcode', '', array('value' => $id, 'style' => 'width: 40mm; height: 7mm;', 'label' => 'label')); 
                echo html_writer::end_tag('div');
                echo html_writer::end_tag('page_header');
            }

            //bookmark, for easy access from a PDF viewer
            echo html_writer::tag('bookmark', '', array('title' => get_string('answerkeynumber', 'quiz_papercopy', $id), 'level' => ($batch_mode !== quiz_papercopy_batch_mode::KEY_ONLY)));

            //print the quiz's introduction
            echo html_writer::tag('p', get_string('answerkeyfortestid', 'quiz_papercopy', $id), array('style' => 'font-weight:bold;'));

            echo html_writer::start_tag('table');
            
            //output each question
            foreach($slots as $slot => $question)
            {
                echo html_writer::start_tag('tr');

                //echo the answer key contents
                echo html_writer::tag('td', ($slot + 1).'. ', array('style' => 'padding-right: 10px;'));
                echo html_writer::tag('td', $usage->get_right_answer_summary($question));
                echo html_writer::end_tag('tr');
            }

            echo html_writer::start_tag('table');
            echo html_writer::end_tag('page');
            
        }
    }
}
