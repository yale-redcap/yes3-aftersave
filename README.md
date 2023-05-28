# YES3 Aftersave

YES3 Aftersave ensures that calculated fields across all forms are updated and saved in real time.

It works by first identifying all forms having fields that figure in calculated fields on other forms. When any of these 'aftersave' forms is saved, all of the affected calculated fields on 'dependent' forms are updated and saved. The default REDCap recalculation behavior of bypassing empty forms can be overriden for any dependent form.

A multipass recalculation is performed, that should ensure that complex expressions involving interdependent calculated fields are resolved correctly.

> YES3 Aftersave is not a substitute for Rule H, or for Adam Nunez' ** Recalculate ** EM. YES3 Aftersave performs real-time, multipass record-level recalculations only for calculation expressions that involve forms other than the form being saved.

## SETUP

Setting up YES3 Aftersave is a two step process, as follows. This process should be repeated whenever a calculated field is added, modified or removed in your project.

### Step 1. Run the "YES3 Aftersave" EM link

This utility program will identify all the 'aftersave' forms in your project, and all the dependent forms and fields, and populate the EM configuration settings with this information.

### Step 2. Review and adjust the EM configuration settings

In the YES3 Aftersave EM configuration settings you will find all of the dependent forms listed. For each one, you may change the behavior of YES3 Aftersave recalculations. The default is for YES3 Aftersave to perform recalculations only if a form is non-empty. This is the REDCap default for recalculations, including Rule H. YES3 Aftersave offers the option to always perform the recalculations, or to ignore the dependent form and never force a recalculation. 

>In our studies, we often have a 'tracking form' that consists entirely of calculated fields for participant study progress and indicators for controlling workflows. You might want the 'always calculate' option for such a form. Otherwise the form would have to be manually saved for each record, even though there's never any data entry.

> You should not add dependent forms manually to the EM configuration settings, or remove them. Let the YES3 Aftersave utility do that.