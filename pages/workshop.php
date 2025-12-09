<?php

namespace Yale\Yes3Aftersave;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$module = new Yes3Aftersave();

$module->testing = true;

$PID = $module->getProjectId();
$ProjTitle = $module->getProject()->getTitle();

$module->initializeJavascriptModuleObject();

echo "<h4>YES3 Aftersave Workshop for Project: $ProjTitle (PID $PID)</h4>";
echo "<p>This workshop page is intended for testing and experimenting with the YES3 Aftersave module features.</p>";

$config = $module->setConfigE( $PID );

printErrorReport($config);

redcap_save_record_test( $module );

printConfig($config);

function redcap_save_record_test( $module ){

    $project_id = $module->getProjectId();
    $repeat_instance = 1;

    /* 
    // livebetter test data
    $instrument = "patient_screening_form";
    $record = 'BWH-00133';
    $event_id = 43;
    */

    // multi-arm test data
    $instrument = "screening_questionnaire_contact_information";
    $record = '1';
    $event_id = 88; // screening arm 1

    $module->redcap_save_record( 
        $project_id, 
        $record, 
        $instrument, 
        $event_id, 
        NULL, 
        NULL, 
        NULL, 
        $repeat_instance
    );
}

function printConfig($config){

    echo "<h5>Current Configuration Data</h5>";
    echo "<pre>";
    print_r( $config );
    echo "</pre>";
}

function printErrorReport($config){

    $k = count($config['error_report']);

    if ( $k > 0 ){

        echo "<h5>{$k} Calculation Expression Errors Detected</h5>";
        echo "<ul>";

        foreach( $config['error_report'] as $error_message ){

            echo "<li>$error_message</li>";
        }

        echo "</ul>";
    }
}

