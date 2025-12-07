<?php

namespace Yale\Yes3Aftersave;
/*
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
*/

use REDCap;
use Calculate;
use Exception;

class Yes3Aftersave extends \ExternalModules\AbstractExternalModule
{
    private $AFTERSAVE_ACTION_IGNORE = "1";
    private $AFTERSAVE_ACTION_NONEMPTY = "2";
    private $AFTERSAVE_ACTION_ALWAYS = "3";
    private $AFTERSAVE_ACTION_DEFAULT = "2";
    private $MAX_SAVE_PASSES = 8;
    private $DEFAULT_FORM_COMPLETE = "1";
    public  $LOG_DEBUG_TABLE = "ydcclib_debug_messages";
    public  $ALLOW_DEBUG_LOGGING = false;
    public  $testing = false;

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

        //$this->logDebugMessage('redcap_save_record:args', print_r(func_get_args(), true));

        $aftersave_forms = json_decode($this->getProjectSetting('aftersave-forms-json'));

        if ( !is_array($aftersave_forms) ) return false;

        if ( !in_array($instrument, $aftersave_forms) ) return false;

        $arm_num = $this->getArmNumForEvent( $project_id, $event_id );

        // list of all (trigger, dependent) field items
        $field_bridge = json_decode($this->getProjectSetting('field-bridge-json'), true);

        // list of all forms having calculated fields that depend on fields in other ('aftersave') forms
        $dependent_forms = $this->getProjectSetting('yes3-dependent-form');

        // calculation actions for dependent forms (ignore, nonempty, always)
        $aftersave_actions = $this->getProjectSetting('yes3-dependent-form-aftersave-action');

        // reduce the field bridge, dependent forms and aftersave actions to only those forms and fields on the triggering ('aftersave') instrument
        $field_bridge_reduced = array();
        $dependent_forms_reduced = array();
        $aftersave_actions_reduced = array();

        foreach( $field_bridge as $item ){

            if ( $item['aftersave_form_name'] === $instrument ){

                $field_bridge_reduced[] = $item;

                if ( !in_array( $item['dependent_form_name'], $dependent_forms_reduced ) ){

                    $dependent_forms_reduced[] = $item['dependent_form_name'];
                    $aftersave_actions_reduced[] = $aftersave_actions[ array_search( $item['dependent_form_name'], $dependent_forms ) ];
                }
            }
        }

        //$this->logDebugMessage('redcap_save_record:field_bridge_reduced', print_r($field_bridge_reduced, true));    
        //$this->logDebugMessage('redcap_save_record:dependent_forms_reduced', print_r( $dependent_forms_reduced, true));
        //$this->logDebugMessage('redcap_save_record:aftersave_actions_reduced', print_r( $aftersave_actions_reduced, true));

        if ( $this->testing ) {
            echo "<pre>";
            echo "-------\n";
            echo "Reduced field_bridge for aftersave form '{$instrument}':\n";
            echo "-------\n";
            print_r($field_bridge_reduced);
            echo "-------\n";
            echo "Reduced dependent_forms and actions for aftersave form '{$instrument}':\n";
            echo "-------\n";
            print_r( $dependent_forms_reduced );
            print_r( $aftersave_actions_reduced );
            echo "</pre>";
        }

        $this->calculateDependentFormsE( 
            $project_id, 
            $arm_num,
            $record, 
            $event_id, 
            $repeat_instance, 
            $instrument, 
            $dependent_forms_reduced,
            $aftersave_actions_reduced, 
            $field_bridge_reduced
        );
    }
    
    public function calculateDependentFormsE( 
        $project_id, 
        $arm_num, 
        $record,
        $aftersave_form_event_id, 
        $repeat_instance, 
        $aftersave_form_name, 
        $dependent_forms, 
        $aftersave_actions, 
        $field_bridge){

       //$this->logDebugMessage('calculateDependentFormsE:args', print_r(func_get_args(), true));

        $k = 0;
    
        for($i=0; $i<count($dependent_forms); $i++){

            // skipThisForm is the form being saved, i.e. the trigger form
            
            if ( $dependent_forms[$i] !==$aftersave_form_name ){

                if ( $this->testing ) print "<p>Processing dependent form '{$dependent_forms[$i]}'</p>";
                //$this->logDebugMessage('calculateDependentFormsE', "Processing dependent form '{$dependent_forms[$i]}'");

                foreach( $this->getREDCapEventsForForm($project_id, $dependent_forms[$i]) as $dependent_form_event_id ){

                    if ( $this->testing ) print "Checking dependent form '{$dependent_forms[$i]}' for event_id '{$dependent_form_event_id}'<br />";
                    //$this->logDebugMessage('calculateDependentFormsE', "Checking dependent form '{$dependent_forms[$i]}' for event_id '{$dependent_form_event_id}'");

                    /**
                     * Build list of dependent fields to be recalculated for this form and event.
                     * 
                     * We want a dependent form calculated only if:
                     * - there is at least one field in the calculation expression that explicitly references the triggering event (the "aftersave" event) OR
                     * - there is at least one field in the calculation expression for which no event is specified, 
                     *     and the dependent form event is the same as the triggering (aftersave) form event.
                     * 
                     */
                    $dependent_fields = [];

                    //$this->logDebugMessage('calculateDependentFormsE:field_bridge', print_r($field_bridge, true));

                    foreach($field_bridge as $item){

                        //$this->logDebugMessage('calculateDependentFormsE:checking_item', print_r($item, true));
                        //$this->logDebugMessage('calculateDependentFormsE:aftersave_form_event_id',$aftersave_form_event_id);
                        //$this->logDebugMessage('calculateDependentFormsE:dependent_form_event_id',$dependent_form_event_id);

                        if ( $item['dependent_form_name']===$dependent_forms[$i] 
                            && $item['aftersave_form_name']===$aftersave_form_name
                            && ($item['aftersave_event_id']==$aftersave_form_event_id || $item['aftersave_event_id']==0 && $dependent_form_event_id==$aftersave_form_event_id)
                            ){

                            if ( !in_array($item['dependent_field_name'], $dependent_fields) ) $dependent_fields[] = $item['dependent_field_name'];
                        }
                    }

                    //$this->logDebugMessage('calculateDependentFormsE:dependent_fields', print_r($dependent_fields, true));

                    if ( $aftersave_actions[$i]!==$this->AFTERSAVE_ACTION_IGNORE 
                        && count($dependent_fields) > 0
                        && $this->getArmNumForEvent( $project_id, $dependent_form_event_id ) === $arm_num ) {

                        if ( $this->testing ) {
                            print "<br /><strong>Calculating dependent form '{$dependent_forms[$i]}' for event_id '{$dependent_form_event_id}'</strong><br />";
                            print "&rarr;Dependent fields: " . implode( ", ", $dependent_fields ) . "<br />";
                        }

                        //$this->logDebugMessage('calculateDependentFormsE', "Calculating dependent form '{$dependent_forms[$i]}' for event_id '{$dependent_form_event_id}' with dependent fields: " . implode( ", ", $dependent_fields ) );

                        $k += $this->calculateDependentFormE( $project_id, $record, $dependent_form_event_id, $repeat_instance, $dependent_forms[$i], $aftersave_actions[$i], $dependent_fields ) ;
                    }
                }
            }
        }

        return $k;
    }

    private function calculateDependentFormE( 
        $project_id, 
        $record, 
        $event_id, 
        $repeat_instance, 
        $form_name, 
        $aftersave_action, 
        $fields ){

       //$this->logDebugMessage('calculateDependentForm:args', print_r(func_get_args(), true));

        /**
         * If designer has elected to always recalculate fields on this form, if needed initialize the form by saving the completion status,
         * because REDCap calc functions skip uninitialized forms
         */
        if ( $aftersave_action === $this->AFTERSAVE_ACTION_ALWAYS && !$this->formIsInitialized( $project_id, $form_name, $record, $event_id, $repeat_instance ) ) {

            $complete_field_name = $form_name . '_complete';

            $data = [];

            if ( in_array($form_name, $this->getRepeatingForms($event_id, $project_id) ) ) {

                // repeat instance structure
                $data[$record]['repeat_instances'][$event_id][$form_name][$repeat_instance][$complete_field_name] = $this->DEFAULT_FORM_COMPLETE;
            }
            else {

                // normal
                $data[$record][$event_id][$complete_field_name] = $this->DEFAULT_FORM_COMPLETE;
            }
            
            $rc = REDCap::saveData(
                $project_id,
                "array",
                $data
            );

            if ( isset($rc['errors']) && count($rc['errors']) > 0 ) {

                if ( $this->testing ) {
                    print "&rarr;Error initializing form '{$form_name}' for record '{$record}', event_id '{$event_id}', repeat_instance '{$repeat_instance}': " . print_r( $rc['errors'], true ) . "<br />";
                }

                throw new Exception( "AfterSave: Error initializing form '{$form_name}' for record '{$record}', event_id '{$event_id}', repeat_instance '{$repeat_instance}': " . print_r( $rc['errors'], true ) );
            }

           //$this->logDebugMessage('calculateDependentForm:saveComplete', print_r($rc, true));

            if ( $this->testing ) {
                print "&rarr;Initialized form '{$form_name}' for record '{$record}', event_id '{$event_id}', repeat_instance '{$repeat_instance}'<br />";   
            }
        }

        /**
         * Now recalculate, until no changes noted.
         */
        $iter = 0;

        while ( $iter < $this->MAX_SAVE_PASSES ){

            $iter++;

            $k = Calculate::saveCalcFields( $record, $fields, $event_id );

            if ( !$k ) break;
        }

        if ( $this->testing ) {
            print "&rarr;Completed recalculation passes ({$iter}) for form '{$form_name}' for record '{$record}', event_id '{$event_id}', repeat_instance '{$repeat_instance}'<br />";   
        }

        return $iter;
    }

    private function formIsInitialized( $project_id, $form_name, $record, $event_id, $repeat_instance=1 ){

        $complete_field = $form_name . '_complete';

        $redcap_data = $this->getDataTable((int) $project_id);

        $sql = "SELECT `value` AS `complete` FROM $redcap_data WHERE project_id=? AND `record`=? AND `event_id`=? AND ifnull(`instance`, 1)=? AND field_name=? LIMIT 1";
        $params = [ $project_id, $record, $event_id, $repeat_instance, $complete_field ];

        $x = $this->fetchRecords($sql, $params);

        if ( !$x ){

            return false;
        }

        return ( $x[0]['complete'] ) ? true:false;
    }


    /* === HOOKS === */

    function redcap_module_link_check_display($project_id, $link){

        if ( $this->getUser()->hasDesignRights() ) return $link;

        return null;
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id){

        if ( $action==="setConfig" ) return $this->setConfigE( $project_id );
        
        return "The action '{$action}' is not supported.";
    }

    /**
     * Ransacks field metadata to build the required structures, and saves them as EM config settings.
     * 
     * @param mixed $project_id 
     * @return array 
     * @throws Exception 
     */
    private function setConfig( $project_id ){

        $aftersave_forms = []; // list of trigger (aftersave) forms
        $dependent_forms = []; // list of dependent forms

        $field_bridge = []; // (trigger, dependent) field pairs
        $form_bridge = []; // (trigger, dependent) form pairs

        $calcFields = $this->getCalculatedFieldsForProject( $project_id );

        foreach($calcFields as $calcField){
           
            $expression_fields = array_merge(
                $this->parseExpressionFields( $project_id, $calcField['element_enum'] ),
                $this->parseExpressionFields( $project_id, $calcField['misc'] ),
            );

            foreach( $expression_fields as $expression_field_name ){

                if ( $expression_field_name ){

                    $expression_form_name = $this->getFormNameForFieldName( $project_id, $expression_field_name );

                    // we only deal with expression fields in other forms
                    if ( $expression_form_name !== $calcField['form_name'] ){

                        $pair_in_field_bridge = false;

                        foreach($field_bridge as $b){

                            if ( $b['aftersave_field_name']===$expression_field_name && $b['dependent_field_name']===$calcField['field_name'] ){

                                $pair_in_field_bridge = true;
                                break;
                            }
                        }

                        if ( !$pair_in_field_bridge ) {

                            $field_bridge[] = [
                                'aftersave_form_name' => $expression_form_name,
                                'aftersave_field_name' => $expression_field_name,
                                'dependent_form_name' => $calcField['form_name'],
                                'dependent_field_name' => $calcField['field_name']
                            ];

                            $pair_in_form_bridge = false;

                            foreach($form_bridge as $formb){

                                if ( $formb['aftersave_form_name']===$expression_form_name && $formb['dependent_form_name']===$calcField['form_name'] ){

                                    $pair_in_form_bridge = true;
                                    break;
                                }
                            }
    
                            if ( !$pair_in_form_bridge ) {

                                $form_bridge[] = [
                                    'aftersave_form_name' => $expression_form_name,
                                    'dependent_form_name' => $calcField['form_name'],
                                ];
                            
                                if ( !in_array( $expression_form_name, $aftersave_forms ) ){

                                    $aftersave_forms[] = $expression_form_name;
                                }

                                if ( !in_array( $calcField['form_name'], $dependent_forms ) ){

                                    $dependent_forms[] = $calcField['form_name'];
                                }
                            }
                        }
                    }
                }
            }
        }

        sort($aftersave_forms);

        sort($dependent_forms);

        array_multisort(
            array_column($form_bridge, 'aftersave_form_name' ), SORT_ASC,
            array_column($form_bridge, 'dependent_form_name' ), SORT_ASC,
            $form_bridge
        );

        array_multisort(
            array_column($field_bridge, 'aftersave_form_name' ), SORT_ASC,
            array_column($field_bridge, 'aftersave_field_name'), SORT_ASC,
            array_column($field_bridge, 'dependent_form_name' ), SORT_ASC,
            array_column($field_bridge, 'dependent_field_name'), SORT_ASC,
            $field_bridge
        );

        $this->writeConfig($field_bridge, $form_bridge, $aftersave_forms, $dependent_forms);

        /**
         * the client will assemble a report out of this stuff
         */
        return [
            'field_bridge' => $field_bridge,
            'form_bridge' => $form_bridge,
            'aftersave_forms' => $aftersave_forms,
            'dependent_forms' => $dependent_forms
        ];
    }

    /**
     * Ransacks field metadata to build the required structures, and saves them as EM config settings.
     * This version handles event names as well as field names from calc expressions.
     * 
     * @param mixed $project_id 
     * @return array 
     * @throws Exception 
     */
    public function setConfigE( $project_id ){

        $aftersave_forms = []; // list of trigger (aftersave) forms
        $dependent_forms = []; // list of dependent forms

        $field_bridge = []; // (trigger, dependent) field pairs
        $form_bridge = []; // (trigger, dependent) form pairs

        $calcFields = $this->getCalculatedFieldsForProject( $project_id );

        $error_report = [];

        foreach($calcFields as $calcField){
           
            $expression_fields = array_merge(
                $this->parseExpressionFieldsE( $project_id, $calcField['element_enum'] ),
                $this->parseExpressionFieldsE( $project_id, $calcField['misc'] ),
            );

            foreach( $expression_fields as $expression_field ){

                if ( $expression_field['field_name'] ){

                    $error_expression_field = 0;
                    $error_expression_event = 0;

                    $expression_form_name = $this->getFormNameForFieldName( $project_id, $expression_field['field_name'] );

                    if ( !$expression_form_name ) {
                        $error_expression_field = 1;
                        $error_report[] = "In project {$project_id}, calculated field [{$calcField['field_name']}] references unknown field [{$expression_field['field_name']}]";
                    }

                    $expression_event_id = intval($expression_field['event_name'] ? REDCap::getEventIdFromUniqueEvent( $expression_field['event_name']) : 0);

                    if ( $expression_field['event_name'] && !$expression_event_id ) {
                        $error_expression_event = 1;
                        $error_report[] = "In project {$project_id}, calculated field [{$calcField['field_name']}] references unknown event [{$expression_field['event_name']}]";
                    }

                    if ( $error_expression_field || $error_expression_event ) {
                        continue;
                    }

                    // we only deal with expression fields in other forms
                    if ( $expression_form_name !== $calcField['form_name'] ){

                        $pair_in_field_bridge = false;

                        foreach($field_bridge as $b){

                            if ( $b['aftersave_field_name']===$expression_field['field_name'] 
                                && $b['aftersave_event_id']==$expression_event_id
                                && $b['dependent_field_name']===$calcField['field_name'] ){

                                $pair_in_field_bridge = true;
                                break;
                            }
                        }

                        if ( !$pair_in_field_bridge ) {

                            $field_bridge[] = [
                                'aftersave_form_name' => $expression_form_name,
                                'aftersave_field_name' => $expression_field['field_name'],
                                //'aftersave_event_name' => $expression_field['event_name'],
                                'aftersave_event_id' =>  intval($expression_event_id),
                                'dependent_form_name' => $calcField['form_name'],
                                'dependent_field_name' => $calcField['field_name'],
                                //'error_expression_field' => $error_expression_field,
                                //'error_expression_event' => $error_expression_event
                            ];

                            $pair_in_form_bridge = false;

                            foreach($form_bridge as $formb){

                                if ( $formb['aftersave_form_name']===$expression_form_name 
                                    && $formb['aftersave_event_id']==$expression_event_id
                                    && $formb['dependent_form_name']===$calcField['form_name'] ){

                                    $pair_in_form_bridge = true;
                                    break;
                                }
                            }
    
                            if ( !$pair_in_form_bridge ) {

                                $form_bridge[] = [
                                    'aftersave_form_name' => $expression_form_name,
                                    'aftersave_event_id' => intval($expression_event_id),
                                    'dependent_form_name' => $calcField['form_name'],
                                ];
                            
                                if ( !in_array( $expression_form_name, $aftersave_forms ) ){

                                    $aftersave_forms[] = $expression_form_name;
                                }

                                if ( !in_array( $calcField['form_name'], $dependent_forms ) ){

                                    $dependent_forms[] = $calcField['form_name'];
                                }
                            }
                        }
                    }
                }
            }
        }

        sort($aftersave_forms);

        sort($dependent_forms);

        array_multisort(
            array_column($form_bridge, 'aftersave_form_name' ), SORT_ASC,
            array_column($form_bridge, 'dependent_form_name' ), SORT_ASC,
            $form_bridge
        );

        array_multisort(
            array_column($field_bridge, 'aftersave_form_name' ), SORT_ASC,
            array_column($field_bridge, 'aftersave_event_id'), SORT_ASC,
            array_column($field_bridge, 'aftersave_field_name'), SORT_ASC,
            array_column($field_bridge, 'dependent_form_name' ), SORT_ASC,
            array_column($field_bridge, 'dependent_field_name'), SORT_ASC,
            $field_bridge
        );

        $this->writeConfig($field_bridge, $form_bridge, $aftersave_forms, $dependent_forms);

        /**
         * the client will assemble a report out of this stuff
         */
        return [
            'field_bridge' => $field_bridge,
            'form_bridge' => $form_bridge,
            'aftersave_forms' => $aftersave_forms,
            'dependent_forms' => $dependent_forms,
            'error_report' => $error_report
        ];
    }

    private function writeConfig($field_bridge, $form_bridge, $aftersave_forms, $dependent_forms){

        /**
         * collect the user selections for 'aftersave action' into an assoc array keyed by form name
         */

        $setting_depform = $this->getProjectSetting('yes3-dependent-form');
        $setting_aftersave_action = $this->getProjectSetting('yes3-dependent-form-aftersave-action');
        
        $form_action_setting = [];
        if ( is_array($setting_depform) && is_array($setting_aftersave_action)){

            for ($i=0; $i<count($setting_depform); $i++){

                $form_action_setting[$setting_depform[$i]] = $setting_aftersave_action[$i] ?? $this->AFTERSAVE_ACTION_DEFAULT;
            }
        }

        /**
         * Build the settings arrays, preserving any user selections for 'aftersave action'.
         * Any new forms are assigned the default action.
         */

        $yes3_dependent_forms = [];
        $yes3_dependent_form = [];
        $yes3_dependent_form_aftersave_action = [];

        foreach($dependent_forms as $dependent_form){

            $yes3_dependent_forms[] = true;
            $yes3_dependent_form[] = $dependent_form;
            $yes3_dependent_form_aftersave_action[] = 
                ( isset($form_action_setting[$dependent_form]) && $form_action_setting[$dependent_form] ) ? $form_action_setting[$dependent_form]:$this->AFTERSAVE_ACTION_DEFAULT;
        }

        // the exposed settings
        $this->setProjectSetting('yes3-dependent-forms', $yes3_dependent_forms);
        $this->setProjectSetting('yes3-dependent-form', $yes3_dependent_form);
        $this->setProjectSetting('yes3-dependent-form-aftersave-action', $yes3_dependent_form_aftersave_action);
        
        // the hidden settings
        $this->setProjectSetting('field-bridge-json', json_encode($field_bridge));
        $this->setProjectSetting('form-bridge-json', json_encode($form_bridge));
        $this->setProjectSetting('aftersave-forms-json', json_encode($aftersave_forms));
        $this->setProjectSetting('dependent-forms-json', json_encode($dependent_forms));
    }

    private function getCalculatedFieldsForProject( $project_id ){

        return $this->fetchRecords(
            "SELECT form_name, field_name, element_enum, misc 
                FROM redcap_metadata 
                WHERE project_id=?
                AND ( element_type='calc'
                OR  ( element_type='text' AND (POSITION('@CALCTEXT' IN `misc`) > 0 OR POSITION('@CALCDATE' IN `misc`) > 0) ))
                ORDER BY field_order",
            [ $project_id ]
        );
    }

    private function getFormNameForFieldName( $project_id, $field_name ){

        return $this->fetchRecords(
            "SELECT form_name FROM redcap_metadata WHERE project_id=? AND field_name=? LIMIT 1",
            [ 
                $project_id, 
                $field_name 
            ]
        )[0]['form_name'];
    }

    /**
     * A simple and possibly dangerous parser
     * Looks for [field_name] patterns in calc expressions.
     * Includes special handling to extract field names from [event_name][field_name] patterns and [field_name(*)] patterns.
     * 
     * @param mixed $project_id 
     * @param mixed $calcExpression 
     * @return array 
     * @throws Exception 
     */
    private function parseExpressionFields( $project_id, $calcExpression ){

        if ( !strlen($calcExpression) ) return [];

        $expression_fields = [];

        $i = strpos( $calcExpression, "[" ); // the start of a [field_name] pattern (or [event_name][field_name] pattern)
        $j = 0;
        $len_1 = strlen( $calcExpression ) - 1;

       //$this->logDebugMessage('parseExpressionFields', $calcExpression);

        while ( $i !== false && $j < $len_1 ){

            $j = strpos( $calcExpression, "]", $i ); // the end of the [field_name] pattern

            if ( $j === false ){

                break;
            } 
            else {

                // make sure this is not the end of the event part of a compound [event][field] pattern
                if ( $j === $len_1 || $calcExpression[$j+1] !== "[" ) {

                    $field_name = substr( $calcExpression, $i+1, $j-$i-1 );

                    // truncate (*) suffix for !#@$ multiselects
                    $m = strpos($field_name, "(");

                    if ( $m !== false ){

                        $field_name = trim(substr($field_name, 0, $m));
                    }

                    // ignore magic tokens etc
                    if ( $this->isValidFormField($project_id, $field_name) && !in_array($field_name, $expression_fields) ){

                        $expression_fields[] = $field_name;
                    }
                }
            }

            $i = strpos( $calcExpression, "[", $j ); // repeat search for next [field_name] pattern
        }

        return $expression_fields;
    }

    /**
     * A simple and possibly dangerous parser
     * Looks for [field_name] patterns in calc expressions.
     * Includes special handling to extract field names from [event_name][field_name] patterns and [field_name(*)] patterns.
     * This version extracts the event name as well as the the field name.
     * 
     * @param mixed $project_id 
     * @param mixed $calcExpression 
     * @return array 
     * @throws Exception 
     */
    private function parseExpressionFieldsE( $project_id, $calcExpression ){

        if ( !strlen($calcExpression) ) return [];

        $expression_fields = [];

        $i = strpos( $calcExpression, "[" ); // the start of a [field_name] pattern (or [event_name][field_name] pattern)
        $j = 0;
        $len_1 = strlen( $calcExpression ) - 1;

       //$this->logDebugMessage('parseExpressionFields', $calcExpression);

        $field_name = "";
        $event_name = "";

        while ( $i !== false && $j < $len_1 ){

            $j = strpos( $calcExpression, "]", $i ); // the end of the [field_name] pattern

            if ( $j === false ){

                break;
            } 
            else {

                // extract the event name if applicable
                if ( $calcExpression[$j+1] === "[" ) {

                    $event_name = substr( $calcExpression, $i+1, $j-$i-1 );

                    $i = $j+1; // the start of the [field_name] pattern

                    $j = strpos( $calcExpression, "]", $i ); // the end of the [field_name] pattern

                    if ( $j === false ){

                        break;
                    }
                }

                $field_name = substr( $calcExpression, $i+1, $j-$i-1 );

                // truncate (*) suffix for !#@$ multiselects
                $m = strpos($field_name, "(");

                if ( $m !== false ){

                    $field_name = trim(substr($field_name, 0, $m));
                }

                // ignore magic tokens etc
                if ( $this->isValidFormField($project_id, $field_name) ){

                    // make sure event_name, field_name pair is unique
                    $found = false;
                    foreach ($expression_fields as $ef) {
                        if ($ef['event_name'] === $event_name && $ef['field_name'] === $field_name) {
                            $found = true;
                            break;
                        }
                    }

                    // add the pair if not found
                    if (!$found) {
                        $expression_fields[] = ['event_name' => $event_name, 'field_name' => $field_name];
                    }   
                }
            }

            $field_name = "";
            $event_name = "";
            $i = strpos( $calcExpression, "[", $j ); // repeat search for next [field_name] pattern
        }

        return $expression_fields;
    }

    private function isValidFormField( $project_id, $field_name ){

        return ( $this->getFormNameForFieldName($project_id, $field_name) ) ? true:false;
    }

    public function getArmNumForEvent( $project_id, $event_id )
    {
        $sql = "SELECT a.arm_num
        FROM redcap_events_arms a
            INNER JOIN redcap_events_metadata e ON e.event_id=? AND e.arm_id=a.arm_id
        WHERE a.project_id=?
        LIMIT 1";

        $params = [ $event_id, $project_id ];

        $x = $this->fetchRecords($sql, $params);

        if ( !$x ){

            return null;
        }

        return $x[0]['arm_num'];
    }

    private function getREDCapEventsForForm( $project_id, $form_name )
    {
        if ( REDCap::isLongitudinal() ) { 

            $sql = "SELECT e.event_id
            FROM redcap_events_metadata e
                INNER JOIN redcap_events_arms a ON a.arm_id=e.arm_id
                INNER JOIN redcap_events_forms ef ON ef.form_name=? AND ef.event_id=e.event_id
            WHERE a.project_id=?
            ORDER BY e.day_offset, e.event_id";

            $params = [ $form_name, $project_id ];

        } else {

            $sql = "SELECT e.event_id
            FROM redcap_events_metadata e
              INNER JOIN redcap_events_arms a ON a.arm_id=e.arm_id
            WHERE a.project_id=?
            ORDER BY e.day_offset, e.event_id
            LIMIT 1";

            $params = [ $project_id ];
        }
        
        return array_column( $this->fetchRecords($sql, $params), 'event_id' );
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

    private function logDebugMessage($msgcat, $msg) 
    {   
        if ( !$this->ALLOW_DEBUG_LOGGING ) return false;
        
        $sql = "INSERT INTO `".$this->LOG_DEBUG_TABLE."` (project_id, debug_message, debug_message_category) VALUES (?,?,?)";
 
        return $this->query($sql, [$this->getProjectId(), $msg, $msgcat]);
    }

    /**
     * 
     * @param int $project_id 
     * @return string 
     */
    public function getDataTable( int $project_id=0 ):string {

        if ( !is_numeric($project_id) || $project_id < 1 ) $project_id = (int) $this->getProjectId();

        if ( method_exists('REDCap', "getDataTable") ) {
            
            return REDCap::getDataTable($project_id);
        }

        return "redcap_data";
    }
}