<?php

/*
   ------------------------------------------------------------------------
   Derrick Smith - PHP SAML Plugin
   Copyright (C) 2014 by Derrick Smith
   ------------------------------------------------------------------------

   LICENSE

   This file is part of phpsaml project.

   PHP SAML Plugin is free software: you can redistribute it and/or modify
   it under the terms of the GNU Affero General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   phpsaml is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   GNU Affero General Public License for more details.

   You should have received a copy of the GNU Affero General Public License
   along with phpsaml. If not, see <http://www.gnu.org/licenses/>.

   ------------------------------------------------------------------------

   @package   phpsamlconfig
   @author    Chris Gralike
   @co-author Derrick Smith
   @copyright Copyright (c) 2018 by Derrick Smith
   @license   AGPL License 3.0 or (at your option) any later version
              http://www.gnu.org/licenses/agpl-3.0-standalone.html
   @since     0.1

   @changelog rewrite and restructure removing context switches and improving readability and maintainability
   @changelog breaking config up into methods for maintainability and unit testing purposes.

   ------------------------------------------------------------------------
 */

// Header guard
if (!defined("GLPI_ROOT")) {
    die("Sorry. You can't access directly to this file");
}

class PluginPhpsamlConfig extends CommonDBTM
{
    /**
     * defines the rights a user must posses to be able to access this menu option in the rules section
     * @var string
     **/
	public static $rightname = "plugin_phpsaml_config";


    /**
     * Defines where the setup HTML template is located
     * @var string
     **/
    private $tpl = '../tpl/configForm.html';


    /**
     * Stores a copy of the HTML template in memory for processing
     * @var string
     **/
    private $htmlForm = null;


    /**
     * Stores a copy of the form values to be injected into the final HTML form
     * @var array
     **/
    private $formValues = [];


    /**
     * The amount of fields we expect from the database
     * Change value for unit testing
     * @var int
     **/
    private $expectedItems = 20;


    /**
     * Where to get the current version
     * Change value for unit testing
     * @var string
     **/
    private $PhpsamlGitAtomUrl = 'https://github.com/derricksmith/phpsaml/releases.atom';

    /**
     * Stores a copy of the phpSaml Database Configuration
     * @var array
     **/
    private $config = [];


    /**
     * Registers if a fatal error occured during execution;
     * @var array
     **/
    private $fatalError = false;

    /**
     * Registers if a fatal error occured during execution;
     * @var array
     **/
    private $warningError = false;


    /**
     * Registers errors that occured in a friendly format;
     * @var array
     **/
    private $errorMsgs = false;


    /**
     *
     * Generate the configuration htmlForm and return it.
     * @return string $htmlForm
     * @since 1.2.1
     */
    public function showForm($id, array $options = [])
    {
        // Populate current configuration
        if ($this->config = $this->getConfig($id)) {
            // Call the form field handlers
            if (is_array($this->config)) {
                foreach ($this->config as $method => $current) {
                    if (method_exists($this, $method)) {
                        // Handle property
                        $this->$method($current);
                    } else {
                        if ($method != 'valid') {
                            $this->registerError(__("Warning: No handler found for configuration item: $method in ".__class__." db corrupted?", 'phpsaml'));
                        }
                    }
                }
            } else {
                $this->registerError("Error: db config did not return required config array", true);
            }

            // Generate and show form
            return $this->generateForm(true);
        }
    }


    /**
     *
     * process $_POST values of the updated form. On error it will regenerate the form with
     * errors and provided values and will not process the form and will loop untill the errors
     * are fixed. Navigating away will reset the form.
     *
     * @param void
     * @return string|void
     * @since 1.2.1
     * @todo add option to reset the form with configuration items calling discarding the POST and caling 'show form'
     */
    public function processChanges()
    {
        // populate config
        $id = (isset($_POST['id']) && is_numeric($_POST['id']) && (strlen($_POST['id']) < 10)) ? (int) $_POST['id'] : '1';
        $this->config = $this->getConfig($id);

        // Use the POST values to iterate through the
        // handlers and make them validate the input.
        foreach ($_POST as $method => $value)
        {
            if (array_key_exists($method, $this->config)) {
                // We can safely call this valid method
                $this->$method($value);
            }
        }

        // If we have errors, then show the form
        // else process the update.
        if ($this->warningError || $this->fatalError) {
            return $this->generateForm();
        } else {
            $this->update($_POST);
            Html::back();
        }
    }


    /**
     *
     * Gets the current configuration from the database. It will first query the columns of the configuration
     * table. It will then use these columns to fetch all the related database configuration values and place them
     * in a structured array. Finally this structured array is returned. The caller should evaluate the 'valid' array
     * key to validate the configuration array is usable.
     *
     * @param string $id
     * @return array $config
     * @since 1.2.1
     * @todo reafactor method property to datatype INT
     */
    public function getConfig(string $id = '1')
	{
        global $DB;
        $config['valid'] = true;

		$sql = 'SHOW COLUMNS FROM '.$this->getTable();
		if ($result = $DB->query($sql)) {
            if ($this->getFromDB($id)) {
                while ($data = $result->fetch_assoc()) {
                    $config[$data['Field']] =  $this->fields[$data['Field']];
                }
            } else {
                $this->registerError('Phpsaml could not retrieve configuration values from database.', true);
                $config['valid'] = false;
            }
        } else {
            $this->registerError('Phpsaml was not able to retrieve configuration columns from database', true);
            $config['valid'] = false;
        }
        // Test if config exists;
        if (count($config) <> $this->expectedItems) {
            $this->registerError('Phpsaml expected '.$this->expectedItems.' configuration items but got '.count($config).' items instead');
            $config['valid'] = false;
        }

        return $config;
	}


    /**
     *
     * The generateForm method is called by the showForm method. It should only be called after all
     * configuration handlers are executed. It will populate all generic form properties, load the
     * configuration template file and replace all template placeholders with the populated fields.
     * It will disable all form fields if a fatal error was reported using the fatalError class property.
     * Finally it will echo the generated htmlForm.
     *
     * @param bool $return   // return the generated htmlform as string
     * @return string|bool
     * @since 1.2.1
     */
    private function generateForm()
    {
        global $CFG_GLPI;

        // Read the template file containing the HTML template;
        if (file_exists($this->tpl)) {
            $this->htmlForm = file_get_contents($this->tpl);
        }

        // Declare general form values
        $formValues = [
            '[[AVAILABLE]]'              => __('Available', 'phpsaml'),
            '[[SELECTED]]'               => __('Selected', 'phpsaml'),
            '[[GLPI_ROOTDOC]]'           => $CFG_GLPI["root_doc"],
            '[[TITLE]]'                  => __("PHP SAML Configuration", "phpsaml"),
            '[[HEADER_GENERAL]]'         => __("General", "phpsaml"),
            '[[HEADER_PROVIDER]]'        => __("Service Provider Configuration", "phpsaml"),
            '[[HEADER_PROVIDER_CONFIG]]' => __("Identity Provider Configuration", "phpsaml"),
            '[[HEADER_SECURITY]]'        => __("Security", "phpsaml"),
            '[[SUBMIT]]'                 => __("Update", "phpsaml"),
            '[[CLOSE_FORM]]'             => Html::closeForm(false)
        ];
        // Merge the values in the central array.
        $this->formValues = array_merge($this->formValues, $formValues);

        // Process generic errors if any
        if (is_array($this->errorMsgs)) {

            // Process the error messages;
            $nice = '';
            foreach ($this->errorMsgs as $k => $errmsg) {
                $nice .= $errmsg.'<br>';
            }

            $this->formValues['[[ERRORS]]'] = ' <div class="alert mb-o rounded-0 border-top-0 border-bottom-0 border-right-0 full-width" role="alert">'.$nice.'</div>';
        } else {
            $this->formValues['[[ERRORS]]'] = '';
        }

        // Disable the form if a fatal was generated.
        $this->htmlForm = ($this->fatalError) ? str_replace('[[DISABLED]]','DISABLED',$this->htmlForm) : str_replace('[[DISABLED]]','',$this->htmlForm);

        if ($html = str_replace(array_keys($this->formValues), array_values($this->formValues), $this->htmlForm)) {
            // Clean all remaining placeholders
            $html = preg_replace('/\[\[.*\]\]/', '', $html);
            return $html;
        } else {
            return false;
        }
    }

    
    /**
     *
     * Adds an error message to the errorMsgs class property. The errorMsgs class property is iterated by the generateForm
     * method to inject all errors into the top header of the htmlForm. If fatal is set to true the generateForm method will
     * disable all form elements and make sure an insert/update can no longer be performed.
     *
     * @param string $errorMsg  the error message 
     * @param string $field     generate field specific error.
     * @param bool $fatal       is it fatal?
     * @param bool $warning     is it a warning?
     * @return void
     * @since 1.2.1
     */
    private function registerError(string $errorMsg, string $field='', bool $fatal=false, bool $warning=true)
    {
        // Warning will prevent update from being executed allowing form changes;
        $this->warningError = ($warning) ? true : $this->warningError;

        //Fatal will prevent update from being executed disabling forms.
        $this->fatalError = ($fatal) ? true : $this->fatalError;

        // Create field specific error else generate generic error
        if (!empty($field)) {
            $spaceholder = '[['.strtoupper($field).']]';
            $this->formValues[$spaceholder] = __($errorMsg, 'phpsaml');
        }else{
            $this->errorMsgs[] = $errorMsg;
        }
    }

     /**
     *
     * Adds an error message to the errorMsgs class property. The errorMsgs class property is iterated by the generateForm
     * method to inject all errors into the top header of the htmlForm. If fatal is set to true the generateForm method will
     * disable all form elements and make sure an insert/update can no longer be performed.
     *
     * @param string $certString  the error message
     * @return array $certDetails
     * @since 1.2.1
     * @todo clean up, functional not fluid.
     */
    public function validateAndParseCertString(string $certString)
    {
        // Clean preprossors entities (very anoying these)
        $cert = preg_replace('/\r\n|\r|\n/', '', $certString);

        // Do some basic validations
        $validationErrors['BEGIN_TAG_PRESENT']  = (!preg_match('/-+BEGIN CERTIFICATE-+/', $cert)) ? false : true;
        $validationErrors['END_TAG_PRESENT']    = (!preg_match('/-+END CERTIFICATE-+/', $cert)) ? false : true;

        // Match the certificate elements using non greedy payload search
        preg_match('/(-+BEGIN CERTIFICATE-+)(.+?)(-+END CERTIFICATE-+)/', $cert, $m);

        // There should be exactly 4 matches!
        if (count($m) == 4) {
            // Reconstruct the certificate including the correct openssl CRLF
            $validationErrors['CERT_SEMANTICS_VALID'] = true;
            $cert = $m['1'].chr(10).$m['2'].chr(10).$m['3'];
        } else {
            $validationErrors['CERT_SEMANTICS_VALID'] = false;
        }
        

        // Try to parse the reconstructed certificate.
        if (extension_loaded('openssl')) {
            if ($pCert = openssl_x509_parse($cert)) {
                $validationErrors['CERT_LOGIC_VALID'] = true;
            } else {
                $validationErrors['CERT_LOGIC_VALID'] = false;
            }
        } else {
            $validationErrors['CERT_LOGIC_VALID'] = 'openssl not loaded';
        }

        if ($pCert) {
            // Populate results
            $n = new DateTimeImmutable('now');
            $t = (array_key_exists('validTo', $pCert)) ? DateTimeImmutable::createFromFormat("ymdHisT", $pCert['validTo']) : false;
            $f = (array_key_exists('validFrom', $pCert)) ? DateTimeImmutable::createFromFormat("ymdHisT", $pCert['validFrom']) : false;
            $d = $n->diff($t);

            //make clean using [ ] arrays.
            $results['msgs'] = $validationErrors;
            $results['certStr'] = $cert;
            $results['certDetails']['cn']   = (array_key_exists('subject', $pCert) && array_key_exists('CN', $pCert['issuer'])) ? $pCert['subject']['CN'] : false;
            $results['certDetails']['isO']  = (array_key_exists('issuer', $pCert) && array_key_exists('O', $pCert['issuer'])) ? $pCert['issuer']['O'] : false;
            $results['certDetails']['isCN'] = (array_key_exists('issuer', $pCert) && array_key_exists('CN', $pCert['issuer'])) ? $pCert['issuer']['CN'] : false;
            $results['validTo'] = $t->format('Y-m-d');
            $results['validFrom'] = $t->format('Y-m-d');
            $results['certAge'] = $d->format('%R%a');
        } else {
            $results['msgs'] = $validationErrors;
            $results['certStr'] = $cert;
            $results['certDetails'] = false;
            $results['validTo'] = false;
            $results['validFrom'] = false;
            $results['certAge'] = false;
        }
        return $results;
    }



    /**********************************
     *
     * Evaluates the enforced property
     * and populates the form template
     *
     * @param int $cValue   configuration value to process
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function enforced(int $cValue)
    {
        // Validate the input value
        if (!preg_match('/[0-1]/', $cValue)) {
            $this->registerError("Enforced can only be 1 or 0", 'ENFORCED_ERROR');
        }

        // Do lable translations
        $formValues = [
            '[[ENFORCED_LABEL]]' =>  __("Plugin Enforced", "phpsaml"),
            '[[ENFORCED_TITLE]]' =>  __("Toggle 'yes' to enforce Single Sign On for all login sessions", "phpsaml"),
            '[[ENFORCED_SELECT]]'=> ''
        ];

        // Generate select options
        $options = [ 1 => __('Yes', 'phpsaml'),
                     0 => __('No', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[ENFORCED_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);

    }


     /**
     *
     * Evaluates the strict property
     * and populates the form template
     *
     * @param int $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function strict(int $cValue)
    {
        // Validate the input value
        if (!preg_match('/[0-1]/', $cValue)) {
            $this->registerError("Strict can only be 1 or 0", 'STRICT_ERROR');
        }

        // Declare template labels
        $formValues = [
            '[[STRICT_LABEL]]' =>  __("Strict", "phpsaml"),
            '[[STRICT_TITLE]]' =>  __("If 'strict' is True, then PhpSaml will reject unencrypted messages", "phpsaml"),
            '[[STRICT_SELECT]]'=>  ''
        ];

        // Generate select options
        $options = [ 1 => __('Yes', 'phpsaml'),
                     0 => __('No', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[STRICT_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the debug property
     * and populates the form template
     *
     * @param int $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function debug(int $cValue)
    {
        // Validate the input value
        if (!preg_match('/[0-1]/', $cValue)) {
            $this->registerError("Debug can only be 1 or 0", 'DEBUG_ERROR');
        }

        // Declare template labels
        $formValues = [
            '[[DEBUG_LABEL]]' =>  __("Debug", "phpsaml"),
            '[[DEBUG_TITLE]]' =>  __("Toggle yes to print errors", "phpsaml"),
            '[[DEBUG_SELECT]]'=> ''
        ];

        // Generate options
        $options = [ 1 => __('Yes', 'phpsaml'),
                     0 => __('No', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[DEBUG_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the sp certificate property
     * and populates the form template
     *
     * @param int $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function jit(int $cValue)
    {
        // Validate the input value
        if (!preg_match('/[0-1]/', $cValue)) {
            $this->registerError("Jit can only be 1 or 0", 'JIT_ERROR');
        }

        // Declare template labels
        $formValues = [
            '[[JIT_LABEL]]' =>  __("Just In Time (JIT) Provisioning", "phpsaml"),
            '[[JIT_TITLE]]' =>  __("Toggle 'yes' to create new users if they do not already exist.  Toggle 'no' will cause an error if the user does not already exist in GLPI.", "phpsaml"),
            '[[JIT_SELECT]]'=> ''
        ];

        // Generate options
        $options = [ 1 => __('Yes', 'phpsaml'),
                     0 => __('No', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[JIT_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the sp certificate property
     * and populates the form template
     *
     * @param string $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     * @todo suspected that GLPI is applying input- output filters that might break certificate on successive updates.
     */
    protected function saml_sp_certificate(string $cValue)
    {
        $cert = $this->validateAndParseCertString($cValue);
        $validationErrors = (!$cert['msgs']['BEGIN_TAG_PRESENT']) ? 'The certificate BEGIN tag should be present<br>' : '';
        $validationErrors = (!$cert['msgs']['END_TAG_PRESENT']) ? 'The certificate END tag should be present<br>' : '';

        if (is_array($cert['certDetails'])) {
            $cer = "Configured SPD certificate was issued by: {$cert['certDetails']['isCN']} for: {$cert['certDetails']['cn']} and has {$cert['certAge']} days left";
        } else {
            $cer = 'No certificate details provided or available';
        }
        
        if ($validationErrors) {
            $this->registerError($validationErrors, 'SP_CERT_ERROR');
        }
        
        // Declare template labels
        $formValues = [
            '[[SP_CERT_LABEL]]' =>  __("Service Provider Certificate", "phpsaml"),
            '[[SP_CERT_TITLE]]' =>  __("Certificate we should use when communicating with the Identity Provider. Use one long string without returns!", "phpsaml"),
            '[[SP_CERT_VALUE]]' => $cValue,
            '[[SP_CERT_VALID]]' => "$cer"
        ];

        // Add validation errors
        if (!empty($validationErrors)) {
            $this->registerError($validationErrors,'SP_CERT_ERROR');
        }

        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the sp certificate key property
     * and populates the form template
     *
     * @param string $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     * @todo write key validation function using openssl.
     */
    protected function saml_sp_certificate_key(string $cValue)
    {
        // Clean stupid new line entities
        $cValue = str_replace('\r\n', '', $cValue);

        // Declare template labels
        $formValues = [
            '[[SP_KEY_LABEL]]' =>  __("Service Provider Certificate Key", "phpsaml"),
            '[[SP_KEY_TITLE]]' =>  __("Certificate private key we should use when communicating with the Identity Provider", "phpsaml"),
            '[[SP_KEY_VALUE]]' => $cValue];

        // Do some basic validations
        if (!strstr($cValue, '-BEGIN PRIVATE KEY-') || !strstr($cValue, '-END PRIVATE KEY-')) {
            $this->registerError('This does not look like a valid private key, please make sure to include the private key BEGIN and END tags','SP_KEY_ERROR');
        }
        
        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the saml sp nameid format property
     * and populates the form template
     *
     * @param string $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function saml_sp_nameid_format(string $cValue)
    {
         // Declare template labels
         $formValues = [
            '[[SP_ID_LABEL]]' =>  __("Name ID Format", "phpsaml"),
            '[[SP_ID_TITLE]]' =>  __("The name id format that is sent to the iDP.", "phpsaml"),
            '[[SP_ID_SELECT]]' => ''];

        // Generate the options array
        $options = ['unspecified'  => __('Unspecified', 'phpsaml'),
                    'emailAddress' => __('Email Address', 'phpsaml'),
                    'transient'    => __('Transient', 'phpsaml'),
                    'persistent'   => __('Persistent', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[SP_ID_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the idp entity id property
     * and populates the form template
     *
     * @param string $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function saml_idp_entity_id(string $cValue)
    {
        // Declare template labels
        $formValues = [
            '[[IP_ID_LABEL]]' =>  __("Identity Provider Entity ID", "phpsaml"),
            '[[IP_ID_TITLE]]' =>  __("Identifier of the IdP entity  (must be a URI).", "phpsaml"),
            '[[IP_ID_VALUE]]' => $cValue];

        //Validate URL?
        
        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the idp single sign on service property
     * and populates the form template
     *
     * @param string $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function saml_idp_single_sign_on_service(string $cValue)
    {
        // Declare template labels
        $formValues = [
            '[[IP_SSO_URL_LABEL]]' =>  __("Identity Provider Single Sign On Service URL", "phpsaml"),
            '[[IP_SSO_URL_TITLE]]' =>  __("URL Target of the Identity Provider where we will send the Authentication Request Message.", "phpsaml"),
            '[[IP_SSO_URL_VALUE]]' => $cValue];

        //Validate URL?
        
        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the idp single logout service property for changes
     * and populates the form template
     *
     * @param string $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function saml_idp_single_logout_service(string $cValue)
    {
         // Declare template labels
         $formValues = [
            '[[IP_SLS_URL_LABEL]]' =>  __("Identity Provider Single Logout Service URL", "phpsaml"),
            '[[IP_SLS_URL_TITLE]]' =>  __("URL Location of the Identity Provider where GLPI will send the Single Logout Request.", "phpsaml"),
            '[[IP_SLS_URL_VALUE]]' => $cValue];

        //Validate URL?
        
        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the idp certificate property for changes
     * and populates the form template
     *
     * @param string $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function saml_idp_certificate(string $cValue)
    {
        $cert = $this->validateAndParseCertString($cValue);
        $validationErrors = (!$cert['msgs']['BEGIN_TAG_PRESENT']) ? 'The certificate BEGIN tag should be present<br>' : '';
        $validationErrors = (!$cert['msgs']['END_TAG_PRESENT']) ? 'The certificate END tag should be present<br>' : '';

        if (is_array($cert['certDetails'])) {
            $cer = "Configured IPD certificate was issued by: {$cert['certDetails']['isCN']} for: {$cert['certDetails']['cn']} and has {$cert['certAge']} days left";
        } else {
            $cer = 'No certificate details provided or available';
        }
        
        if ($validationErrors) {
            $this->registerError($validationErrors, 'IP_CERT_ERROR');
        }

        // Declare template labels
        $formValues = [
            '[[IP_CERT_LABEL]]' =>  __("Identity Provider Public X509 Certificate", "phpsaml"),
            '[[IP_CERT_TITLE]]' =>  __("Public x509 certificate of the Identity Provider.", "phpsaml"),
            '[[IP_CERT_VALUE]]' => $cValue,
            '[[IP_CERT_VALID]]' => "$cer"
        ];
        
        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the authn context property for changes
     * and populates the form template
     *
     * @param string $cValue
     * @return boolean
     * @since 1.2.1
     * @todo write unit test
     */
    protected function requested_authn_context(string $cValue)
    {
        /*This value uses a multi select that generates a comma separated value. This value
          is processed by JS code in the HTML template to create the multiselect.*/
         
        $cValue = (empty($cValue)) ? 'none' : $cValue;

        // Declare template labels
        $formValues = [
            '[[AUTHN_LABEL]]' =>  __("Requested Authn Context", "phpsaml"),
            '[[AUTHN_TITLE]]' =>  __("Set to None and no AuthContext will be sent in the AuthnRequest, oth", "phpsaml"),
            '[[AUTHN_SELECT]]' => '',
            '[[AUTHN_CONTEXT]]' => $cValue
        ];

        // Generate the options array
        $options = ['PasswordProtectedTransport'  => __('PasswordProtectedTransport', 'phpsaml'),
                    'Password'                    => __('Password', 'phpsaml'),
                    'X509'                        => __('X509', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[AUTHN_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


   /**
     *
     * Evaluates the requested authn context comparison property for changes
     * and populates the form template
     *
     * @param string $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function requested_authn_context_comparison(string $cValue)
    {
        // Declare template labels
        $formValues = [
            '[[AUTHN_COMPARE_LABEL]]' =>  __("Requested Authn Comparison", "phpsaml"),
            '[[AUTHN_COMPARE_TITLE]]' =>  __("How should the library compare the requested Authn Context?  The value defaults to 'Exact'.", "phpsaml"),
            '[[AUTHN_COMPARE_SELECT]]'=> ''
        ];

        // Generate the options array
        $options = ['exact'  => __('Exact', 'phpsaml'),
                    'minimum'=> __('Minimum', 'phpsaml'),
                    'maximum'=> __('Maximum', 'phpsaml'),
                    'better' => __('Better', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[AUTHN_COMPARE_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the nameid encrypted property for changes
     * and populates the form template
     *
     * @param int $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function saml_security_nameidencrypted(int $cValue)
    {
        // Declare template labels
        $formValues = [
            '[[ENCR_NAMEID_LABEL]]' =>  __("Encrypt NameID", "phpsaml"),
            '[[ENCR_NAMEID_TITLE]]' =>  __("Toggle yes to encrypt NameID.  Requires service provider certificate and key", "phpsaml"),
            '[[ENCR_NAMEID_SELECT]]'=> ''
        ];

        // Generate options
        $options = [ 1 => __('Yes', 'phpsaml'),
                     0 => __('No', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[ENCR_NAMEID_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the authn requests signed property for changes
     * and populates the form template
     *
     * @param int $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function saml_security_authnrequestssigned(int $cValue)
    {
        // Declare template labels
        $formValues = [
            '[[SIGN_AUTHN_REQ_LABEL]]' =>  __("Sign Authn Requests", "phpsaml"),
            '[[SIGN_AUTHN_REQ_TITLE]]' =>  __("Toggle yes to sign Authn Requests.  Requires service provider certificate and key", "phpsaml"),
            '[[SIGN_AUTHN_REQ_SELECT]]'=> ''
        ];

        // Generate options
        $options = [ 1 => __('Yes', 'phpsaml'),
                     0 => __('No', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[SIGN_AUTHN_REQ_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the Logout Request Signed property
     * and populates the form template
     *
     * @param int $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function saml_security_logoutrequestsigned(int $cValue)
    {
        // Declare template labels
        $formValues = [
            '[[SIGN_LOGOUT_REQ_LABEL]]' =>  __("Sign Logout Requests", "phpsaml"),
            '[[SIGN_LOGOUT_REQ_TITLE]]' =>  __("Toggle yes to sign Logout Requests.  Requires service provider certificate and key", "phpsaml"),
            '[[SIGN_LOGOUT_REQ_SELECT]]'=> ''
        ];

        // Generate options
        $options = [ 1 => __('Yes', 'phpsaml'),
                     0 => __('No', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[SIGN_LOGOUT_REQ_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the Logout Response Signed property
     * and populates the form template
     *
     * @param int $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function saml_security_logoutresponsesigned(int $cValue)
    {
         // Declare template labels
         $formValues = [
            '[[SIGN_LOGOUT_RES_LABEL]]' => __("Sign Logout Requests", "phpsaml"),
            '[[SIGN_LOGOUT_RES_TITLE]]' => __("Toggle yes to sign Logout Requests.  Requires service provider certificate and key", "phpsaml"),
            '[[SIGN_LOGOUT_RES_SELECT]]'=> ''
        ];

        // Generate options
        $options = [ 1 => __('Yes', 'phpsaml'),
                     0 => __('No', 'phpsaml')];

        foreach ($options as $value => $label) {
            $selected = ($value == $cValue) ? 'selected' : '';
            $formValues['[[SIGN_LOGOUT_RES_SELECT]]'] .= "<option value='$value' $selected>$label</option>";
        }

        // Merge outcomes in formValues
        $this->formValues = array_merge($this->formValues, $formValues);
    }


    /**
     *
     * Evaluates the id property for changes
     * and populates the form template
     *
     * @param int $cValue
     * @return void
     * @since 1.2.1
     * @todo write unit test
     */
    protected function id(int $cValue) : void
    {
        $this->formValues['[[ID]]'] = $cValue;
    }


    /**
     * Validates the provided Phpsaml version against the git repository
     * if $return is true method will return collected information in an array.
     *
     * version($dbConf, $return);
     *
     * @param string $compare       //version to compare
     * @param bool $return          //return the outcomes
     * @return void|array $outcomes //optional return
     * @since 1.2.1
     * @todo write unit test
     */
    public function version(string $compare, bool $return = false)
    {
        if ($feed = implode(file($this->PhpsamlGitAtomUrl))) {
            if ($xmlArray = simplexml_load_string($feed)) {
                $href = (string) $xmlArray->entry->link['href'];
                preg_match('/.* (.+)/', (string) $xmlArray->entry->title, $version);
                if (is_array($version)) {
                    $v = $version['1'];
                    if ($v <> $compare) {
                        if ($return) {
                            return ['gitVersion' => $v,
                                    'compare'    => $compare,
                                    'gitUrl'     => $href,
                                    'latest'     => true];
                        }
                        $this->formValues['[[VERSION]]'] = "<a href='$href' target='_blank'>A new version of Phpsaml is available</a>. Version $v was found in the repository, you are running $compare";
                    } else {
                        if ($return) {
                            return ['gitVersion' => $v,
                                    'compare'    => $compare,
                                    'gitUrl'     => $href,
                                    'latest'     => false];
                        }
                        $this->formValues['[[VERSION]]'] = "You are using version $v which is also the <a href='$href' target='_blank'>latest version</a>";
                    }
                } else {
                    $this->registerError("Could not correctly parse xml information from:".$this->PhpsamlGitAtomUrl." is simpleXml available?");
                }
            } else {
                $this->registerError("Could not correctly parse xml information from:".$this->PhpsamlGitAtomUrl." is simpleXml available?");
            }
        } else {
            $this->registerError("Could not retrieve version information from:".$this->PhpsamlGitAtomUrl." is internet access blocked?");
        }
    }
}
