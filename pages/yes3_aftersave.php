<?php

$module = new \Yale\Yes3Aftersave\Yes3Aftersave();

$module->initializeJavascriptModuleObject();

?>

<style>

    table.y3as-aftersave td {

        vertical-align: top;
        padding-right: 10px;
        padding-top: 10px;
        padding-left: 0;
        padding-bottom: 10px;
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

        selectForms: ()=>{

            Y3AS.jmo.ajax( 'selectForms', {}).then(function(response){

                console.log(response);

                const dash = "-";
                const ddash = "=";

                let txta = "";
                let txtd = "";

                let ka = 0;
                let kd = 0;

                for(const form_name in response.aftersaveForms){

                    ka++;

                    txta += "\n" + dash.repeat(form_name.length) 
                        + "\n" + form_name 
                        + "\n" + dash.repeat(form_name.length) 
                        + "\nhas fields that figure in the calculation of:"
                        + "\n" + response.aftersaveForms[form_name];
                }

                txta = ka + " FORM(S) AFFECT CALCULATIONS IN OTHER FORMS\nSaving any of these forms will trigger Aftersave.\n" + txta;

                $('#y3as-response-aftersaveForms').text(txta);

                for(const dep_form_name in response.dependentForms){

                    kd++;

                    txtd += "\n" + dash.repeat(dep_form_name.length) 
                        + "\n" + dep_form_name 
                        + "\n" + dash.repeat(dep_form_name.length) 
                        + "\nhas calculations that depend on:"
                        + "\n" + response.dependentForms[dep_form_name];
                }

                txtd = kd + " FORM(S) HAVE CALCULATIONS THAT DEPEND ON OTHER FORMS\nThese forms will be calculated and saved by Aftersave.\n" + txtd;

                $('#y3as-response-dependentForms').text(txtd);

            }).catch(function(err){

                console.error(err);
                alert(`Aftersave reports an AJAX error: ${err}.`);
            })
        }


    }
</script>

<h3>YES3 Aftersave</h3>

<p>Click the button below to have YES3 Aftersave determine the forms to which to attach the "after save" calculation execution.
These will be all forms having fields that figure in calculations on <em>other</em> forms.</p>

<p>This program will also determine which forms have calculated fields that depend on fields in other forms.
    The calculated fields on these <em>dependent</em> forms will be recalculated and saved by the "after save" process.</p>

<p>The default is that all dependent forms are recalculated and saved <em>even if they are empty</em>.
You may prevent this action for any dependent form in the standard EM configuration.</p>

<p>
    <input type="button" class="y3as-button" onclick="Y3AS.selectForms()" value="Splash on some Aftersave!" />
</p>

<table class="y3as-aftersave">

    <tr>
        <td><pre id="y3as-response-aftersaveForms"></pre></td>
        <td><pre id="y3as-response-dependentForms"></pre></td>
    </tr>

</table>
