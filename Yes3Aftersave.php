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
    public  $ALLOW_DEBUG_LOGGING = true;

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
        // only proceed if this is a registered 'Aftersave' form

        $this->logDebugMessage('redcap_save_record:args', print_r(func_get_args(), true));

        $aftersave_forms = json_decode($this->getProjectSetting('aftersave-forms-json'));

        $this->logDebugMessage('redcap_save_record:aftersave_forms', print_r($aftersave_forms, true));

        if ( is_array($aftersave_forms) && in_array($instrument, $aftersave_forms) ){

            // list of all (trigger, dependent) field pairs
            $field_bridge = json_decode($this->getProjectSetting('field-bridge-json'), true);
            // list of all forms having calculated fields that depend on fields in other ('aftersave') forms
            $dependent_forms = $this->getProjectSetting('yes3-dependent-form');
            // calculation actions for dependent forms (ignore, nonempty, always)
            $aftersave_actions = $this->getProjectSetting('yes3-dependent-form-aftersave-action');

            $this->logDebugMessage('redcap_save_record:field_bridge', print_r($field_bridge, true));
            $this->logDebugMessage('redcap_save_record:dependent_forms', print_r($dependent_forms, true));
            $this->logDebugMessage('redcap_save_record:aftersave_actions', print_r($aftersave_actions, true));

            if ( is_array($field_bridge) && is_array($dependent_forms) ){

                $this->calculateDependentForms( $project_id, $record, $repeat_instance, $instrument, $dependent_forms, $aftersave_actions, $field_bridge );
            }
        }
    }

    public function calculateDependentForms( $project_id, $record, $repeat_instance, $skipThisForm, $dependent_forms, $aftersave_actions, $field_bridge ){

        $this->logDebugMessage('calculateDependentForms:args', print_r(func_get_args(), true));

        $k = 0;
    
        for($i=0; $i<count($dependent_forms); $i++){

            if ( $dependent_forms[$i] !== $skipThisForm ){

                /**
                 * Build list of dependent fields to be recalculated for this form
                 * from the many-to-many bridging table
                 */
                $dependent_fields = [];

                foreach($field_bridge as $pair){

                    if ( $pair['dependent_form_name']===$dependent_forms[$i] && !in_array($pair['dependent_field_name'], $dependent_fields) ){

                        $dependent_fields[] = $pair['dependent_field_name'];
                    }
                }

                foreach( $this->getREDCapEventsForForm($project_id, $dependent_forms[$i]) as $event_id ){

                    $k += $this->calculateDependentForm( $project_id, $record, $event_id, $repeat_instance, $dependent_forms[$i], $aftersave_actions[$i], $dependent_fields );
                }
            }
        }

        return $k;
    }

    private function calculateDependentForm( $project_id, $record, $event_id, $repeat_instance, $form_name, $aftersave_action, $fields ){

        $this->logDebugMessage('calculateDependentForm:args', print_r(func_get_args(), true));

        /**
         * If needed, initialize the form by saving the completion status,
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

            $this->logDebugMessage('calculateDependentForm:saveComplete', print_r($rc, true));
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

        return $iter;
    }

    private function formIsInitialized( $project_id, $form_name, $record, $event_id, $repeat_instance=1 ){

        $complete_field = $form_name . '_complete';

        $sql = "SELECT `value` AS `complete` FROM redcap_data WHERE project_id=? AND `record`=? AND `event_id`=? AND ifnull(`instance`, 1)=? AND field_name=? LIMIT 1";
        $params = [ $project_id, $record, $event_id, $repeat_instance, $complete_field ];

        $x = $this->fetchRecords($sql, $params);

        if ( !$x ){

            return false;
        }

        return ( $x[0]['complete'] ) ? true:false;
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id){

        if ( $action==="setConfig" ) return $this->setConfig( $project_id );

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
     * a simple and possibly dangerous parser
     * 
     * @param mixed $project_id 
     * @param mixed $calcExpression 
     * @return array 
     * @throws Exception 
     */
    private function parseExpressionFields( $project_id, $calcExpression ){

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

                // make sure not the event part of a compound [event][field] spec
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

            $i = strpos( $calcExpression, "[", $j );
        }

        return $expression_fields;
    }

    private function isValidFormField( $project_id, $field_name ){

        return ( $this->getFormNameForFieldName($project_id, $field_name) ) ? true:false;
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
}