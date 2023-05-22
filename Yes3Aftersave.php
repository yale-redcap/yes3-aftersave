<?php

namespace Yale\Yes3Aftersave;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

use REDCap;
use Calculate;

class Yes3Aftersave extends \ExternalModules\AbstractExternalModule
{
    
    public $LOG_DEBUG_TABLE = "ydcclib_debug_messages";

    function redcap_save_record ( 
        $project_id,
        $record, 
        $instrument, 
        $event_id, 
        $group_id = NULL, 
        $survey_hash = NULL, 
        $response_id = NULL, 
        $repeat_instance = 1 
    ){

        $this->refreshRegisteredYes3SupportForms( $record, $instrument );
    }

    public function refreshRegisteredYes3SupportForms( $record, $skipThisForm="" ){

        $yes3_forms         = $this->getProjectSetting('yes3-support-forms');
        $yes3_form_event    = $this->getProjectSetting('yes3-form-event');
        $yes3_form_name     = $this->getProjectSetting('yes3-form-name');

        $k = 0;
    
        for($i=0; $i<count($yes3_forms); $i++){

            if ( $skipThisForm && $yes3_form_name[$i] !== $skipThisForm ){

                $k += $this->refreshYes3SupportForm( $record, $yes3_form_event[$i], $yes3_form_name[$i] );
            }
        }

        return $k;
    }

    private function refreshYes3SupportForm( $record, $event_id, $form_name )
    {
        if (!$fields = $this->getCalculatedFieldsForForm( $this->getProjectId(), $form_name )){

            return -1;
        }

        /**
         * If needed, initialize the record by saving the completion status,
         * because REDCap calc functions are skipped for uninitialized forms(!)
         */
        $complete_field = $form_name . '_complete';

        if ( !REDCap::getData( $this->getProjectId(), "array", $record, $complete_field, $event_id ) ) {
            
            REDCap::saveData(
                $this->getProjectId(),
                "array",
                [
                    $record => [
                        $event_id => [
                            $complete_field => "1"
                        ]
                    ]    
                ]
            );
        }

        /*
        Yes3::logDebugMessage(
            $this->getProjectId(),
            print_r($data, true),
            "refreshYes3SupportForm: current data"
        );

        Yes3::logDebugMessage(
            $this->getProjectId(),
            print_r($rc, true),
            "refreshYes3SupportForm: save result"
        );
        */
        /**
         * Now recalculate, until no changes noted.
         */
        $iter = 0;

        while ( $iter < 5 ){

            $iter++;

            $k = Calculate::saveCalcFields( $record, $fields, $event_id );

            if ( !$k ) break;
        }
/*
        Yes3::logDebugMessage(
            $this->getProjectId(),
            print_r($fields, true),
            "refreshYes3SupportForm: fields"
        );

        Yes3::logDebugMessage(
            $this->getProjectId(),
            "record={$record}, event_id={$event_id}, form_name={$form_name}. {$iter} iteration(s).",
            "refreshYes3SupportForm: result"
        );
*/
        return $iter;
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id){

        if ( $action==="selectForms" ) return $this->selectForms( $project_id );

        return "The action '{$action}' is not supprted.";
    }

    private function selectForms( $project_id ){

        $Project = $this->getProject();

        $sqlF = "SELECT DISTINCT form_name FROM redcap_metadata WHERE project_id=? ORDER BY field_order";

        $form_names = array_column($this->fetchRecords($sqlF, [ $project_id ]), 'form_name');

        $aftersaveForms = [];

        $dependentForms = [];

        foreach($form_names as $form_name){

            if ( !$calc_field_names = $this->getCalculatedFieldsForForm($project_id, $form_name) ) continue;

            foreach($calc_field_names as $calc_field_name){

                $this->addExternalFormsForCalculatedField( $Project, $form_name, $calc_field_name, $aftersaveForms, $dependentForms );
            }
        }

        /**
         * forms having fields affecting calculations in other forms
         */
        $yes3_aftersave_forms = [];
        $yes3_aftersave_form = [];
        $yes3_aftersave_form_external_calcs = [];

        foreach($aftersaveForms as $form_name=>$external_calcs){
            $yes3_aftersave_forms[] = true;
            $yes3_aftersave_form[] = $form_name;
            $yes3_aftersave_form_external_calcs[] = $external_calcs;
        }

        $this->setProjectSetting('yes3-aftersave-forms', $yes3_aftersave_forms);
        $this->setProjectSetting('yes3-aftersave-form', $yes3_aftersave_form);
        $this->setProjectSetting('yes3-aftersave-form-external-calcs', $yes3_aftersave_form_external_calcs);

        /**
         * forms having calculations dependent on fields in other forms
         */
        $yes3_aftersave_dependent_forms = [];
        $yes3_aftersave_dependent_form = [];
        $yes3_aftersave_dependent_form_skip_if_empty = [];
        $yes3_aftersave_dependent_form_external_fields = [];

        foreach($dependentForms as $form_name=>$external_fields){
            $yes3_aftersave_dependent_forms[] = true;
            $yes3_aftersave_dependent_form[] = $form_name;
            $yes3_aftersave_dependent_form_external_fields[] = $external_fields;
            $yes3_aftersave_dependent_form_skip_if_empty[] = "0";
        }

        $this->setProjectSetting('yes3-aftersave-dependent-forms', $yes3_aftersave_dependent_forms);
        $this->setProjectSetting('yes3-aftersave-dependent-form', $yes3_aftersave_dependent_form);
        $this->setProjectSetting('yes3-aftersave-dependent-form-skip-if-empty', $yes3_aftersave_dependent_form_skip_if_empty);
        $this->setProjectSetting('yes3-aftersave-dependent-form-external-fields', $yes3_aftersave_dependent_form_external_fields);
        
        return [
            'aftersaveForms' => $aftersaveForms,
            'dependentForms' => $dependentForms
        ];
    }

    private function getCalculatedFieldsForForm( $project_id, $form_name ){

        $sql = "SELECT field_name 
        FROM redcap_metadata 
        WHERE project_id=? 
          AND form_name=? 
          AND ( element_type='calc' 
            OR POSITION('@CALCTEXT' IN misc) > 0 
            OR POSITION('@CALCDATE' IN misc) > 0 
          ) 
        ORDER BY field_order";
        
        $params = [
            $project_id,
            $form_name
        ];

        if (!$calc_field_names = array_column($this->fetchRecords($sql, $params), 'field_name')){

            return [];
        }

        return $calc_field_names;
    }

    private function addExternalFormsForCalculatedField( $Project, $calc_form_name, $calc_field_name, &$externalForms, &$dependentForms ){

        $expressions = $this->fetchRecords(
            "SELECT `element_enum`, `misc`
            FROM redcap_metadata 
            WHERE project_id=? 
                AND field_name=? 
            LIMIT 1",
            [ $Project->getProjectId(), $calc_field_name ]
        )[0];
        
        $expression_fields = array_merge(
            $this->parseExpressionFields( $expressions['element_enum'] ),
            $this->parseExpressionFields( $expressions['misc'] ),
        );
        
        $calc_field_token = "[" . $calc_field_name . "]";

        foreach( $expression_fields as $expression_field_name ){

            if ( $expression_field_name ){

                $expression_field_token = "[" . $expression_field_name . "]";

                $expression_form_name = $this->getFormNameForFieldName( $expression_field_name );
                
                if ( $expression_form_name && $expression_form_name !== $calc_form_name ){

                    // calcForms is the collection of forms having calculated fields dependent on other forms
                    if ( !in_array( $calc_form_name, array_keys($dependentForms) ) ){

                        $dependentForms[$calc_form_name] = "";
                    }

                    if ( strpos($dependentForms[$calc_form_name], $expression_field_token)===false ){

                        $dependentForms[$calc_form_name] .=  $expression_field_token . " on " . $expression_form_name . "\n";
                    }

                    // externalForms is the collection of forms contributing to external calculations
                    if ( !in_array( $expression_form_name, array_keys($externalForms) ) ){

                        $externalForms[$expression_form_name] = "";
                    }

                    if ( strpos($externalForms[$expression_form_name], $calc_field_token)===false ){

                        $externalForms[$expression_form_name] .= $calc_field_token . " on " . $calc_form_name . "\n";
                    }
                }
            }
        }
    }

    private function getFormNameForFieldName( $field_name ){

        return $this->fetchRecords(
            "SELECT form_name FROM redcap_metadata WHERE project_id=? AND field_name=? LIMIT 1",
            [ 
                $this->getProjectId(), 
                $field_name 
            ])[0]['form_name'];
    }

    private function parseExpressionFields( $calcExpression ){

        if ( !strlen($calcExpression) ) return [];

        $expression_fields = [];

        $i = strpos( $calcExpression, "[" );
        $j = 0;
        $len_1 = strlen( $calcExpression ) - 1;

        $this->logDebugMessage('parseExpressionFields', $calcExpression);

        while ( $i !== false && $j < $len_1 ){

            $j = strpos( $calcExpression, "]", $i );

            if ( $j === false ){

                break;
            } 
            else {

                //$this->logDebugMessage('-- top of loop', "i = {$i}, j = {$j}");

                // make sure not the event part of a compound [event][field] spec
                if ( $j === $len_1 || $calcExpression[$j+1] !== "[" ) {

                    $field_name = substr( $calcExpression, $i+1, $j-$i-1 );

                    if ( !in_array($field_name, $expression_fields )){

                        $expression_fields[] = $field_name;
                    }

                    $this->logDebugMessage('parseExpressionFields: field name', $field_name);
                }
            }

            $i = strpos( $calcExpression, "[", $j );
        }

        return $expression_fields;
    }


    private function fetchRecords($sql, $parameters = [])
    {
       $rows = [];
       $resultSet = $this->query($sql, $parameters);
       if ( $resultSet->num_rows > 0 ) {
          while ($row = $resultSet->fetch_assoc()) {
             $rows[] = $row;
          }
       }
       return $rows;
    }

    public function logDebugMessage($msgcat, $msg) 
    {   
         $sql = "INSERT INTO `".$this->LOG_DEBUG_TABLE."` (project_id, debug_message, debug_message_category) VALUES (?,?,?)";
 
         return $this->query($sql, [$this->getProjectId(), $msg, $msgcat]);
    }
 
}