<?php

/**
 * PDF output generation for quiz paper copies
 * @author ktemkin
 */


//ini_set('display_errors', 1); 
//error_reporting(E_ALL);

require_once('../../../../config.php');
require_once('lib.php');
require_once('report.php');

//two possible core identifiers: attempt ID, or coursemodule ID
$attempt_id = optional_param('attempt', 0, PARAM_INT);
$cm_id = optional_param('id', 0, PARAM_INT);

//supplementary identification, for printing within a coursemodule
//these identify what should be printed
$quba_id = optional_param('usage', 0, PARAM_INT);
$batch_id = optional_param('batch', 0, PARAM_INT);
$as_zip = optional_param('zip', 0, PARAM_INT);
$force_rerender = optional_param('rerender', 0, PARAM_INT);
$prerender = optional_param('prerender', 0, PARAM_INT);

//DEBUG FIXME
$force_rerender = 1;

//if an attempt ID was provided, use it first, as it's most specific
if($attempt_id) {

    //print the given attempt, if the user has the appropriate access
    printable_copy_helper::print_attempt($attempt_id);
}
//otherwise, fall back on the coursemoudule ID
elseif($cm_id) {

    //create a new printable_copy_helper
    $printer = printable_copy_helper::create_from_coursemodule($cm_id);

    //retrieve optional arguments for the printer, which are only appropriate for the report view
    $batch_mode = optional_param('mode', '', PARAM_ALPHA);

    //if a QUBA was specified, print it, and only it (the helper methods check permissions)
    if($quba_id) {
        $printer->print_question_usage_by_activity($quba_id, $batch_mode);
    }
    //otherwise, use a batch, if provided
    else if($batch_id) {

        //disable purification on batch jobs, as it takes up too much RAM
        //(the print_batch function will handle purification)
        core_pdf_renderer::$do_not_purify = true;

        // If "force-rerender" is _not_ set, and a pre-rendered batch exists,
        // (e.g. this batch has been rendered before), then print the pre-rendered batch.
        // 
        // TODO: Perhaps don't run this part if we're going to pre-render, but instead print an "already rendered" 
        // message?
        if(!$force_rerender) {

            // If a pre-rendered batch exits, print it. Otherwise, this will return false.
            $printed = $printer->print_prerendered_batch($batch_id);
            
            // If we printed a batch, then terminate this script's execution.
            // If we don't abort here, Moodle will append a blank PDF to the end of our full PDF file.
            if($printed) {
                exit();
            }
        }

        // If the pre-render option is set, then begin executing the "interactive pre-renderer", which 
        // updates the user on progress instead of sending the rendered file. The file can then be downloaded later.
        // (This is excellent for starting a long render operation from a mobile phone.)
        if($prerender) {

            // Compose the URL at which this page is located...
            $url = quiz_papercopy_report::get_paper_copy_url(array('id' => $cm_id, 'batch' => $batch_id, 'mode' => $batch_mode, 'prerender' => '1'));

            // ... and inform the page's renderer. Note that the pre-renderer does not use the render-to-PDF theme
            $PAGE->set_url($url).

            // Start the interactive pre-renderer.
            $printer->interactive_prerender($batch_id, $batch_mode, true);
            
            // Once pre-render has completed, abort; so we do not continue to the render-to-pdf theme.
            // TODO: force this to the 
            exit();

        } else {
            $PAGE->set_url(quiz_papercopy_report::get_paper_copy_url(array('id' => $cm_id, 'batch' => $batch_id, 'mode' => $batch_mode, 'prerender' => '0')));
            //print the entire batch
            $printer->print_batch($batch_id, quiz_papercopy_batch_mode::NORMAL, $as_zip);
        }

        //if we just finished printing a zip,
        if($as_zip) {
            exit();
        }
    }

    //if neither was provided, we can't continue; print an error
    else {
        throw new moodle_exception('missingparam', 'error', '', 'usage');
    }
}
else
{
    //if neither was provided, throw a missing parameter error
    throw new moodle_exception('missingparam', 'error', '', 'id');
}


printable_copy_helper::render_pdf();



