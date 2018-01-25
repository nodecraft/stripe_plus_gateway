<?php
/**
 * Forked version of the official Stripe gateway which
 * includes updated offsite storage and ACH payments
 *
 * The Stripe API can be found at: https://stripe.com/docs/api
 *
 * @package blesta
 * @subpackage blesta.components.gateways.stripe_plus_gateway
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

class StripePlusGateway extends MerchantGateway implements MerchantCcOffsite, MerchantAchOffsite {

	/**
	 * @var string The base URL of API requests
	 */
	private $base_url = "https://api.stripe.com/v1/";

	/**
	 * Construct a new merchant gateway
	 */
	public function __construct() {
		// Load config settings for this module
		$this->loadConfig(dirname(__FILE__) . DS . "config.json");
		// Load components required by this module
		Loader::loadComponents($this, array("Input"));

		// Load the language required by this module
		Language::loadLang("stripe_plus_gateway", null, dirname(__FILE__) . DS . "language" . DS);
	}
	/**
	 * Attempt to install this gateway
	 */
	public function install() {
		// Ensure that the system has support for the JSON extension
		if (!function_exists("json_decode")) {
			$this->Input->setErrors(array(
				'json' => array(
					'required' => Language::_("Stripe_plus_gateway.!error.json_required", true)
				)
			));
		}

		if (!isset($this->Record))
			Loader::loadComponents($this, array("Record"));

		try {
			$this->Record->
				setField("id", array('type'=>"int", 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true))->
				setField("contact_id", array('type'=>"int", 'size'=>10, 'unsigned'=>true))->
				setField("stripe_id", array('type'=>"varchar", 'size'=>24))->
				setKey(array("id"), "primary")->
				setKey(array("contact_id"), "index")->
				setKey(array("contact_id"), "unique")->
				setKey(array("stripe_id"), "unique")->
				create("stripe_plus_meta", true);
		}
		catch (Exception $e) {
			$this->Input->setErrors(array('db'=> array('create'=>$e->getMessage())));
			return;
		}
	}
	/**
	 * Attempt to uninstall this gateway. If last is removed, all
	 * connections will be lost between contacts and stripe
	 *
	 * @param int $gateway_id The ID of the gateway being uninstalled
	 * @param boolean $last_instance True if $gateway_id is the last instance across all companies for this gateway, false otherwise
	 */
	public function uninstall($gateway_id, $last_instance) {
		if ($last_instance) {
			if (!isset($this->Record))
				Loader::loadComponents($this, array("Record"));

			$this->Record->drop("stripe_plus_meta");
		}
	}

	/**
	 * Create and return the view content required to modify the settings of this gateway
	 *
	 * @param array $meta An array of meta (settings) data belonging to this gateway
	 * @return string HTML content containing the fields to update the meta data for this gateway
	 */
	public function getSettings(array $meta=null) {
		// Load the view into this object, so helpers can be automatically added to the view
		$this->view = new View("settings", "default");
		$this->view->setDefaultView("components" . DS . "gateways" . DS . "merchant" . DS . "stripe_plus_gateway" . DS);
		// Load the helpers required for this view
		Loader::loadHelpers($this, array("Form", "Html"));

		$this->view->set("meta", $meta);

		return $this->view->fetch();
	}
	/**
	 * Validates the given meta (settings) data to be updated for this gateway
	 *
	 * @param array $meta An array of meta (settings) data to be updated for this gateway
	 * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure
	 */
	public function editSettings(array $meta) {
		// Verify meta data is valid
		$this->Input->setRules(array(
			'live_api_key'=>array(
				'empty'=>array(
					'rule'=>"isEmpty",
					'negate'=>true,
					'message'=>Language::_("Stripe_plus_gateway.!error.live_api_key.empty", true)
				)
			),
			'test_api_key'=>array(
				'empty'=>array(
					'rule'=>"isEmpty",
					'negate'=>true,
					'message'=>Language::_("Stripe_plus_gateway.!error.test_api_key.empty", true)
				)
			),
			'environment'=>array(
				'format'=>array(
					'rule'=>array("in_array", array("live_api_key", "test_api_key")),
					'message'=>Language::_("Stripe_plus_gateway.!error.environment.format", true)
				)
			)
		));

		// Validate the given meta data to ensure it meets the requirements
		$this->Input->validates($meta);
		// Return the meta data, no changes required regardless of success or failure for this gateway
		return $meta;
	}

	/**
	 * Sets the currency code to be used for all subsequent payments
	 *
	 * @param string $currency The ISO 4217 currency code to be used for subsequent payments
	 */
	public function setCurrency($currency) {
		$this->currency = $currency;
	}
	/**
	 * Sets the meta data for this particular gateway
	 *
	 * @param array $meta An array of meta data to set for this gateway
	 */
	public function setMeta(array $meta=null) {
		$this->meta = $meta;
	}
	/**
	 * Returns an array of all fields to encrypt when storing in the database
	 *
	 * @return array An array of the field names to encrypt when storing in the database
	 */
	public function encryptableFields() {
		return array("live_api_key", "test_api_key");
	}
	/**
	 * Used to determine whether this gateway can be configured for autodebiting accounts
	 *
	 * @return boolean True if the customer must be present (e.g. in the case of credit card customer must enter security code), false otherwise
	 */
	public function requiresCustomerPresent() {
		return false;
	}
	/**
	 * Used to determine if offsite ACH customer account information is enabled for the gateway
	 * This is invoked after the gateway has been initialized and after Gateway::setMeta() has been called.
	 * The gateway should examine its current settings to verify whether or not the system
	 * should invoke the gateway's offsite methods
	 *
	 * @return boolean True if the gateway expects the offset methods to be called for ACH payments, false to process the normal methods instead
	 */
	public function requiresAchStorage() {
		return true;
	}
	/**
	 * Used to determine if offsite credit card customer account information is enabled for the gateway
	 * This is invoked after the gateway has been initialized and after Gateway::setMeta() has been called.
	 * The gateway should examine its current settings to verify whether or not the system
	 * should invoke the gateway's offsite methods
	 *
	 * @return boolean True if the gateway expects the offset methods to be called for credit card payments, false to process the normal methods instead
	 */
	public function requiresCcStorage() {
		return true;
	}
	/**
	 * Store a credit card off site
	 *
	 * @param array $card_info An array of card info to store off site including:
	 * 	- first_name The first name on the card
	 * 	- last_name The last name on the card
	 * 	- card_number The card number
	 * 	- card_exp The card expiration date in yyyymm format
	 * 	- card_security_code The 3 or 4 digit security code of the card (if available)
	 * 	- type The credit card type
	 * 	- address1 The address 1 line of the card holder
	 * 	- address2 The address 2 line of the card holder
	 * 	- city The city of the card holder
	 * 	- state An array of state info including:
	 * 		- code The 2 or 3-character state code
	 * 		- name The local name of the country
	 * 	- country An array of country info including:
	 * 		- alpha2 The 2-character country code
	 * 		- alpha3 The 3-character country code
	 * 		- name The english name of the country
	 * 		- alt_name The local name of the country
	 * 	- zip The zip/postal code of the card holder
	 *  - reference_id The reference ID for this payment account
	 *  - merchant_token A token generated by the merchant referencing card details, stored offsite
	 * @param array $contact An array of contact information for the billing contact this account is to be set up under including:
	 * 	- id The ID of the contact
	 * 	- client_id The ID of the client this contact resides under
	 * 	- user_id The ID of the user this contact represents
	 * 	- contact_type The contact type
	 * 	- contact_type_id The reference ID for this custom contact type
	 * 	- contact_type_name The name of the contact type
	 * 	- first_name The first name of the contact
	 * 	- last_name The last name of the contact
	 * 	- title The title of the contact
	 * 	- company The company name of the contact
	 * 	- email The email address of the contact
	 * 	- address1 The address of the contact
	 * 	- address2 The address line 2 of the contact
	 * 	- city The city of the contact
	 * 	- state An array of state info including:
	 * 		- code The 2 or 3-character state code
	 * 		- name The local name of the country
	 * 	- country An array of country info including:
	 * 		- alpha2 The 2-character country code
	 * 		- alpha3 The 3-character country code
	 * 		- name The english name of the country
	 * 		- alt_name The local name of the country
	 * 	- zip The zip/postal code of the contact
	 * 	- date_added The date/time the contact was added
	 * @param string $client_reference_id The reference ID for the client on the remote gateway (if one exists)
	 * @return mixed False on failure or an array containing:
	 * 	- client_reference_id The reference ID for this client
	 * 	- reference_id The reference ID for this payment account
	 */
	public function storeCc(array $card_info, array $contact, $client_reference_id=null) {
		if(isset($card_info['merchant_token'])){
			// store card to stripe using token and then update blesta
			return $this->storeSource($contact, array(
				'source' => $card_info['merchant_token']
			));
		}
		return $this->storeSource($contact, array(
			'source' => array(
				'object' => "card",
				'number' => $card_info["card_number"],
				'exp_month' => substr($card_info['card_exp'], -2),
				'exp_year' => substr($card_info['card_exp'], 0, 4),
				'cvc' => $this->ifSet($card_info['card_security_code']),
				'name' => $this->getCustomerName($card_info),
				'address_line1' => $this->ifSet($card_info['address1']),
				'address_line2' => $this->ifSet($card_info['address2']),
				'address_zip' => $this->ifSet($card_info['zip']),
				'address_state' => $this->ifSet($card_info['state']['code']),
				'address_country' => $this->ifSet($card_info['country']['alpha3'])
			)
		));
	}
	/**
	 * Update a credit card stored off site
	 *
	 * @param array $card_info An array of card info to store off site including:
	 * 	- first_name The first name on the card
	 * 	- last_name The last name on the card
	 * 	- card_number The card number
	 * 	- card_exp The card expiration date in yyyymm format
	 * 	- card_security_code The 3 or 4 digit security code of the card (if available)
	 * 	- type The credit card type
	 * 	- address1 The address 1 line of the card holder
	 * 	- address2 The address 2 line of the card holder
	 * 	- city The city of the card holder
	 * 	- state An array of state info including:
	 * 		- code The 2 or 3-character state code
	 * 		- name The local name of the country
	 * 	- country An array of country info including:
	 * 		- alpha2 The 2-character country code
	 * 		- alpha3 The 3-character country code
	 * 		- name The english name of the country
	 * 		- alt_name The local name of the country
	 * 	- zip The zip/postal code of the card holder
	 * 	- account_changed True if the account details (bank account or card number, etc.) have been updated, false otherwise
	 * @param array $contact An array of contact information for the billing contact this account is to be set up under including:
	 * 	- id The ID of the contact
	 * 	- client_id The ID of the client this contact resides under
	 * 	- user_id The ID of the user this contact represents
	 * 	- contact_type The contact type
	 * 	- contact_type_id The reference ID for this custom contact type
	 * 	- contact_type_name The name of the contact type
	 * 	- first_name The first name of the contact
	 * 	- last_name The last name of the contact
	 * 	- title The title of the contact
	 * 	- company The company name of the contact
	 * 	- email The email address of the contact
	 * 	- address1 The address of the contact
	 * 	- address2 The address line 2 of the contact
	 * 	- city The city of the contact
	 * 	- state An array of state info including:
	 * 		- code The 2 or 3-character state code
	 * 		- name The local name of the country
	 * 	- country An array of country info including:
	 * 		- alpha2 The 2-character country code
	 * 		- alpha3 The 3-character country code
	 * 		- name The english name of the country
	 * 		- alt_name The local name of the country
	 * 	- zip The zip/postal code of the contact
	 * 	- date_added The date/time the contact was added
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
	 * @return mixed False on failure or an array containing:
	 * 	- client_reference_id The reference ID for this client
	 * 	- reference_id The reference ID for this payment account
	 */
	public function updateCc(array $card_info, array $contact, $client_reference_id, $account_reference_id) {
		$this->loadApi();
		$stripe_customer = $this->getStripeId($contact);
		if ($stripe_customer == false) {
			// catch all errors associated
			return false;
		}
		$logUrl = "customers/" . $stripe_customer->id . "/sources/" . $account_reference_id;
		$request = $card_info;
		$result = false;

		try {
			$source = $stripe_customer->sources->retrieve($account_reference_id);
			$source->exp_month = substr($card_info['card_exp'], -2);
			$source->exp_year = substr($card_info['card_exp'], 0, 4);
			$source->name = $this->getCustomerName($card_info, $source->name);
			$source->address_line1 = $this->ifSet($card_info['address1'], $source->address_line1);
			$source->address_line2 = $this->ifSet($card_info['address2'], $source->address_line2);
			$source->address_zip = $this->ifSet($card_info['zip'], $source->address_zip);
			$source->address_state = $this->ifSet($card_info['state']['code'], $source->address_state);
			$source->address_country = $this->ifSet($card_info['country']['alpha3'], $source->address_country);
			$request = $this->objectToArray($source);
			$save = $source->save();
			$result = $this->parseSource($stripe_customer, $source);
			$this->logRequest($logUrl, $request, $save->__toArray(true));
			file_put_contents('./debug.json', json_encode($results));
		}
		catch(Exception $e) {
			$errors = $this->handleErrors($e);
			$this->logRequest($logUrl, $request, $this->getErrorLog($e, $errors), true);
			file_put_contents('./debug-error.json', var_export($e, true));
		}

		return $result;
	}
	/**
	 * Remove a credit card stored off site
	 *
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to remove
	 * @return array An array containing:
	 * 	- client_reference_id The reference ID for this client
	 * 	- reference_id The reference ID for this payment account
	 */
	public function removeCc($client_reference_id, $account_reference_id) {
		return $this->removeSource($client_reference_id, $account_reference_id);
	}
	/**
	 * Charge a credit card stored off site
	 *
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
	 * @param float $amount The amount to process
	 * @param array $invoice_amounts An array of invoices, each containing:
	 * 	- id The ID of the invoice being processed
	 * 	- amount The amount being processed for this invoice (which is included in $amount)
	 * @return array An array of transaction data including:
	 * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
	 * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
	 * 	- transaction_id The ID returned by the remote gateway to identify this transaction
	 * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
	 */
	public function processStoredCc($client_reference_id, $account_reference_id, $amount, array $invoice_amounts=null) {
		return $this->processCharge($client_reference_id, $account_reference_id, $amount, $invoice_amounts);
	}
	/**
	 * Authorize a credit card stored off site (do not charge)
	 *
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
	 * @param float $amount The amount to authorize
	 * @param array $invoice_amounts An array of invoices, each containing:
	 * 	- id The ID of the invoice being processed
	 * 	- amount The amount being processed for this invoice (which is included in $amount)
	 * @return array An array of transaction data including:
	 * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
	 * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
	 * 	- transaction_id The ID returned by the remote gateway to identify this transaction
	 * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
	 */
	public function authorizeStoredCc($client_reference_id, $account_reference_id, $amount, array $invoice_amounts=null) {
		// Gateway does not support this action
		$this->setErrors($this->getCommonError("unsupported"));
	}
	/**
	 * Charge a previously authorized credit card stored off site
	 *
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
	 * @param string $transaction_reference_id The reference ID for the previously authorized transaction
	 * @param string $transaction_id The ID of the previously authorized transaction
	 * @param float $amount The amount to capture
	 * @param array $invoice_amounts An array of invoices, each containing:
	 * 	- id The ID of the invoice being processed
	 * 	- amount The amount being processed for this invoice (which is included in $amount)
	 * @return array An array of transaction data including:
	 * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
	 * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
	 * 	- transaction_id The ID returned by the remote gateway to identify this transaction
	 * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
	 */
	public function captureStoredCc($client_reference_id, $account_reference_id, $transaction_reference_id, $transaction_id, $amount, array $invoice_amounts=null) {
		// Gateway does not support this action
		$this->setErrors($this->getCommonError("unsupported"));
	}
	/**
	 * Void an off site credit card charge
	 *
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
	 * @param string $transaction_reference_id The reference ID for the previously authorized transaction
	 * @param string $transaction_id The ID of the previously authorized transaction
	 * @return array An array of transaction data including:
	 * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
	 * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
	 * 	- transaction_id The ID returned by the remote gateway to identify this transaction
	 * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
	 */
	public function voidStoredCc($client_reference_id, $account_reference_id, $transaction_reference_id, $transaction_id) {
		return $this->processRefund($transaction_id);
	}
	/**
	 * Refund an off site credit card charge
	 *
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
	 * @param string $transaction_reference_id The reference ID for the previously authorized transaction
	 * @param string $transaction_id The ID of the previously authorized transaction
	 * @param float $amount The amount to refund
	 * @return array An array of transaction data including:
	 * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
	 * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
	 * 	- transaction_id The ID returned by the remote gateway to identify this transaction
	 * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
	 */
	public function refundStoredCc($client_reference_id, $account_reference_id, $transaction_reference_id, $transaction_id, $amount) {
		return $this->processRefund($transaction_id, $amount);
	}

	/**
	 * Store an ACH account off site
	 *
	 * @param array $account_info An array of bank account info including:
	 * 	- first_name The first name on the account
	 * 	- last_name The last name on the account
	 * 	- account_number The bank account number
	 * 	- routing_number The bank account routing number
	 * 	- type The bank account type (checking, savings, business_checking)
	 * 	- address1 The address 1 line of the card holder
	 * 	- address2 The address 2 line of the card holder
	 * 	- city The city of the card holder
	 * 	- state An array of state info including:
	 * 		- code The 2 or 3-character state code
	 * 		- name The local name of the country
	 * 	- country An array of country info including:
	 * 		- alpha2 The 2-character country code
	 * 		- alpha3 The 3-character country code
	 * 		- name The english name of the country
	 * 		- alt_name The local name of the country
	 * 	- zip The zip/postal code of the account holder
	 * @param array $contact An array of contact information for the billing contact this account is to be set up under including:
	 * 	- id The ID of the contact
	 * 	- client_id The ID of the client this contact resides under
	 * 	- user_id The ID of the user this contact represents
	 * 	- contact_type The contact type
	 * 	- contact_type_id The reference ID for this custom contact type
	 * 	- contact_type_name The name of the contact type
	 * 	- first_name The first name of the contact
	 * 	- last_name The last name of the contact
	 * 	- title The title of the contact
	 * 	- company The company name of the contact
	 * 	- email The email address of the contact
	 * 	- address1 The address of the contact
	 * 	- address2 The address line 2 of the contact
	 * 	- city The city of the contact
	 * 	- state An array of state info including:
	 * 		- code The 2 or 3-character state code
	 * 		- name The local name of the country
	 * 	- country An array of country info including:
	 * 		- alpha2 The 2-character country code
	 * 		- alpha3 The 3-character country code
	 * 		- name The english name of the country
	 * 		- alt_name The local name of the country
	 * 	- zip The zip/postal code of the contact
	 * 	- date_added The date/time the contact was added
	 * @param string $client_reference_id The reference ID for the client on the remote gateway (if one exists)
	 * @return mixed False on failure or an array containing:
	 * 	- client_reference_id The reference ID for this client
	 * 	- reference_id The reference ID for this payment account
	 */
	public function storeAch(array $account_info, array $contact, $client_reference_id=null) {
		$type = 'individual';
		if($account_info['type'] === 'business_checking'){
			$type = 'company';
		}
		return $this->storeSource($contact, array(
			'source' => array(
				'object' => "bank_account",
				'account_number' => $account_info["account_number"],
				'routing_number' => $account_info["routing_number"],
				'account_holder_name' => $this->getCustomerName($account_info),
				'account_holder_type' => $type,
				'currency' => $this->currency,
				'country' => $this->ifSet($account_info['country']['alpha2'])
			)
		));
	}
	/**
	 * Update an off site ACH account
	 *
	 * @param array $account_info An array of bank account info including:
	 * 	- first_name The first name on the account
	 * 	- last_name The last name on the account
	 * 	- account_number The bank account number
	 * 	- routing_number The bank account routing number
	 * 	- type The bank account type (checking, savings, business_checking)
	 * 	- address1 The address 1 line of the card holder
	 * 	- address2 The address 2 line of the card holder
	 * 	- city The city of the card holder
	 * 	- state An array of state info including:
	 * 		- code The 2 or 3-character state code
	 * 		- name The local name of the country
	 * 	- country An array of country info including:
	 * 		- alpha2 The 2-character country code
	 * 		- alpha3 The 3-character country code
	 * 		- name The english name of the country
	 * 		- alt_name The local name of the country
	 * 	- zip The zip/postal code of the account holder
	 * 	- account_changed True if the account details (bank account or card number, etc.) have been updated, false otherwise
	 * @param array $contact An array of contact information for the billing contact this account is to be set up under including:
	 * 	- id The ID of the contact
	 * 	- client_id The ID of the client this contact resides under
	 * 	- user_id The ID of the user this contact represents
	 * 	- contact_type The contact type
	 * 	- contact_type_id The reference ID for this custom contact type
	 * 	- contact_type_name The name of the contact type
	 * 	- first_name The first name of the contact
	 * 	- last_name The last name of the contact
	 * 	- title The title of the contact
	 * 	- company The company name of the contact
	 * 	- email The email address of the contact
	 * 	- address1 The address of the contact
	 * 	- address2 The address line 2 of the contact
	 * 	- city The city of the contact
	 * 	- state An array of state info including:
	 * 		- code The 2 or 3-character state code
	 * 		- name The local name of the country
	 * 	- country An array of country info including:
	 * 		- alpha2 The 2-character country code
	 * 		- alpha3 The 3-character country code
	 * 		- name The english name of the country
	 * 		- alt_name The local name of the country
	 * 	- zip The zip/postal code of the contact
	 * 	- date_added The date/time the contact was added
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
	 * @return mixed False on failure or an array containing:
	 * 	- client_reference_id The reference ID for this client
	 * 	- reference_id The reference ID for this payment account
	 */
	public function updateAch(array $account_info, array $contact, $client_reference_id, $account_reference_id) {
		$this->setErrors($this->getCommonError("unsupported"));
	}
	/**
	 * Remove an off site ACH account
	 *
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to remove
	 * @return array An array containing:
	 * 	- client_reference_id The reference ID for this client
	 * 	- reference_id The reference ID for this payment account
	 */
	public function removeAch($client_reference_id, $account_reference_id) {
		return $this->removeSource($client_reference_id, $account_reference_id);
	}
	/**
	 * Process an off site ACH account transaction
	 *
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
	 * @param float $amount The amount to process
	 * @param array $invoice_amounts An array of invoices, each containing:
	 * 	- id The ID of the invoice being processed
	 * 	- amount The amount being processed for this invoice (which is included in $amount)
	 * @return array An array of transaction data including:
	 * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
	 * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
	 * 	- transaction_id The ID returned by the remote gateway to identify this transaction
	 * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
	 */
	public function processStoredAch($client_reference_id, $account_reference_id, $amount, array $invoice_amounts=null) {
		return $this->processCharge($client_reference_id, $account_reference_id, $amount, $invoice_amounts);
	}
	/**
	 * Void an off site ACH account transaction
	 *
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
	 * @param string $transaction_reference_id The reference ID for the previously authorized transaction
	 * @param string $transaction_id The ID of the previously authorized transaction
	 * @return array An array of transaction data including:
	 * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
	 * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
	 * 	- transaction_id The ID returned by the remote gateway to identify this transaction
	 * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
	 */
	public function voidStoredAch($client_reference_id, $account_reference_id, $transaction_reference_id, $transaction_id) {
		return $this->processRefund($transaction_id);
	}
	/**
	 * Refund an off site ACH account transaction
	 *
	 * @param string $client_reference_id The reference ID for the client on the remote gateway
	 * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
	 * @param string $transaction_reference_id The reference ID for the previously authorized transaction
	 * @param string $transaction_id The ID of the previously authorized transaction
	 * @param float $amount The amount to refund
	 * @return array An array of transaction data including:
	 * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
	 * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
	 * 	- transaction_id The ID returned by the remote gateway to identify this transaction
	 * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
	 */
	public function refundStoredAch($client_reference_id, $account_reference_id, $transaction_reference_id, $transaction_id, $amount) {
		return $this->processRefund($transaction_id, $amount);
	}

	/**
	 * Gateway Helper: Store source to Stripe customer by contact
	 *
	 * @param array $contact Returned contact by gateway method
	 * @param array $contact Mixed key to value array containing source details
	 * @return mixed Returns array containing results or false on error for use by gateway method
	 */
	private function storeSource($contact, $request) {
		$this->loadApi();
		$stripe_customer = $this->getStripeId($contact);
		if ($stripe_customer == false) {
			// catch all errors associated
			return false;
		}
		$logUrl = "customers/" . $stripe_customer->id . "/sources";
		$result = false;
		try {
			$source = $stripe_customer->sources->create($request);
			$result = $this->parseSource($stripe_customer, $source);
			$this->logRequest($logUrl, $request, $source->__toArray(true));
		}
		catch(Exception $e) {
			$errors = $this->handleErrors($e);
			$this->logRequest($logUrl, $request, $this->getErrorLog($e, $errors), true);
		}
		return $result;
	}


	private function getCCType($brand){
		$brandMap = [
			"visa" => "visa",
			"american express" => "amex",
			"mastercard" => "mc",
			"discover" => "disc",
			"jcb" => "jcb",
			"diners club" => "dc-cb",
			"unknown" => "visa"
		];
		$check = $brandMap[strtolower($brand)];
		if($check){
			return $check;
		}
		return $brandMap['unknown'];
	}

	private function parseSource($stripe_customer, $source){
		return [
			'client_reference_id' => $stripe_customer->id,
			'reference_id' => $source->id,
			'last4' => $source->last4,
			'type' => $this->getCCType($source->brand),
			'expiration' => $source->exp_year . substr("0" . $source->exp_month, -2)
		];
	}

	/**
	 * Gateway Helper: Removes source from Stripe customer by contact
	 *
	 * @param string $client_reference_id Returned stripe id by gateway method
	 * @param string $account_reference_id Returned source id by gateway method
	 * @return mixed Returns array containing references ids for use by gateway method
	 */
	private function removeSource($client_reference_id, $account_reference_id) {
		$this->loadApi();
		$result = false;
		$logUrl = "customers/" . $client_reference_id . "/sources/" . $account_reference_id;
		$request = array();
		try {
			$stripe_customer = $result = \Stripe\Customer::retrieve($client_reference_id);
			$source = $stripe_customer->sources->retrieve($account_reference_id);
			$id = $source->id;
			$delete = $source->delete();
			$this->logRequest($logUrl, $request, $delete->__toArray(true));
		}
		catch(Exception $e) {
			$errors = $this->handleErrors($e);
			$this->logRequest($logUrl, $request, $this->getErrorLog($e, $errors), true);
		}
		// return the reference, because giving our error would make too much sense
		return array(
			'client_reference_id' => $client_reference_id,
			'reference_id' => $account_reference_id
		);
	}
	/**
	 * Gateway Helper: Issues charge on Stripe source
	 *
	 * @param string $client_reference_id Stripe id, returned by gateway method
	 * @param string $account_reference_id Source id, returned by gateway method
	 * @param int $amount Currency amount to charge, returned by gateway method
	 * @param array $invoice_amounts array of invoices for charge, returned by gateway method
	 * @return array Returns status array for use by gateway method
	 */
	private function processCharge($client_reference_id, $account_reference_id, $amount, array $invoice_amounts=null) {
		$this->loadApi();
		$result = false;
		$logUrl = "charges";
		$description = $this->createDescription($invoice_amounts);
		$request = array(
			'amount' => $this->formatAmount($amount, $this->currency),
			'currency' => strtolower($this->currency),
			'customer' => $client_reference_id,
			'source' => $account_reference_id,
			'statement_descriptor' => $description,
			'description' => $description
		);
		try {
			$charge = \Stripe\Charge::create($request);
			$result = array(
				'status' => "approved",
				'reference_id' => $charge->balance_transaction,
				'transaction_id' => $charge->id
			);
			$this->logRequest($logUrl, $request, $charge->__toArray(true));
		}
		catch(Exception $e) {
			$message = Language::_("Stripe_plus_gateway.!error.declined.generic", true);
			$this->logRequest($logUrl, $request, $this->getErrorLog($e, $message), true);
			$transaction_id = 'invalid'; // sorry

			if (isset($e->jsonBody) && isset($e->jsonBody['error'])) {
				if (isset($e->jsonBody['error']['message'])) {
					$message = $e->jsonBody['error']['message'];
				}
				if (isset($e->jsonBody['error']['charge'])) {
					$transaction_id = $e->jsonBody['error']['charge'];
				}
			}
			$result = array(
				'status' => "declined",
				'message' => $message,
				'transaction_id' => $transaction_id
			);
		}
		return $result;
	}
	/**
	 * Gateway Helper: Issues refund on Stripe transaction
	 *
	 * @param string $transaction_id Stripe transaction id, returned by gateway method
	 * @param mixed $amount int Currency amount to refund or null for void, returned by gateway method
	 * @return array Returns status array for use by gateway method
	 */
	private function processRefund($transaction_id, $amount=null) {
		$this->loadApi();
		$result = false;
		if (isset($amount) && $amount !== null) {
			$options['amount'] = $this->formatAmount($amount, $this->currency);
		}
		$logUrl = "refunds";
		$request = array(
			'charge' => $transaction_id,
		);
		try {
			$result = \Stripe\Refund::create($request);
			$this->logRequest($logUrl, $request, $result->__toArray(true));
		}
		catch(Exception $e) {
			$errors = $this->handleErrors($e);
			$this->logRequest($logUrl, $request, $this->getErrorLog($e, $errors), true);
		}
		if (isset($result['is_error']) && $result['is_error'] === true) {
			return array(
				'status' => "error",
				'message' => $this->ifSet($result['message'], Language::_("Stripe_plus_gateway.!error.refund.generic", true)),
				'transaction_id' => null,
				'reference_id' => null
			);
		}
		return array(
			'status' => $amount === null ? "void" : "refunded",
			'message' => null,
			'transaction_id' => $result->id,
			'reference_id' => null
		);
	}

	/**
	 * Loads the API if not already loaded
	 */
	private function loadApi() {
		// check if loaded
		if (class_exists('Stripe')) {
			return;
		}
		// load stripe
		Loader::load(dirname(__FILE__) . DS . "api" . DS . "init.php");
		$apiKey = "";
		if (isset($this->meta['environment']) && isset($this->meta[$this->meta['environment']])) {
			$apiKey = $this->meta[$this->meta['environment']];
		}
		\Stripe\Stripe::setApiKey($apiKey);
	}
	/**
	 * Helper function to obtain stripe customer for contact
	 *
	 * @param array $contact An array populated gateway methods
	 * @return mixed object Stripe Customer Object or false on error
	 */
	private function getStripeId($contact) {
		$result = false;
		$this->loadApi();

		if (!isset($this->Record))
			Loader::loadComponents($this, array("Record"));

		$meta = $this->Record->select()->from("stripe_plus_meta")->
			where("contact_id", "=", intval($contact['id']))->fetch(PDO::FETCH_ASSOC);
		if ($meta == false) {
			return $this->createStripeId($contact);
		}
		// lookup
		try {
			$result = \Stripe\Customer::retrieve($meta['stripe_id']);
			if ($result->deleted) {
				$this->setErrors(array(
					'invalid_request_error' => array(
						'customer' => Language::_("Stripe_plus_gateway.!error.customer.deleted", true)
					)
				));
				$result = false; // reset customer back to `false`
			}
		}
		catch(\Stripe\Error\InvalidRequest $e) {
			// catch if the user's account no longer exists or was removed (not deleted)
			if ($e->getHttpStatus() === 404) {
				$meta = $this->Record->select()->from("stripe_plus_meta")->
					where("contact_id", "=", intval($contact['id']))->delete();
				$result = $this->createStripeId($contact);
			}
		}
		catch(Exception $e) {
			$this->handleErrors($e);
		}
		return $result;
	}
	/**
	 * Helper function to create stripe customer for contact
	 *
	 * @param array $contact An array populated gateway methods
	 * @return mixed object Stripe Customer Object or false on error
	 */
	private function createStripeId($contact) {
		$result = false;
		$logUrl = "customers";
		$request = array(
			'email' => $contact['email'],
			'description' => "Blesta customer contact: " . $contact['id'],
			'metadata' => array(
				'blesta_contact_id' => $contact['id']
			)
		);
		try {
			$result = \Stripe\Customer::create($request);
			$this->logRequest($logUrl, $request, $result->__toArray(true));

			$this->Record->insert("stripe_plus_meta", array(
				'contact_id' => $contact['id'],
				'stripe_id' => $result->id
			));
		}
		catch (PDOException $e) {
			$result = false;
			$errors = array(
				'db'=> array(
					'create'=>$e->getMessage()
				)
			);
			$this->Input->setErrors($errors);
			$this->logRequest($logUrl, $request, $errors, true);
		}
		catch(Exception $e) {
			$result = false;
			$errors = $this->handleErrors($e);
			$this->logRequest($logUrl, $request, $this->getErrorLog($e, $errors), true);
		}
		return $result;
	}

	/**
	 * Helper function to push errors into a log format
	 *
	 * @param Exception $e Exception returned by request catch
	 * @param mixed $errors Default errors to return if no JsonBody error is found
	 * @return mixed Request body or default errors
	 */
	private function getErrorLog($e, $errors) {
		if (!isset($e->jsonBody)) {
			return $errors;
		}
		return $e->jsonBody;
	}
	/**
	 * Helper function to filter error detection from returned errors
	 *
	 * @param mixed $errors Error object to filter results from
	 * @return mixed returns filtered error object
	 */
	private function setErrors($errors) {
		foreach($errors as $key => $error) {
			if (isset($errors[$key]['is_error'])) {
				unset($errors[$key]['is_error']);
			}
			if (isset($errors[$key]['status_code'])) {
				unset($errors[$key]['status_code']);
			}
		}
		$this->Input->setErrors($errors);
	}
	/**
	 * Helper function to catch all Stripe errors and filter to Input class
	 *
	 * @param Exception $e Exception returned by request catch
	 * @return array returns error list
	  */
	private function handleErrors($e) {
		if (!isset($e->jsonBody)) {
			return $this->getCommonError("general");
		}
		$body = $e->jsonBody;
		$error = array(
			'is_error' => true, // helper to detect error
			'status_code' => $e->getHttpStatus()
		);
		switch($body['error']['type']) {
			case "invalid_request_error":
				if(isset($body['error']['param'])){
					$error[$body['error']['param']] = $body['error']['message'];
				}else{
					$error['message'] = $body['error']['message'];
				}
			break;
			case "authentication_error":
				// Don't use the actual error (as it may contain an API key, albeit invalid), rather a general auth error
				$error['message'] = Language::_("Stripe_plus_gateway.!error.auth", true);
			break;
			case "api_connection_error":
			case "api_error":
			case "rate_limit_error":
				$error['message'] = $body['error']['message'];
			break;
			case "card_error":
				$error[$body['error']['code']] = $body['error']['message'];
			break;
			default:
				return $this->getCommonError("general");
			break;
		}
		$this->setErrors(array($body['error']['type'] => $error));
		return $error;
	}

	/**
	 * Log the request
	 *
	 * @param string $url The URL of the API request to log
	 * @param array $request The input parameters sent to the gateway
	 * @param array $response The response from the gateway
	 * @param boolean $isError Flags log as success or error
	 */
	private function logRequest($url, $request, $response, $isError=false) {
		// Define all fields to mask when logging
		$mask_fields = array(
			'number', // CC number
			'exp_month',
			'exp_year',
			'cvc'
		);
		// Log data sent to the gateway
		$this->log($this->base_url . $url, json_encode($this->maskDataRecursive($request, $mask_fields)), "input", !$isError);
		// Log response from the gateway
		$this->log($this->base_url .$url, json_encode($this->maskDataRecursive($response, $mask_fields)), "output", !$isError);
	}
	/**
	 * Casts multi-dimensional objects to arrays
	 *
	 * @param mixed $object An object
	 * @return array All objects cast to array
	 */
	private function objectToArray($object) {
		if (is_object($object))
			$object = get_object_vars($object);
		// Recurse over object to convert all object keys in $object to array
		if (is_array($object))
			return array_map(array($this, __FUNCTION__), $object);
		return $object;
	}
	/**
	 * Convert amount from decimal value to integer representation of cents
	 *
	 * @param float $amount
	 * @param string $currency
	 * @return int The amount in cents
	 */
	private function formatAmount($amount, $currency) {
		$non_decimal_currencies = array("BIF", "CLP", "DJF", "GNF", "JPY", "KMF",
			"KRW", "MGA", "PYG", "RWF", "VND", "VUV", "XAF", "XOF", "XPF");
		if (is_numeric($amount) && !in_array($currency, $non_decimal_currencies))
			$amount *= 100;
		return (int)round($amount);
	}

	/**
	 * Helper function to generate bank statement within 22 character
	 * limit as per Stripe's api docs
	 *
	 * @param array $invoice_amounts An object
	 * @return string Statement description of invoice(s)
	 */
	private function createDescription($invoice_amounts) {
		if(!isset($this->Invoices))
			Loader::loadModels($this, array("Invoices"));

		$desc = "";
		if (count($invoice_amounts) > 1) {
			$ids = array();
			foreach($invoice_amounts as $invoice) {
				$invoice_data = $this->Invoices->get($invoice['invoice_id']);
				$ids[] = ($invoice_data ? $invoice_data->id_code : $invoice['invoice_id']);
			}
			$desc = "Invoices " . join(", ", $ids);
			if (strlen($desc) > 22) {
				$desc = "Multi-invoice payment";
			}
		}
		elseif(count($invoice_amounts) === 1) {
			$invoice_data = $this->Invoices->get($invoice_amounts[0]['invoice_id']);
			$desc = "Invoice " . ($invoice_data ? $invoice_data->id_code : $invoice_amounts[0]['invoice_id']);
			if (strlen($desc) > 22) {
				$desc = "Invoice payment";
			}
		}else{ // no invoice amounts passed, must be a credit deposit
			$desc = 'Payment Credit';
		}
		return $desc;
	}

	/**
	 * Helper function to generate single name from parts
	 *
	 * @param array $source_info An array populated from source
	 * @param string $default Default string returned when no name is provided
	 * @return string Combined name
	 */
	private function getCustomerName($source_info, $default="") {
		$name = "";
		if (isset($source_info['first_name'])) {
			$name = $source_info['first_name'];
		}
		if (isset($source_info['last_name'])) {
			$name = $name . " " . $source_info['last_name'];
		}
		if (!$name) {
			return $default;
		}
		return $name;
	}
}