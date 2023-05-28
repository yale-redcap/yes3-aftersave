<?php

$module = new \Yale\Yes3Aftersave\Yes3Aftersave();

$PID = $module->getProjectId();
$ProjTitle = $module->getProject()->getTitle();

$module->initializeJavascriptModuleObject();

?>

<style>

    @media print {
        #west, #south {
            display: none;
        }
    }

    i.y3as-action-icon {

        font-size: 1.5rem;
        color: slategray;
        opacity: 50%;
    }

    i.y3as-action-icon:hover {

        cursor: pointer;
        color: dodgerblue;
        opacity: 100%;
    }

    .y3as-response {

        display:none;
        margin-top: 10px;
    }

    input.y3as-button {

        height: 2rem;
        border-radius: 1rem;
        padding-left: 1rem;
        padding-right: 1rem;
        font-weight: 800;
        background-color: white;
        color: coral;
        border: 2px solid coral;
    }

    input.y3as-button:hover {
        background-color: coral;
        color: white;
    }

</style>

<script>

    Y3AS = {

        jmo: <?= $module->getJavascriptModuleObjectName() ?>,

        setConfig: ()=>{

            Y3AS.jmo.ajax( 'setConfig', {}).then(function(response){

                //console.log(response);

                const dash = "-";
                const ddash = "=";

                let txta = "";
                let txtd = "";

                let ka = 0;
                let kd = 0;

                let s = "";

                for(let j=0; j<response.aftersave_forms.length; j++){

                    ka++;

                    txta += "\n" + dash.repeat(response.aftersave_forms[j].length) 
                        + "\n" + response.aftersave_forms[j] 
                        + "\n" + dash.repeat(response.aftersave_forms[j].length) 
                        + "\nhas fields that figure in the calculation of:"
                    ;

                    s = "?";

                    for(let i=0; i<response.field_bridge.length; i++){

                        if ( response.field_bridge[i].aftersave_form_name === response.aftersave_forms[j] 
                                && s !== response.field_bridge[i].dependent_field_name ){

                            s = response.field_bridge[i].dependent_field_name;

                            txta += "\n[" + response.field_bridge[i].dependent_field_name + "] on " + response.field_bridge[i].dependent_form_name;
                        }
                    }
                    
                    txta += "\n";                        
                }

                txta = ka + " FORM(S) AFFECT CALCULATIONS IN OTHER FORMS\nSaving any of these forms will trigger Aftersave.\n" + txta;

                $('#y3as-response-aftersave_forms').text(txta);

                for(let j=0; j<response.dependent_forms.length; j++){

                    kd++;

                    txtd += "\n" + dash.repeat(response.dependent_forms[j].length) 
                        + "\n" + response.dependent_forms[j] 
                        + "\n" + dash.repeat(response.dependent_forms[j].length) 
                        + "\nhas calculations that depend on:"
                    ;

                    s = "?";

                    for(let i=0; i<response.field_bridge.length; i++){

                        if ( response.field_bridge[i].dependent_form_name === response.dependent_forms[j] 
                                && s !== response.field_bridge[i].aftersave_field_name ){
                            
                            s = response.field_bridge[i].aftersave_field_name;

                            txtd += "\n[" + response.field_bridge[i].aftersave_field_name + "] on " + response.field_bridge[i].aftersave_form_name;
                        }
                    }

                    txtd += "\n";
                }

                txtd = kd + " FORM(S) HAVE CALCULATIONS THAT DEPEND ON OTHER FORMS\nThese forms will be calculated and saved by Aftersave.\n" + txtd;

                $('#y3as-response-dependent_forms').text(txtd);

                $(".y3as-response").show();

            }).catch(function(err){

                console.error(err);
                //alert(`Aftersave reports an AJAX error: ${err}.`);
            })
        }


    }
</script>

<div class='container'>

    <div class="row">

        <div class='col-lg-12'>

            <h3>YES3 Aftersave</h3>
            <h6><?= $ProjTitle ?>&nbsp;&nbsp;pid#<?= $PID ?></h6>

            <p class="d-print-none">Click the button below to have YES3 Aftersave determine the forms to which to attach the "after save" calculation execution.
            These will be all forms having fields that figure in calculations on <em>other</em> forms.</p>

            <p class="d-print-none">This program will also determine which forms have calculated fields that depend on fields on other forms.
                The calculated fields on these <em>dependent</em> forms may or may not be recalculated and saved by YES3 Aftersave,
                depending on options that you set in the EM configuration settings.</p>

            <p class="d-print-none">The default is for YES3 Aftersave to be triggered for non-empty dependent forms,
            which is the REDCap default for autocalculations.
            You may choose to have YES3 Aftersave triggered even for empty forms, or to never be triggered for selected forms.</p>

            <p class="d-print-none">YES3 Aftersave will carry out a multi-pass recalculation, so that complex expressions involving interdependent calculated fields should be resolved correctly.</p>

        </div>
    </div>

    <div class="row" class="d-print-none">
        <div class="col-lg-6">
            <input type="button" class="y3as-button d-print-none" onclick="Y3AS.setConfig()" value="Splash on some YES3 Aftersave!" />
        </div>
        <div class="col-lg-6">
            <i class="fa-solid fa-print y3as-action-icon float-end d-print-none y3as-response" title="print this report" onclick="window.print()"></i>
        </div>
    </div>

    <div class="row">

        <div class="col-lg-6">
            <pre class="y3as-response" id="y3as-response-aftersave_forms"></pre>
        </div>

        <div class="col-lg-6">
            <pre class="y3as-response" id="y3as-response-dependent_forms">
        </div>
        
    </div>

</div>
