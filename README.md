# YES3 Aftersave

Peter Charpentier
<br>CRI Web Tools LLC
<br>Yale University

June 2023

YES3 Aftersave ensures that affected calculated fields on forms other than a form being saved are updated and saved in real time.

It works by first identifying all forms having fields that figure in calculated fields on other forms. When any of these "aftersave" forms is saved, all of the affected calculated fields on "dependent" forms are updated and saved. The default REDCap recalculation behavior of bypassing empty forms can be overriden for any dependent form.

A multipass recalculation is performed, that should ensure that complex expressions involving interdependent calculated fields are resolved correctly.

> YES3 Aftersave is not a substitute for Rule H, or for Adam Nunez' **Recalculate** EM. YES3 Aftersave performs real-time, multipass record-level recalculations only for affected calculated fields on forms other than the form being saved.

## SETUP

Setting up YES3 Aftersave is a two step process, as explained below. This process should be repeated whenever a calculated field is added, modified or removed in your project.

### Step 1. Open the "YES3 Aftersave" EM link and run the YES3 Aftersave utility

This utility program will identify all the "aftersave" forms in your project, and all the dependent forms and fields, and populate or revise the EM configuration settings with this information.

To run the YES3 Aftersave Utility, open the "YES3 Aftersave" EM link and click the button labeled "Splash on some YES3 Aftersave!".

![image of the button to run the YES3 Aftersave utility](./media/splash-on.png)

 (sorry, we couldn't resist)

### Step 2. Review and adjust the EM configuration settings

In the YES3 Aftersave EM configuration settings you will find all of the dependent forms listed. For each one, you may change the behavior of YES3 Aftersave recalculations. The default is for YES3 Aftersave to perform recalculations only if a form is non-empty. This is the REDCap default for recalculations, including Rule H. 

In our studies, we often have a "tracking form" that consists entirely of calculated fields for monitoring participant study progress and indicators for controlling workflows. Under the normal REDCap behavior these forms would have to be manually saved for each record, even though there's never any data entry. For such forms we offer the "always recalculate" option.

You may also choose to have YES3 Aftersave ignore any dependent form.

> You should not add or remove dependent forms manually through the EM configuration settings. Let the YES3 Aftersave utility do that.