<?php
/*
Plugin Name: Gravity Forms Blue Pay Add-On
Plugin URI:
Description: Integrates Gravity Forms with Blue Pay, enabling end users to purchase goods and services through Gravity Forms.
Version: 1.0
Author: David Cramer
Author URI:

------------------------------------------------------------------------
Copyright 2013

This program is free software; you can redistribute it and/ modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('init',  array('GFBluePay', 'init'));

//limits currency to US Dollars
add_filter("gform_currency", create_function("","return 'USD';"));
add_action("ach_cron", array("GFBluePay", "ach_update"));

register_activation_hook( __FILE__, array("GFBluePay", "add_permissions"));

class GFBluePay {

	private static $path = "gravity-forms-bluepay/blue-pay.php";
	private static $url = "http://www.gravityforms.com";
	private static $slug = "gravity-forms-bluepay";
	private static $version = "1.0";
	private static $min_gravityforms_version = "1.7";
	public static $transaction_response = "";
	private static $supported_fields = array("checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title", "post_tags", "post_custom_field", "post_content", "post_excerpt");

	private static $settings;
	private static $is_test;

	//Plugin starting point. Will load appropriate files
	public static function init(){

		//runs the setup when version changes
		self::setup();

		// Testing cron update
		//self::ach_update();

		//supports logging
		add_filter("gform_logging_supported", array("GFBluePay", "set_logging_supported"));

		self::setup_cron();

		if(basename($_SERVER['PHP_SELF']) == "plugins.php") {

			//loading translations
			load_plugin_textdomain('gravity-forms-bluepay', FALSE, '/gravity-forms-bluepay/languages' );
		}

		if(!self::is_gravityforms_supported())
			return;

		if(is_admin()){

			//loading translations
			load_plugin_textdomain('gravity-forms-bluepay', FALSE, '/gravity-forms-bluepay/languages' );

			//automatic upgrade hooks
			add_filter("transient_update_plugins", array('GFBluePay', 'check_update'));
			add_filter("site_transient_update_plugins", array('GFBluePay', 'check_update'));

			//integrating with Members plugin
			if(function_exists('members_get_capabilities'))
				add_filter('members_get_capabilities', array("GFBluePay", "members_get_capabilities"));

			//creates the subnav left menu
			add_filter("gform_addon_navigation", array('GFBluePay', 'create_menu'));

			//enables credit card field
			add_filter("gform_enable_credit_card_field", "__return_true");

			if(self::is_blue_pay_page()){

				//enqueueing sack for AJAX requests
				wp_enqueue_script(array("sack"));

				//loading data lib
				require_once(self::get_base_path() . "/data.php");

				//loading Gravity Forms tooltips
				require_once(GFCommon::get_base_path() . "/tooltips.php");
				add_filter('gform_tooltips', array('GFBluePay', 'tooltips'));

			}
			else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

					//loading data class
					require_once(self::get_base_path() . "/data.php");

					add_action('wp_ajax_gf_blue_pay_update_feed_active', array('GFBluePay', 'update_feed_active'));
					add_action('wp_ajax_gf_select_blue_pay_form', array('GFBluePay', 'select_blue_pay_form'));

				}
			else if(RGForms::get("page") == "gf_settings"){
					RGForms::add_settings_page("BluePay", array("GFBluePay", "settings_page"), self::get_base_url() . "/images/blue_pay_wordpress_icon_32.png");
					add_filter("gform_currency_setting_message", create_function("","echo '<div class=\'gform_currency_message\'>BluePay only supports US Dollars.</div>';"));
					add_filter("gform_currency_disabled", "__return_true");
				}
			else if(RGForms::get("page") == "gf_entries"){

				}
		}
		else{
			//loading data class
			require_once(self::get_base_path() . "/data.php");

			//handling post submission.
			add_filter('gform_validation',array("GFBluePay", "blue_pay_validation"), 10, 4);
			add_action('gform_entry_post_save',array("GFBluePay", "blue_pay_commit_transaction"), 10, 2);

			//handle hashing ACH
			add_filter("gform_save_field_value", array("GFBluePay", "blue_pay_save_field_value"), 10, 4);
		}
	}

	public static function get_transaction_response () {
		return self::$transaction_response;
	}


	//-------------------- VALIDATION STEP -------------------------------------------------------

	public static function blue_pay_validation($validation_result){

		$config = self::is_ready_for_capture($validation_result);
		$form = $validation_result["form"];

		if(!$config)
			return $validation_result;

		//getting submitted data from fields
		$form_data = self::get_form_data($form, $config);
		$initial_payment_amount = $form_data["amount"] + absint(rgar($form_data,"fee_amount"));

		//don't process payment if initial payment is 0, but act as if the transaction was successful
		if($initial_payment_amount == 0){

			self::log_debug("Amount is 0. No need to authorize payment, but act as if transaction was successful");

			self::process_free_product($form, $config, $validation_result);
		}
		else {
			$card_field = self::get_creditcard_field($form);

			if($card_field && rgpost("input_{$card_field["id"]}_1") && rgpost("input_{$card_field["id"]}_2") && rgpost("input_{$card_field["id"]}_3") && rgpost("input_{$card_field["id"]}_5") ){

				self::log_debug("Initial payment of {$initial_payment_amount}. Credit card authorization required.");

				//authorizing credit card and setting self::$transaction_response variable
				$validation_result = self::authorize_credit_card($form_data, $config, $validation_result);
			}else{
				self::log_debug("Initial payment of {$initial_payment_amount}. ACH authorization required.");

				//authorizing credit card and setting self::$transaction_response variable
				$validation_result = self::authorize_ach($form_data, $config, $validation_result);
			}
		}

		return $validation_result;
	}

	private static function authorize_ach($form_data, $config, $validation_result){

		self::init_api();

		$transaction = self::get_initial_transaction($form_data, $config);
		$transaction = apply_filters("gform_blue_pay_transaction_pre_authorize", $transaction, $form_data, $config, $validation_result["form"]);


		$settings = self::get_api_settings( self::get_local_api_settings( $config ) );

		self::log_debug("Authorizing ACH...");

		$authorize = new BluePayment($settings['account_id'], $settings['secret_key'], $settings['mode']);

		if ( 'auth' == $settings['transaction_mode'] ) {
			$authorize->auth( $transaction['Amount'] );
		}
		else {
		$authorize->sale( $transaction['Amount'] );
		}

		$authorize->setCustACHInfo(
			$transaction['routing_number'],
			$transaction['account_number'],
			$transaction['account_type'],
			$transaction['name1'],
			$transaction['name2'],
			$transaction['address1'],
			$transaction['city'],
			$transaction['state'],
			$transaction['zip'],
			$transaction['countryCode'],
			$transaction['CustomerPhone'],
			$transaction['CustomerEmail'],
			$transaction['custom_id1'],
			$transaction['custom_id2'],
			$transaction['address2'],
			$transaction['memo']
		);

		$authorize->setOrderId( $transaction['OrderID'] );
		$authorize->processACH();

		$authCode 	= $authorize->getAuthCode();
		$authStatus = $authorize->getStatus();
		$transID 	= $authorize->getTransId();
		$response 	= $authorize->getResponse();

		self::log_debug("Authorization response: ");
		self::log_debug(print_r($response, true));

		//If first transaction was successful, move on
		if( $authStatus == '1' && !empty( $transID ) ){

			self::log_debug("ACH approved.");

			self::$transaction_response = array(
				"auth_transaction_id"   => (string)$authorize->getTransId(),
				"config"                => $config,
				"auth_code"             => (string)$authCode,
				"type"                  => 'ACH',
				"amount"                => $form_data["amount"],
				"setup_fee"             => $form_data["fee_amount"]
			);

			//passed validation
			$validation_result["is_valid"] = true;
		}
		else
		{
			self::log_error("ACH authorization failed. Aborting.");
			self::log_error(print_r($response, true));

			//authorization was not succesfull. failed validation
			$validation_result = self::set_validation_result($validation_result, $_POST, array('code' => $authStatus, 'message' => $authorize->getMessage() ), 'ach' );
		}

		return $validation_result;
	}

	private static function authorize_credit_card($form_data, $config, $validation_result){

		self::init_api();

		$transaction = self::get_initial_transaction($form_data, $config);
		$transaction = apply_filters("gform_blue_pay_transaction_pre_authorize", $transaction, $form_data, $config, $validation_result["form"]);

		$settings = self::get_api_settings( self::get_local_api_settings( $config ) );

		self::log_debug("Authorizing credit card...");

		$authorize = new BluePayment($settings['account_id'], $settings['secret_key'], $settings['mode']);

		if ( 'auth' == $settings['transaction_mode'] ) {
		$authorize->auth( $transaction['Amount'] );
		}
		else {
			$authorize->sale( $transaction['Amount'] );
		}

		$authorize->setCustInfo(
			$transaction['card_number'],
			$transaction['CardSecVal'],
			$transaction['Exp'],
			$transaction['name1'],
			$transaction['name2'],
			$transaction['address1'],
			$transaction['city'],
			$transaction['state'],
			$transaction['zip'],
			$transaction['countryCode'],
			$transaction['CustomerPhone'],
			$transaction['CustomerEmail'],
			$transaction['custom_id1'],
			$transaction['custom_id2'],
			$transaction['address2'],
			$transaction['memo']
		);
		$authorize->setOrderId( $transaction['OrderID'] );
		$authorize->process();

		$authCode 	= $authorize->getAuthCode();
		$authStatus = $authorize->getStatus();
		$transID 	= $authorize->getTransId();
		$response 	= $authorize->getResponse();

		self::log_debug("Authorization response: ");
		self::log_debug(print_r($response, true));

		//If first transaction was successful, move on
		if( !empty( $authCode ) && $authStatus == '1' && !empty( $transID ) ){

			self::log_debug("Credit card approved.");

			self::$transaction_response = array(
				"auth_transaction_id"   => (string)$authorize->getTransId(),
				"config"                => $config,
				"auth_code"             => (string)$authCode,
				"type"                  => 'CREDIT_CARD',
				"amount"                => $form_data["amount"],
				"setup_fee"             => $form_data["fee_amount"]
			);

			//passed validation
			$validation_result["is_valid"] = true;
		}
		else
		{
			self::log_error("Credit card authorization failed. Aborting.");
			self::log_error(print_r($response, true));

			//authorization was not succesfull. failed validation
			$validation_result = self::set_validation_result($validation_result, $_POST, array('code' => $authStatus, 'message' => $authorize->getMessage() ), 'creditcard' );
		}

		return $validation_result;
	}

	private static function process_free_product($form, $config, $validation_result){


		//blank out credit card field if this is the last page
		if(self::is_last_page($form)){
			$card_field = self::get_creditcard_field($form);
			$_POST["input_{$card_field["id"]}_1"] = "";
		}

		//creating dummy transaction response if there are any visible product fields in the form
		if(self::has_visible_products($form)){
			self::$transaction_response = array(
				"auth_transaction_id"   => 'N/A',
				"config"                => $config,
				"auth_code"             => null,
				"type"                  => 'FREE',
				"amount"                => 0,
				"setup_fee"             => 0
			);
		}
	}

	private static function set_validation_result($validation_result,$post,$response,$mode = 'creditcard'){

		$ach_field = self::get_ach_field($validation_result["form"]);

		$message = (string)$response['message'];
		$code = (string)$response['code'];

		$message = "<!-- Error: " . $code . " -->" . $message;

		$field_page = 0;

		foreach($validation_result["form"]["fields"] as &$field)
		{
			if('creditcard' == $mode && $field["type"] == "creditcard")
			{
				$field["failed_validation"] = true;
				$field["validation_message"] = $message;
				$field_page = $field["pageNumber"];
				break;
			}
			elseif('ach' == $mode) {
				if ( false !== strpos( $message, 'routing' ) ) {
					if ( $field["id"] == $ach_field[ 'routing_number' ][ 'id' ] ) {
						$field["failed_validation"] = true;
						$field["validation_message"] = $message;
						$field_page = $field["pageNumber"];
						break;
					}
				}
				elseif ( $field["id"] == $ach_field[ 'account_number' ][ 'id' ] ) {
					$field["failed_validation"] = true;
					$field["validation_message"] = $message;
					$field_page = $field["pageNumber"];
					break;
				}
			}
		}

		$validation_result["is_valid"] = false;

		GFFormDisplay::set_current_page($validation_result["form"]["id"], $field_page);

		return $validation_result;
	}


	//-------------------- SUBMISSION STEP -------------------------------------------------------

	public static function blue_pay_save_field_value($value, $lead, $field, $form){

		if(empty(self::$transaction_response))
			return $value;


		$config = self::$transaction_response["config"];

		if($field['id'] == $config['meta']['customer_fields']['routing_number']){
			return '######'.substr($value, 6);
		}

		if($field['id'] == $config['meta']['customer_fields']['account_number']){
			return '######'.substr($value, 6);
		}

		return $value;

	}

	public static function blue_pay_commit_transaction($entry, $form){

		if(empty(self::$transaction_response))
			return $entry;


		$config = self::$transaction_response["config"];

		//Creating entry meta
		gform_update_meta($entry["id"], "payment_gateway", "blue_pay");
		gform_update_meta($entry["id"], "blue_pay_feed_id", $config["id"]);
		gform_update_meta($entry["id"], "blue_pay_auth_code", self::$transaction_response["auth_code"]);


		//Capturing payment
		if($config["meta"]["type"] == "product"){
			$result = self::capture_product_payment($config, $entry, $form);
		}elseif($config["meta"]["type"] == "product"){
			$result = self::capture_product_payment($config, $entry, $form);
		}

		//Updates entry, creates transaction and entry notes
		$entry = self::update_entry($result, $entry, $config);

		return $entry;
	}

	private static function capture_product_payment($config, $entry, $form){

		self::log_debug("Capturing funds. Authorization transaction ID: " . self::$transaction_response["auth_transaction_id"] . " - Authorization code: " . self::$transaction_response["auth_code"]);


		//Getting transaction
		$form_data = self::get_form_data($form, $config);
		$transaction = self::get_initial_transaction($form_data, $config, $entry["id"]);

		$transaction = apply_filters("gform_blue_pay_transaction_pre_capture", $transaction, $form_data, $config, $form);

		$save_entry_id = apply_filters("gform_blue_pay_save_entry_id", false, $form["id"]);
		
		$auth_status = '0';
		$transID = 0;
		$response = false;
		$authorize = false;

		if($save_entry_id){

			self::log_debug("Creating a new capture only transaction that will save the entry id as the invoice number.");

			//Capturing payment. Must do a captureOnly to save the entry ID. This will create a new capture transaction and leave behind the prior authorization transaction (that will then be voided)
			$response = $transaction->captureOnly(self::$transaction_response["auth_code"]);

			self::void_authorization_transaction($config);
			//Voiding authorization transaction
			$void_response = $transaction->void(self::$transaction_response["auth_transaction_id"]);

			self::log_debug("Voiding authorization transaction - Transaction ID: " . self::$transaction_response["auth_transaction_id"] ." - Result: " . print_r($void_response, true));

		}
		elseif ( self::$transaction_response ) {
			if( self::$transaction_response['type'] == 'ACH' ){
				
				self::log_debug("Capturing funds using prior authorization transaction. Authorization transaction ID: " . self::$transaction_response["auth_transaction_id"] . " - Authorization code: " . self::$transaction_response["auth_code"]);
	
				$authStatus = '1';
				$transID 	= self::$transaction_response["auth_transaction_id"];
				$response = array(
					'status'				=>	'1',
					'auth_transaction_id'	=> $transID
				);
	
			}
			else {
				self::log_debug("Capturing funds using prior authorization transaction. Authorization transaction ID: " . self::$transaction_response["auth_transaction_id"] . " - Authorization code: " . self::$transaction_response["auth_code"]);
	
				//self::$transaction_response['auth_transaction_id']
				$settings = self::get_api_settings( self::get_local_api_settings( $config ) );
	
				$authorize = new BluePayment($settings['account_id'], $settings['secret_key'], $settings['mode']);
				$authorize->capture( self::$transaction_response['auth_transaction_id'] );
				$authorize->process();
	
				$response = $authorize->getResponse();
				$authCode 	= $authorize->getAuthCode();
				$authStatus = $authorize->getStatus();
				$transID 	= $authorize->getTransId();
			}

		}

		if( $authStatus == '1' && !empty( $transID ) )
		{
			//Saving transaction ID
			self::$transaction_response["transaction_id"] = (string)$transID;

			self::log_debug("Funds captured successfully. Transaction Id: {".(string)$transID."}");
			$result = array("is_success" => true, "error_code" => "", "error_message" => "");
		}
		else{
			self::log_error("Funds could not be captured. Response: ");
			self::log_error(print_r($response, true));

			$result = array("is_success" => false, "error_code" => $authStatus );
			
			if ( $authorize ) {
				$result["error_message"] = $authorize->getMessage();
			}
		}

		do_action("gform_blue_pay_post_capture", $result["is_success"], $form_data["amount"], $entry, $form, $config, $response);
		
		return $result;
	}


	private static function update_entry($result, $entry, $config){

		$entry["transaction_id"] = self::$transaction_response["auth_transaction_id"];
		$entry["transaction_type"] = $config["meta"]["type"] == "product" ? "1" : "2";
		$entry["is_fulfilled"] = true;
		$entry["currency"] = GFCommon::get_currency();

		if($result["is_success"]){

			$entry["payment_amount"] = self::$transaction_response["amount"];
			$entry["payment_status"] = $config["meta"]["type"] == "product" ? "Approved" : "Active";
			// Check if its ACH - set to pending
			if(!empty($config["meta"]["customer_fields"]["account_type"]) && !empty($config["meta"]["customer_fields"]["routing_number"]) && !empty($config["meta"]["customer_fields"]["account_number"])){
				if( $entry["payment_status"] == "Approved" ){
					$entry["payment_status"] = 'Pending';
				}
			}

			$entry["payment_date"] = gmdate("Y-m-d H:i:s");

			if($config["meta"]["type"] == "product"){
				GFBluePayData::insert_transaction($entry["id"], "payment", $entry["transaction_id"], $entry["transaction_id"], $entry["payment_amount"]);
			}

		}
		else{
			$entry["payment_status"] = "Failed";
			$message = $config["meta"]["type"] == "product" ? sprintf(  __("Transaction failed to be captured. Reason: %s", "gravity-forms-bluepay") , $result["error_message"] ) . " (" . $result["error_code"] . ")" : sprintf( __("Subscription failed to be created. Reason: %s", "gravity-forms-bluepay") , $result["error_message"]) . "(" . $result["error_code"] . ")";
			GFFormsModel::add_note($entry["id"], 0, "System", $message);
		}

		GFAPI::update_entry($entry);

		return $entry;
	}

	private static function get_initial_transaction($form_data, $config, $invoice_number=""){

		// processing products and services single transaction and first payment.
		$transaction = array();

		$transaction['Amount'] = GFCommon::to_number($form_data["amount"]);

		if( isset( $form_data['card_number'] ) ){
			$transaction['account_type'] = 'C';
			$transaction['card_number'] = $form_data["card_number"];
			$exp_date = str_pad($form_data["expiration_date"][0], 2, "0", STR_PAD_LEFT) . substr($form_data["expiration_date"][1], -2);
			$transaction['Exp'] = $exp_date;
			$transaction['CardSecVal'] = $form_data["security_code"];
		}else{
			$transaction['account_type'] = $form_data["account_type"];
			$transaction['account_number'] = $form_data["account_number"];
			$transaction['routing_number'] = $form_data["routing_number"];
		}

		$transaction['CustomerEmail'] = empty( $form_data["email"] ) ? '' : trim($form_data["email"]);
		$transaction['CustomerPhone'] = empty( $form_data["phone"] ) ? '' : trim($form_data["phone"]);
		$transaction['name1'] = $form_data["first_name"];
		$transaction['name2'] = $form_data["last_name"];
		$transaction['address1'] = trim($form_data["address1"]);
		$transaction['address2'] = trim($form_data["address2"]);
		$transaction['city'] = $form_data["city"];
		$transaction['state'] = convert_state($form_data["state"]);
		$transaction['zip'] = $form_data["zip"];
		$transaction['countryCode'] = convert_country($form_data["country"]);
		$transaction['OrderID'] = empty($invoice_number) ? uniqid() : $invoice_number;
		$transaction['memo'] = $form_data['memo'];
		$transaction['custom_id1'] = $form_data['custom_id1'];
		$transaction['custom_id2'] = $form_data['custom_id2'];

		return $transaction;
	}


	//-------------------- CRON -------------------------------------------------------

	public static function setup_cron(){
		if(!wp_next_scheduled("ach_cron"))
			wp_schedule_event(time(), "daily", "ach_cron");
	}

	public static function ach_update(){


		if(!self::is_gravityforms_supported())
			return;

		//loading data lib
		require_once(self::get_base_path() . "/data.php");

		// loading blue pay api and getting credentials
		self::include_api();

		// getting all feeds
		$bp_feeds = GFBluePayData::get_feeds();

		foreach($bp_feeds as $feed){

			// check the feed is ACH
			if(!empty($feed["meta"]["customer_fields"]["account_type"]) && !empty($feed["meta"]["customer_fields"]["routing_number"]) && !empty($feed["meta"]["customer_fields"]["account_number"]))
			{
				$form_id = $feed["form_id"];

				// getting billig cycle information
				$billing_cycle = $feed["meta"]["billing_cycle"];

				$querytime = strtotime(gmdate("Y-m-d") . "-" . $billing_cycle);
				$querydate = gmdate("Y-m-d", $querytime) . " 00:00:00";

				// finding leads with a pending statuc
				//gform_update_meta($entry["id"], "blue_pay_auth_code", self::$transaction_response["auth_code"]);
				global $wpdb;

				$query = "SELECT l.id, l.form_id, l.payment_date, l.transaction_id, m.meta_value as auth_code
                                                FROM {$wpdb->prefix}rg_lead l
                                                INNER JOIN {$wpdb->prefix}rg_lead_meta m ON l.id = m.lead_id
                                                WHERE l.form_id={$form_id}
                                                AND payment_status = 'Pending'
                                                AND meta_key = 'blue_pay_auth_code'
                                                AND meta_value = ''";

				$results = $wpdb->get_results( $query );

				foreach($results as $result){

					$config = GFBluePayData::get_feed($result->form_id);

					$settings = self::get_api_settings( self::get_local_api_settings( $config ) );

					$report = new BluePayment($settings['account_id'], $settings['secret_key'], $settings['mode']);
					$report->get_report($result->payment_date, date('Y-m-d'), $result->transaction_id);

					$settleId	= $report->getSettlementId();
					$authStatus = $report->getStatus();
					$message 	= $report->getMessage();

					/* STATUS */
					if( !empty($authStatus)){

						//Getting entry
						$entry_id = $result->id;
						$entry = RGFormsModel::get_lead($entry_id);

						switch ($authStatus){
							case 1:
								// Update
								$entry["payment_status"] = $message;
								$entry["payment_method"] = 'ACH';
								GFAPI::update_entry($entry);
								gform_update_meta($entry_id, "blue_pay_auth_code", $settleId);
								break;
							case 2:
								// update - not found
								$entry["payment_status"] = $message;
								$entry["payment_method"] = 'ACH';
								GFAPI::update_entry($entry);
								RGFormsModel::add_note($entry["id"], 0, "System", $message);

						}

					}


				}

			}
		}

	}

	public static function check_update($update_plugins_option){

		if(get_option("gf_blue_pay_version") != self::$version){
			require_once(self::get_base_path() . "/data.php");
			GFBluePayData:: update_table();
            update_option('gf_blue_pay_version', self::$version );
		}

        return $update_plugins_option;
    }

	private static function get_key(){
		if(self::is_gravityforms_supported())
			return GFCommon::get_key();
		else
			return "";
	}

	//------------------------------------------------------------------------


	public static function update_feed_active(){
		check_ajax_referer('gf_blue_pay_update_feed_active','gf_blue_pay_update_feed_active');
		$id = $_POST["feed_id"];
		$feed = GFBluePayData::get_feed($id);
		GFBluePayData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
	}

	//Creates BluePay left nav menu under Forms
	public static function create_menu($menus){

		// Adding submenu if user has access
		$permission = self::has_access("gravityforms_blue_pay");
		if(!empty($permission))
			$menus[] = array("name" => "gf_blue_pay", "label" => __("BluePay", "gravity-forms-bluepay"), "callback" =>  array("GFBluePay", "blue_pay_page"), "permission" => $permission);

		return $menus;
	}

	//Creates or updates database tables. Will only run when version changes
	private static function setup(){
		if(get_option("gf_blue_pay_version") != self::$version){
			require_once(self::get_base_path() . "/data.php");
			GFBluePayData:: update_table();
		}

		update_option("gf_blue_pay_version", self::$version);
	}

	//Adds feed tooltips to the list of tooltips
	public static function tooltips($tooltips){
		$blue_pay_tooltips = array(
			"blue_pay_transaction_type" => "<h6>" . __("Transaction Type", "gravity-forms-bluepay") . "</h6>" . __("Select which BluePay transaction type should be used. Products and Services, Donations or Subscription.", "gravity-forms-bluepay"),
			"blue_pay_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-bluepay") . "</h6>" . __("Select which Gravity Forms you would like to integrate with BluePay.", "gravity-forms-bluepay"),
			"blue_pay_customer" => "<h6>" . __("Customer", "gravity-forms-bluepay") . "</h6>" . __("Map your Form Fields to the available BluePay customer information fields.", "gravity-forms-bluepay"),
			"blue_pay_options" => "<h6>" . __("Options", "gravity-forms-bluepay") . "</h6>" . __("Turn on or off the available BluePay checkout options.", "gravity-forms-bluepay"),
			"blue_pay_recurring_amount" => "<h6>" . __("Recurring Amount", "gravity-forms-bluepay") . "</h6>" . __("Select which field determines the recurring payment amount.", "gravity-forms-bluepay"),
			"blue_pay_billing_cycle" => "<h6>" . __("Billing Cycle", "gravity-forms-bluepay") . "</h6>" . __("Select your billing cycle.  This determines how often the recurring payment should occur.", "gravity-forms-bluepay"),
			"blue_pay_recurring_times" => "<h6>" . __("Recurring Times", "gravity-forms-bluepay") . "</h6>" . __("Select how many times the recurring payment should be made.  The default is to bill the customer until the subscription is canceled.", "gravity-forms-bluepay"),
			"blue_pay_trial_period_enable" => "<h6>" . __("Trial Period", "gravity-forms-bluepay") . "</h6>" . __("Enable a trial period.  The users recurring payment will not begin until after this trial period.", "gravity-forms-bluepay"),
			"blue_pay_trial_duration" => "<h6>" . __("Trial Duration", "gravity-forms-bluepay") . "</h6>" . __("Enter the number of days to be included for a free trial.", "gravity-forms-bluepay"),
			"blue_pay_trial_period" => "<h6>" . __("Trial Recurring Times", "gravity-forms-bluepay") . "</h6>" . __("Select the number of billing occurrences or payments in the trial period.", "gravity-forms-bluepay"),
			"blue_pay_conditional" => "<h6>" . __("BluePay Condition", "gravity-forms-bluepay") . "</h6>" . __("When the BluePay condition is enabled, form submissions will only be sent to BluePay when the condition is met. When disabled all form submissions will be sent to BluePay.", "gravity-forms-bluepay"),
			"blue_pay_setup_fee_enable" => "<h6>" . __("Setup Fee", "gravityformspaypalpro") . "</h6>" . __("Enable setup fee to charge a one time fee before the recurring payments begin.", "gravity-forms-bluepay")
		);
		return array_merge($tooltips, $blue_pay_tooltips);
	}

	public static function blue_pay_page(){
		$view = rgget("view");
		if($view == "edit")
			self::edit_page(rgget("id"));
		elseif($view == "stats")
			self::stats_page(rgget("id"));
		else
			self::list_page();
	}

	//Displays the blue_pay feeds list page
	private static function list_page(){
		if(!self::is_gravityforms_supported()){
			die(__(sprintf("BluePay Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravity-forms-bluepay"));
		}

		if(rgpost('action') == "delete"){
			check_admin_referer("list_action", "gf_blue_pay_list");

			$id = absint($_POST["action_argument"]);
			GFBluePayData::delete_feed($id);
?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravity-forms-bluepay") ?></div>
            <?php
		}
		else if (!empty($_POST["bulk_action"])){
				check_admin_referer("list_action", "gf_blue_pay_list");
				$selected_feeds = $_POST["feed"];
				if(is_array($selected_feeds)){
					foreach($selected_feeds as $feed_id)
						GFBluePayData::delete_feed($feed_id);
				}
?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravity-forms-bluepay") ?></div>
            <?php
			}

?>
        <div class="wrap">
            <img alt="<?php _e("BluePay Transactions", "gravity-forms-bluepay") ?>" src="<?php echo self::get_base_url()?>/images/blue_pay_wordpress_icon_32.png" style="float:left; margin:7px 7px 0 0;"/>
            <h2><?php
		_e("BluePay Forms", "gravity-forms-bluepay");
?>
                <a class="button add-new-h2" href="admin.php?page=gf_blue_pay&view=edit&id=0"><?php _e("Add New", "gravity-forms-bluepay") ?></a>

            </h2>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_blue_pay_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravity-forms-bluepay") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravity-forms-bluepay") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravity-forms-bluepay") ?></option>
                        </select>
                        <?php
		echo '<input type="submit" class="button" value="' . __("Apply", "gravity-forms-bluepay") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravity-forms-bluepay") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-bluepay") .'\')) { return false; } return true;"/>';
?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-bluepay") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravity-forms-bluepay") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-bluepay") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravity-forms-bluepay") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php


		$settings = GFBluePayData::get_feeds();
		if(!self::is_valid_key()){
?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php echo sprintf(__("To get started, please configure your %sBluePay Settings%s.", "gravity-forms-bluepay"), '<a href="admin.php?page=gf_settings&addon=BluePay">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
		}
		else if(is_array($settings) && sizeof($settings) > 0){
				foreach($settings as $setting){
?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-bluepay") : __("Inactive", "gravity-forms-bluepay");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-bluepay") : __("Inactive", "gravity-forms-bluepay");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_blue_pay&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-bluepay") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="<?php _e("Edit", "gravity-forms-bluepay")?>" href="admin.php?page=gf_blue_pay&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-bluepay") ?>"><?php _e("Edit", "gravity-forms-bluepay") ?></a>
                                            |
                                            </span>
                                            <span>
                                            <a title="<?php _e("View Stats", "gravity-forms-bluepay")?>" href="admin.php?page=gf_blue_pay&view=stats&id=<?php echo $setting["id"] ?>" title="<?php _e("View Stats", "gravity-forms-bluepay") ?>"><?php _e("Stats", "gravity-forms-bluepay") ?></a>
                                            |
                                            </span>
                                            <span>
                                            <a title="<?php _e("View Entries", "gravity-forms-bluepay")?>" href="admin.php?page=gf_entries&view=entries&id=<?php echo $setting["form_id"] ?>" title="<?php _e("View Entries", "gravity-forms-bluepay") ?>"><?php _e("Entries", "gravity-forms-bluepay") ?></a>
                                            |
                                            </span>
                                            <span>
                                            <a title="<?php _e("Delete", "gravity-forms-bluepay") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravity-forms-bluepay") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-bluepay") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravity-forms-bluepay")?></a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date">
                                        <?php
					switch($setting["meta"]["type"]){
					case "product" :
						_e("Product and Services", "gravity-forms-bluepay");
						break;
					}
?>
                                    </td>
                                </tr>
                                <?php
				}
			}
		else{
?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php echo sprintf(__("You don't have any BluePay feeds configured. Let's go %screate one%s!", "gravity-forms-bluepay"), '<a href="admin.php?page=gf_blue_pay&view=edit&id=0">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
		}
?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravity-forms-bluepay") ?>').attr('alt', '<?php _e("Inactive", "gravity-forms-bluepay") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravity-forms-bluepay") ?>').attr('alt', '<?php _e("Active", "gravity-forms-bluepay") ?>');
                }

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_blue_pay_update_feed_active" );
                mysack.setVar( "gf_blue_pay_update_feed_active", "<?php echo wp_create_nonce("gf_blue_pay_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravity-forms-bluepay" ) ?>' )};
                mysack.runAJAX();

                return true;
            }


        </script>
        <?php
	}

	public static function settings_page(){

		if(isset($_POST["uninstall"])){
			check_admin_referer("uninstall", "gf_blue_pay_uninstall");
			self::uninstall();

?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms BluePay Add-On have been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravity-forms-bluepay")?></div>
            <?php
			return;
		}
		else if(isset($_POST["gf_blue_pay_submit"])){
				check_admin_referer("update", "gf_blue_pay_update");
				$settings = array(
					"mode"             => rgpost("gf_blue_pay_mode"),
					"account_id"       => rgpost("gf_blue_pay_account_id"),
					"secret_key"       => rgpost("gf_blue_pay_secret_key"),
					"transaction_mode" => rgpost("gf_blue_pay_transaction_mode"),
					"mb_configured"    => rgpost("gf_mb_configured")
				);


				update_option("gf_blue_pay_settings", $settings);
			}
		else{
			$settings = get_option("gf_blue_pay_settings");
		}

		$message = "";


?>
        <style>
            .valid_credentials{color:green;}
            .invalid_credentials{color:red;}
            .size-1{width:400px;}
        </style>

        <form method="post" action="">
            <?php wp_nonce_field("update", "gf_blue_pay_update") ?>

            <h3><?php _e("BluePay Account Information", "gravity-forms-bluepay") ?></h3>
            <p style="text-align: left;">
                <?php _e(sprintf("BluePay is a payment gateway for merchants. Use Gravity Forms to collect payment information and automatically integrate to your client's BluePay account. If you don't have a BluePay account, you can %ssign up for one here%s", "<a href='https://www.bluepay.com/' target='_blank'>" , "</a>"), "gravity-forms-bluepay") ?>
            </p>
            <p style="text-align: left;">
            	<?php _e("Log into the Bluepay 2.0 Gateway Manager. From the Administration menu choose Accounts, then List. On the Account List, under Options on the right-hand side, choose the first icon to view the account. It looks like a pair of eyes. On the Account Admin page, you will find the ACCOUNT ID is the second item in the right-hand column and the SECRET KEY is about halfway down the page, near a large red warning.", "gravity-forms-bluepay") ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row" nowrap="nowrap"><label for="gf_blue_pay_mode"><?php _e("Mode", "gravity-forms-bluepay"); ?></label> </th>
                    <td width="88%">
                        <input type="radio" name="gf_blue_pay_mode" id="gf_blue_pay_mode_live" value="LIVE" <?php echo rgar($settings, 'mode') != "TEST" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_blue_pay_mode_live"><?php _e("Live", "gravity-forms-bluepay"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="gf_blue_pay_mode" id="gf_blue_pay_mode_test" value="TEST" <?php echo rgar($settings, 'mode') == "TEST" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_blue_pay_mode_test"><?php _e("Test", "gravity-forms-bluepay"); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row" nowrap="nowrap"><label for="gf_blue_pay_account_id"><?php _e("Account ID", "gravity-forms-bluepay"); ?></label> </th>
                    <td width="88%">
                        <input class="size-1" id="gf_blue_pay_account_id" name="gf_blue_pay_account_id" value="<?php echo esc_attr(rgar($settings,"account_id")) ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row" nowrap="nowrap"><label for="gf_blue_pay_secret_key"><?php _e("Secret Key", "gravity-forms-bluepay"); ?></label> </th>
                    <td width="88%">
                        <input class="size-1" id="gf_blue_pay_secret_key" name="gf_blue_pay_secret_key" value="<?php echo esc_attr(rgar($settings,"secret_key")) ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row" nowrap="nowrap"><label for="gf_blue_pay_transaction_mode"><?php _e("Transaction Mode", "gravity-forms-bluepay"); ?></label> </th>
                    <td width="88%">
                        <input type="radio" name="gf_blue_transaction_mode" id="gf_blue_transaction_mode_sale" value="sale" <?php echo rgar($settings, 'transaction_mode') != "auth" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_blue_transaction_mode_sale"><?php _e("Sale", "gravity-forms-bluepay"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="gf_blue_transaction_mode" id="gf_blue_transaction_mode_auth" value="auth" <?php echo rgar($settings, 'transaction_mode') == "auth" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_blue_transaction_mode_auth"><?php _e("Auth", "gravity-forms-bluepay"); ?></label>
                    </td>
                </tr>
                <?php
                /*
                // Need to setup up this still
                <tr>
                    <td colspan="2">
                        <h3><?php _e("Managed Billing (Recurring) Setup", "gravity-forms-bluepay") ?></h3>
                        <p style="text-align: left;">
                            <?php _e("To create recurring payments, you must have Managed Billing setup in your BluePay account.", "gravity-forms-bluepay") ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <input type="checkbox" name="gf_mb_configured" id="gf_mb_configured" <?php echo $settings["mb_configured"] ? "checked='checked'" : ""?>/>
                        <label for="gf_mb_configured" class="inline"><?php _e("Managed Billing is setup in my BluePay account.", "gravity-forms-bluepay") ?></label>
                    </td>
                </tr>
                */
                ?>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_blue_pay_submit" class="button-primary" value="<?php _e("Save Settings", "gravity-forms-bluepay") ?>" /></td>
                </tr>

            </table>

        </form>

         <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_blue_pay_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_blue_pay_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall BluePay Add-On", "gravity-forms-bluepay") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL BluePay Feeds.", "gravity-forms-bluepay") ?>
                    <?php
			$uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall BluePay Add-On", "gravity-forms-bluepay") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL BluePay Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravity-forms-bluepay") . '\');"/>';
			echo apply_filters("gform_blue_pay_uninstall_button", $uninstall_button);
?>
                </div>
            <?php } ?>
        </form>

        <!--<form action="" method="post">
                <div class="hr-divider"></div>
                <div class="delete-alert">
                    <input type="submit" name="cron" value="Cron" class="button"/>
                </div>
        </form>-->

        <?php
	}

	private static function init_api(){

		self::include_api();
		self::$settings = get_option("gf_blue_pay_settings");
		self::$is_test = rgar(self::$settings, "mode") == "TEST";

		if(!defined('BLUEPAY_ACCOUNTID')) define('BLUEPAY_ACCOUNTID', self::$settings['account_id']);
		if(!defined('BLUEPAY_SECRETKEY')) define('BLUEPAY_SECRETKEY', self::$settings['secret_key']);
	}

	private static function is_valid_key($local_api_settings = array()){

		self::init_api();

		if( empty(self::$settings['account_id'] ) || empty( self::$settings['secret_key'] )){
			return false;
		}
		return true;
	}

	private static function get_api_settings($local_api_settings){
		$custom_api_settings = false;
		if(!empty($local_api_settings))
			$custom_api_settings = true;
		else
			$settings = get_option("gf_blue_pay_settings");

		$account_id = $custom_api_settings ? rgar($local_api_settings, "account_id") : rgar($settings, "account_id");
		$secret_key = $custom_api_settings ? rgar($local_api_settings, "secret_key") : rgar($settings, "secret_key");
		$mode = $custom_api_settings ? rgar($local_api_settings, "mode") : rgar($settings, "mode");
		$transaction_mode = $custom_api_settings ? rgar($local_api_settings, "transaction_mode") : rgar($settings, "transaction_mode");

		return array("account_id" => $account_id, "secret_key" => $secret_key, "mode" => $mode, "transaction_mode" => $transaction_mode);
	}

	private static function include_api(){
		if(!class_exists('BluePayRequest'))
			require_once self::get_base_path() . "/api/BluePay.php";
	}

	private static function stats_page(){
?>
        <style>
          .blue_pay_graph_container{clear:both; padding-left:5px; min-width:789px; margin-right:50px;}
        .blue_pay_message_container{clear: both; padding-left:5px; text-align:center; padding-top:120px; border: 1px solid #CCC; background-color: #FFF; width:100%; height:160px;}
        .blue_pay_summary_container {margin:30px 60px; text-align: center; min-width:740px; margin-left:50px;}
        .blue_pay_summary_item {width:160px; background-color: #FFF; border: 1px solid #CCC; padding:14px 8px; margin:6px 3px 6px 0; display: -moz-inline-stack; display: inline-block; zoom: 1; *display: inline; text-align:center;}
        .blue_pay_summary_value {font-size:20px; margin:5px 0; font-family:Georgia,"Times New Roman","Bitstream Charter",Times,serif}
        .blue_pay_summary_title {}
        #blue_pay_graph_tooltip {border:4px solid #b9b9b9; padding:11px 0 0 0; background-color: #f4f4f4; text-align:center; -moz-border-radius: 4px; -webkit-border-radius: 4px; border-radius: 4px; -khtml-border-radius: 4px;}
        #blue_pay_graph_tooltip .tooltip_tip {width:14px; height:14px; background-image:url(<?php echo self::get_base_url() ?>/images/tooltip_tip.png); background-repeat: no-repeat; position: absolute; bottom:-14px; left:68px;}

        .blue_pay_tooltip_date {line-height:130%; font-weight:bold; font-size:13px; color:#21759B;}
        .blue_pay_tooltip_sales {line-height:130%;}
        .blue_pay_tooltip_revenue {line-height:130%;}
            .blue_pay_tooltip_revenue .blue_pay_tooltip_heading {}
            .blue_pay_tooltip_revenue .blue_pay_tooltip_value {}
            .blue_pay_trial_disclaimer {clear:both; padding-top:20px; font-size:10px;}
        </style>
        <script type="text/javascript" src="<?php echo self::get_base_url() ?>/flot/jquery.flot.min.js"></script>
        <script type="text/javascript" src="<?php echo self::get_base_url() ?>/js/currency.js"></script>

        <div class="wrap">
            <img alt="<?php _e("BluePay", "gravity-forms-bluepay") ?>" style="margin: 7px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/blue_pay_wordpress_icon_32.png"/>
            <h2><?php _e("BluePay Stats", "gravity-forms-bluepay") ?></h2>

            <form method="post" action="">
                <ul class="subsubsub">
                    <li><a class="<?php echo (!RGForms::get("tab") || RGForms::get("tab") == "daily") ? "current" : "" ?>" href="?page=gf_blue_pay&view=stats&id=<?php echo absint($_GET["id"]) ?>"><?php _e("Daily", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "weekly" ? "current" : ""?>" href="?page=gf_blue_pay&view=stats&id=<?php echo absint($_GET["id"]) ?>&tab=weekly"><?php _e("Weekly", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "monthly" ? "current" : ""?>" href="?page=gf_blue_pay&view=stats&id=<?php echo absint($_GET["id"]) ?>&tab=monthly"><?php _e("Monthly", "gravityforms"); ?></a></li>
                </ul>
                <?php
		$config = GFBluePayData::get_feed(RGForms::get("id"));

		switch(RGForms::get("tab")){
		case "monthly" :
			$chart_info = self::monthly_chart_info($config);
			break;

		case "weekly" :
			$chart_info = self::weekly_chart_info($config);
			break;

		default :
			$chart_info = self::daily_chart_info($config);
			break;
		}

		if(!$chart_info["series"]){
?>
                    <div class="blue_pay_message_container"><?php _e("No payments have been made yet.", "gravity-forms-bluepay") ?> <?php echo $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_duration"]) ? " **" : ""?></div>
                    <?php
		}
		else{
?>
                    <div class="blue_pay_graph_container">
                        <div id="graph_placeholder" style="width:100%;height:300px;"></div>
                    </div>

                    <script type="text/javascript">
                        var blue_pay_graph_tooltips = <?php echo $chart_info["tooltips"]?>;
                        jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        jQuery(window).resize(function(){
                            jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        });

                        var previousPoint = null;
                        jQuery("#graph_placeholder").bind("plothover", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        jQuery("#graph_placeholder").bind("plotclick", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        function startShowTooltip(item){
                            if (item) {
                                if (!previousPoint || previousPoint[0] != item.datapoint[0]) {
                                    previousPoint = item.datapoint;

                                    jQuery("#blue_pay_graph_tooltip").remove();
                                    var x = item.datapoint[0].toFixed(2),
                                        y = item.datapoint[1].toFixed(2);

                                    showTooltip(item.pageX, item.pageY, blue_pay_graph_tooltips[item.dataIndex]);
                                }
                            }
                            else {
                                jQuery("#blue_pay_graph_tooltip").remove();
                                previousPoint = null;
                            }
                        }
                        function showTooltip(x, y, contents) {
                            jQuery('<div id="blue_pay_graph_tooltip">' + contents + '<div class="tooltip_tip"></div></div>').css( {
                                position: 'absolute',
                                display: 'none',
                                opacity: 0.90,
                                width:'150px',
                                height:'<?php echo $config["meta"]["type"] == "subscription" ? "75px" : "60px" ;?>',
                                top: y - <?php echo $config["meta"]["type"] == "subscription" ? "100" : "89" ;?>,
                                left: x - 79
                            }).appendTo("body").fadeIn(200);
                        }
                        function convertToMoney(number){
                            var currency = getCurrentCurrency();
                            return currency.toMoney(number);
                        }
                        function formatWeeks(number){
                            number = number + "";
                            return "<?php _e("Week ", "gravity-forms-bluepay") ?>" + number.substring(number.length-2);
                        }
                        function getCurrentCurrency(){
                            <?php
			if(!class_exists("RGCurrency"))
				require_once(ABSPATH . "/" . PLUGINDIR . "/gravityforms/currency.php");

			$current_currency = RGCurrency::get_currency(GFCommon::get_currency());
?>
                            var currency = new Currency(<?php echo GFCommon::json_encode($current_currency)?>);
                            return currency;
                        }
                    </script>
                <?php
		}
		$payment_totals = RGFormsModel::get_form_payment_totals($config["form_id"]);
		$transaction_totals = GFBluePayData::get_transaction_totals($config["form_id"]);

		switch($config["meta"]["type"]){
		case "product" :
			$total_sales = $payment_totals["orders"];
			$sales_label = __("Total Orders", "gravity-forms-bluepay");
			break;

		case "donation" :
			$total_sales = $payment_totals["orders"];
			$sales_label = __("Total Donations", "gravity-forms-bluepay");
			break;

		}

		$total_revenue = empty($transaction_totals["payment"]["revenue"]) ? 0 : $transaction_totals["payment"]["revenue"];
?>
                <div class="blue_pay_summary_container">
                    <div class="blue_pay_summary_item">
                        <div class="blue_pay_summary_title"><?php _e("Total Revenue", "gravity-forms-bluepay")?></div>
                        <div class="blue_pay_summary_value"><?php echo GFCommon::to_money($total_revenue) ?></div>
                    </div>
                    <div class="blue_pay_summary_item">
                        <div class="blue_pay_summary_title"><?php echo $chart_info["revenue_label"]?></div>
                        <div class="blue_pay_summary_value"><?php echo $chart_info["revenue"] ?></div>
                    </div>
                    <div class="blue_pay_summary_item">
                        <div class="blue_pay_summary_title"><?php echo $sales_label?></div>
                        <div class="blue_pay_summary_value"><?php echo $total_sales ?></div>
                    </div>
                    <div class="blue_pay_summary_item">
                        <div class="blue_pay_summary_title"><?php echo $chart_info["sales_label"] ?></div>
                        <div class="blue_pay_summary_value"><?php echo $chart_info["sales"] ?></div>
                    </div>
                </div>
                <?php
		if(!$chart_info["series"] && $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_duration"])){
?>
                    <div class="blue_pay_trial_disclaimer"><?php _e("** Free trial transactions will only be reflected in the graph after the first payment is made (i.e. after trial period ends)", "gravity-forms-bluepay") ?></div>
                    <?php
		}
?>
            </form>
        </div>
        <?php
	}

	private static function get_graph_timestamp($local_datetime){
		$local_timestamp = mysql2date("G", $local_datetime); //getting timestamp with timezone adjusted
		$local_date_timestamp = mysql2date("G", gmdate("Y-m-d 23:59:59", $local_timestamp)); //setting time portion of date to midnight (to match the way Javascript handles dates)
		$timestamp = ($local_date_timestamp - (24 * 60 * 60) + 1) * 1000; //adjusting timestamp for Javascript (subtracting a day and transforming it to milliseconds
		$date = gmdate("Y-m-d",$timestamp);
		return $timestamp;
	}

	private static function matches_current_date($format, $js_timestamp){
		$target_date = $format == "YW" ? $js_timestamp : date($format, $js_timestamp / 1000);

		$current_date = gmdate($format, GFCommon::get_local_timestamp(time()));
		return $target_date == $current_date;
	}

	private static function daily_chart_info($config){
		global $wpdb;

		$tz_offset = self::get_mysql_tz_offset();

		$results = $wpdb->get_results("SELECT CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "') as date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                        FROM {$wpdb->prefix}rg_lead l
                                        INNER JOIN {$wpdb->prefix}rg_blue_pay_transaction t ON l.id = t.entry_id
                                        WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                        GROUP BY date(date)
                                        ORDER BY payment_date desc
                                        LIMIT 30");

		$sales_today = 0;
		$revenue_today = 0;
		$tooltips = "";
		$series = "";
		$options ="";
		if(!empty($results)){

			$data = "[";

			foreach($results as $result){
				$timestamp = self::get_graph_timestamp($result->date);
				if(self::matches_current_date("Y-m-d", $timestamp)){
					$sales_today += $result->new_sales;
					$revenue_today += $result->amount_sold;
				}
				$data .="[{$timestamp},{$result->amount_sold}],";


				$sales_line = "<div class='blue_pay_tooltip_sales'><span class='blue_pay_tooltip_heading'>" . __("Orders", "gravity-forms-bluepay") . ": </span><span class='blue_pay_tooltip_value'>" . $result->new_sales . "</span></div>";

				$tooltips .= "\"<div class='blue_pay_tooltip_date'>" . GFCommon::format_date($result->date, false, "", false) . "</div>{$sales_line}<div class='blue_pay_tooltip_revenue'><span class='blue_pay_tooltip_heading'>" . __("Revenue", "gravity-forms-bluepay") . ": </span><span class='blue_pay_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
			}
			$data = substr($data, 0, strlen($data)-1);
			$tooltips = substr($tooltips, 0, strlen($tooltips)-1);
			$data .="]";

			$series = "[{data:" . $data . "}]";
			$month_names = self::get_chart_month_names();
			$options ="
            {
                xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %d', minTickSize:[1, 'day']},
                yaxis: {tickFormatter: convertToMoney},
                bars: {show:true, align:'right', barWidth: (24 * 60 * 60 * 1000) - 10000000},
                colors: ['#a3bcd3', '#14568a'],
                grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
            }";
		}
		switch($config["meta"]["type"]){
		case "product" :
			$sales_label = __("Orders Today", "gravity-forms-bluepay");
			break;

		case "donation" :
			$sales_label = __("Donations Today", "gravity-forms-bluepay");
			break;

		}
		$revenue_today = GFCommon::to_money($revenue_today);
		return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue Today", "gravity-forms-bluepay"), "revenue" => $revenue_today, "sales_label" => $sales_label, "sales" => $sales_today);
	}

	private static function weekly_chart_info($config){
		global $wpdb;

		$tz_offset = self::get_mysql_tz_offset();

		$results = $wpdb->get_results("SELECT yearweek(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "')) week_number, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_blue_pay_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            GROUP BY week_number
                                            ORDER BY week_number desc
                                            LIMIT 30");
		$sales_week = 0;
		$revenue_week = 0;
		$tooltips = '';

		if(!empty($results))
		{
			$data = "[";

			foreach($results as $result){
				if(self::matches_current_date("YW", $result->week_number)){
					$sales_week += $result->new_sales;
					$revenue_week += $result->amount_sold;
				}
				$data .="[{$result->week_number},{$result->amount_sold}],";

				$sales_line = "<div class='blue_pay_tooltip_sales'><span class='blue_pay_tooltip_heading'>" . __("Orders", "gravity-forms-bluepay") . ": </span><span class='blue_pay_tooltip_value'>" . $result->new_sales . "</span></div>";

				$tooltips .= "\"<div class='blue_pay_tooltip_date'>" . substr($result->week_number, 0, 4) . ", " . __("Week",  "gravity-forms-bluepay") . " " . substr($result->week_number, strlen($result->week_number)-2, 2) . "</div>{$sales_line}<div class='blue_pay_tooltip_revenue'><span class='blue_pay_tooltip_heading'>" . __("Revenue", "gravity-forms-bluepay") . ": </span><span class='blue_pay_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
			}
			$data = substr($data, 0, strlen($data)-1);
			$tooltips = substr($tooltips, 0, strlen($tooltips)-1);
			$data .="]";

			$series = "[{data:" . $data . "}]";
			$month_names = self::get_chart_month_names();
			$options ="
                {
                    xaxis: {tickFormatter: formatWeeks, tickDecimals: 0},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth:0.95},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
		}

		switch($config["meta"]["type"]){
		case "product" :
			$sales_label = __("Orders this Week", "gravity-forms-bluepay");
			break;

		case "donation" :
			$sales_label = __("Donations this Week", "gravity-forms-bluepay");
			break;

		}
		$revenue_week = GFCommon::to_money($revenue_week);

		return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Week", "gravity-forms-bluepay"), "revenue" => $revenue_week, "sales_label" => $sales_label , "sales" => $sales_week);
	}

	private static function monthly_chart_info($config){
		global $wpdb;
		$tz_offset = self::get_mysql_tz_offset();

		$results = $wpdb->get_results("SELECT date_format(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "'), '%Y-%m-02') date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_blue_pay_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            group by date
                                            order by date desc
                                            LIMIT 30");

		$sales_month = 0;
		$revenue_month = 0;
		$tooltips = '';
		if(!empty($results)){

			$data = "[";

			foreach($results as $result){
				$timestamp = self::get_graph_timestamp($result->date);
				if(self::matches_current_date("Y-m", $timestamp)){
					$sales_month += $result->new_sales;
					$revenue_month += $result->amount_sold;
				}
				$data .="[{$timestamp},{$result->amount_sold}],";

				$sales_line = "<div class='blue_pay_tooltip_sales'><span class='blue_pay_tooltip_heading'>" . __("Orders", "gravity-forms-bluepay") . ": </span><span class='blue_pay_tooltip_value'>" . $result->new_sales . "</span></div>";

				$tooltips .= "\"<div class='blue_pay_tooltip_date'>" . GFCommon::format_date($result->date, false, "F, Y", false) . "</div>{$sales_line}<div class='blue_pay_tooltip_revenue'><span class='blue_pay_tooltip_heading'>" . __("Revenue", "gravity-forms-bluepay") . ": </span><span class='blue_pay_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
			}
			$data = substr($data, 0, strlen($data)-1);
			$tooltips = substr($tooltips, 0, strlen($tooltips)-1);
			$data .="]";

			$series = "[{data:" . $data . "}]";
			$month_names = self::get_chart_month_names();
			$options ="
                {
                    xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %y', minTickSize: [1, 'month']},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth: (24 * 60 * 60 * 30 * 1000) - 130000000},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
		}
		switch($config["meta"]["type"]){
		case "product" :
			$sales_label = __("Orders this Month", "gravity-forms-bluepay");
			break;

		case "donation" :
			$sales_label = __("Donations this Month", "gravity-forms-bluepay");
			break;

		case "subscription" :
			$sales_label = __("Subscriptions this Month", "gravity-forms-bluepay");
			break;
		}
		$revenue_month = GFCommon::to_money($revenue_month);
		return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Month", "gravity-forms-bluepay"), "revenue" => $revenue_month, "sales_label" => $sales_label, "sales" => $sales_month);
	}

	private static function get_mysql_tz_offset(){
		$tz_offset = get_option("gmt_offset");

		//add + if offset starts with a number
		if(is_numeric(substr($tz_offset, 0, 1)))
			$tz_offset = "+" . $tz_offset;

		return $tz_offset . ":00";
	}

	private static function get_chart_month_names(){
		return "['" . __("Jan", "gravity-forms-bluepay") ."','" . __("Feb", "gravity-forms-bluepay") ."','" . __("Mar", "gravity-forms-bluepay") ."','" . __("Apr", "gravity-forms-bluepay") ."','" . __("May", "gravity-forms-bluepay") ."','" . __("Jun", "gravity-forms-bluepay") ."','" . __("Jul", "gravity-forms-bluepay") ."','" . __("Aug", "gravity-forms-bluepay") ."','" . __("Sep", "gravity-forms-bluepay") ."','" . __("Oct", "gravity-forms-bluepay") ."','" . __("Nov", "gravity-forms-bluepay") ."','" . __("Dec", "gravity-forms-bluepay") ."']";
	}

	// Edit Page
	private static function edit_page(){
		require_once(GFCommon::get_base_path() . "/currency.php");
?>
        <style>
            #blue_pay_submit_container{clear:both;}
            .blue_pay_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold; width:120px;}
            .blue_pay_field_cell {padding: 6px 17px 0 0; margin-right:15px;}

            .blue_pay_validation_error{ background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border:1px dotted #C89797;}
            .blue_pay_validation_error span {color: red;}
            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0; padding-left:5px;}
            .margin_vertical_30{margin: 30px 0; padding-left:5px;}
            .width-1{width:300px;}
            .gf_blue_pay_invalid_form{margin-top:30px; background-color:#FFEBE8;border:1px solid #CC0000; padding:10px; width:600px;}
        </style>

        <script type="text/javascript" src="<?php echo GFCommon::get_base_url()?>/js/gravityforms.js"> </script>
        <script type="text/javascript">
            var form = Array();

            window['gf_currency_config'] = <?php echo json_encode(RGCurrency::get_currency("USD")) ?>;
            function FormatCurrency(element){
                var val = jQuery(element).val();
                jQuery(element).val(gformFormatMoney(val));
            }

            function ToggleSetupFee(){
                if(jQuery('#gf_blue_pay_setup_fee').is(':checked')){
                    jQuery('#blue_pay_setup_fee_container').show('slow');
                    jQuery('#blue_pay_enable_trial_container, #blue_pay_trial_period_container').slideUp();
                }
                else{
                    jQuery('#blue_pay_setup_fee_container').hide('slow');
                    jQuery('#blue_pay_enable_trial_container').slideDown();
                    ToggleTrial();
                }
            }

            function ToggleTrial(){
                if(jQuery('#gf_blue_pay_trial_period').is(':checked'))
                    jQuery('#blue_pay_trial_period_container').show('slow');
                else
                    jQuery('#blue_pay_trial_period_container').hide('slow');
            }

        </script>

        <div class="wrap">
            <img alt="<?php _e("BluePay", "gravity-forms-bluepay") ?>" style="margin: 7px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/blue_pay_wordpress_icon_32.png"/>
            <h2><?php _e("BluePay Transaction Settings", "gravity-forms-bluepay") ?></h2>

        <?php

		//getting setting id (0 when creating a new one)
		$id = !empty($_POST["blue_pay_setting_id"]) ? $_POST["blue_pay_setting_id"] : absint($_GET["id"]);
		$config = empty($id) ? array("meta" => array(), "is_active" => true) : GFBluePayData::get_feed($id);
		$setup_fee_field_conflict = false; //initialize variable

		//updating meta information
		if(rgpost("gf_blue_pay_submit")){

			$config["form_id"] = absint(rgpost("gf_blue_pay_form"));
			$config["meta"]["type"] = rgpost("gf_blue_pay_type");
			$config["meta"]["enable_receipt"] = rgpost('gf_blue_pay_enable_receipt');
			$config["meta"]["update_post_action"] = rgpost('gf_blue_pay_update_action');

			// blue_pay conditional
			$config["meta"]["blue_pay_conditional_enabled"] = rgpost('gf_blue_pay_conditional_enabled');
			$config["meta"]["blue_pay_conditional_field_id"] = rgpost('gf_blue_pay_conditional_field_id');
			$config["meta"]["blue_pay_conditional_operator"] = rgpost('gf_blue_pay_conditional_operator');
			$config["meta"]["blue_pay_conditional_value"] = rgpost('gf_blue_pay_conditional_value');

			//recurring fields
			$config["meta"]["recurring_amount_field"] = rgpost("gf_blue_pay_recurring_amount");
			$config["meta"]["billing_cycle"] = rgpost("gf_blue_pay_billing_cycle");
			$config["meta"]["recurring_times"] = rgpost("gf_blue_pay_recurring_times");
			$config["meta"]["recurring_retry"] = rgpost('gf_blue_pay_recurring_retry');
			$config["meta"]["setup_fee_enabled"] = rgpost('gf_blue_pay_setup_fee');
			$config["meta"]["setup_fee_amount_field"] = rgpost('gf_blue_pay_setup_fee_amount');

			$has_setup_fee = $config["meta"]["setup_fee_enabled"];
			$config["meta"]["trial_period_enabled"] = $has_setup_fee ? false : rgpost('gf_blue_pay_trial_period');
			$config["meta"]["trial_duration"] = $has_setup_fee ? "" : rgpost('gf_blue_pay_trial_duration');
			$config["meta"]["trial_period_number"] = "1"; //$has_setup_fee ? "" : rgpost('gf_blue_pay_trial_period_number');

			//api settings fields
			$config["meta"]["api_settings_enabled"] = rgpost('gf_blue_pay_api_settings');
			$config["meta"]["api_mode"] = rgpost('gf_blue_pay_api_mode');
			$config["meta"]["api_account_id"] = rgpost('gf_blue_pay_api_account_id');
			$config["meta"]["api_secret_key"] = rgpost('gf_blue_pay_api_secret_key');
			$config["meta"]["api_transaction_mode"] = rgpost('gf_blue_pay_api_transaction_mode');


			//-----------------

			$customer_fields = self::get_customer_fields();
			$config["meta"]["customer_fields"] = array();
			foreach($customer_fields as $field){
				$config["meta"]["customer_fields"][$field["name"]] = $_POST["blue_pay_customer_field_{$field["name"]}"];
			}

			$config = apply_filters('gform_blue_pay_save_config', $config);

			$setup_fee_field_conflict = $has_setup_fee && $config["meta"]["recurring_amount_field"] == $config["meta"]["setup_fee_amount_field"];

			if(!$setup_fee_field_conflict){
				$id = GFBluePayData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
?>
                <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravity-forms-bluepay"), "<a href='?page=gf_blue_pay'>", "</a>") ?></div>
                <?php
			}
			else{
				$setup_fee_field_conflict = true;
			}
		}

		$form = isset($config["form_id"]) && $config["form_id"] ? $form = RGFormsModel::get_form_meta($config["form_id"]) : array();
		$settings = get_option("gf_blue_pay_settings");
?>
        <form method="post" action="">
            <input type="hidden" name="blue_pay_setting_id" value="<?php echo $id ?>" />

            <div style="padding: 15px; margin:15px 0px" class="<?php echo $setup_fee_field_conflict ? "error" : "" ?>">
                <?php
		if($setup_fee_field_conflict){
?>
                    <span><?php _e('There was an issue saving your feed.', 'gravity-forms-bluepay'); ?></span>
                    <span><?php _e('Recurring Amount and Setup Fee must be assigned to different fields.', 'gravity-forms-bluepay'); ?></span>
                    <?php
		}
?>
            </div> <!-- / validation message -->

            <?php
		if($settings["mb_configured"]=="on") {
?>
            <div class="margin_vertical_10">
                <label class="left_header" for="gf_blue_pay_type"><?php _e("Transaction Type", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_transaction_type") ?></label>

                <select id="gf_blue_pay_type" name="gf_blue_pay_type" onchange="SelectType(jQuery(this).val());">
                    <option value=""><?php _e("Select a transaction type", "gravity-forms-bluepay") ?></option>
                    <option value="product" <?php echo rgar($config['meta'], 'type') == "product" ? "selected='selected'" : "" ?>><?php _e("Products and Services", "gravity-forms-bluepay") ?></option>
                </select>
            </div>
            <?php } else {$config["meta"]["type"]= "product" ?>

                  <input id="gf_blue_pay_type" type="hidden" name="gf_blue_pay_type" value="product">


            <?php } ?>
            <div id="blue_pay_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["type"]) ? "style='display:none;'" : "" ?>>
                <label for="gf_blue_pay_form" class="left_header"><?php _e("Gravity Form", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_gravity_form") ?></label>

                <select id="gf_blue_pay_form" name="gf_blue_pay_form" onchange="SelectForm(jQuery('#gf_blue_pay_type').val(), jQuery(this).val(), '<?php echo rgar($config, 'id') ?>');">
                    <option value=""><?php _e("Select a form", "gravity-forms-bluepay"); ?> </option>
                    <?php

		$active_form = rgar($config, 'form_id');
		$available_forms = GFBluePayData::get_available_forms($active_form);

		foreach($available_forms as $current_form) {
			$selected = absint($current_form->id) == rgar($config, 'form_id') ? 'selected="selected"' : '';
?>

                            <option value="<?php echo absint($current_form->id) ?>" <?php echo $selected; ?>><?php echo esc_html($current_form->title) ?></option>

                        <?php
		}
?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFBluePay::get_base_url() ?>/images/loading.gif" id="blue_pay_wait" style="display: none;"/>

                <div id="gf_blue_pay_invalid_product_form" class="gf_blue_pay_invalid_form"  style="display:none;">
                    <?php _e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "gravity-forms-bluepay") ?>
                </div>
                <div id="gf_blue_pay_invalid_creditcard_form" class="gf_blue_pay_invalid_form" style="display:none;">
                    <?php _e("The form selected does not have a credit card field. Please add a credit card field to the form and try again.", "gravity-forms-bluepay") ?>
                </div>
            </div>
            <div id="blue_pay_field_group" valign="top" <?php echo strlen(rgars($config,"meta/type")) == 0 || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>

                <div id="blue_pay_field_container_subscription" class="blue_pay_field_container" valign="top" <?php echo rgars($config,"meta/type") != "subscription" ? "style='display:none;'" : ""?>>
                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_blue_pay_recurring_amount"><?php _e("Recurring Amount", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_recurring_amount") ?></label>
                        <select id="gf_blue_pay_recurring_amount" name="gf_blue_pay_recurring_amount">
                            <?php echo self::get_product_options($form, rgar($config["meta"],"recurring_amount_field"),true) ?>
                        </select>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_blue_pay_billing_cycle"><?php _e("Billing Cycle", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_billing_cycle") ?></label>
                        <select id="gf_blue_pay_billing_cycle" name="gf_blue_pay_billing_cycle">
                        	<?php foreach(self::subscription_options() as $name => $desc): ?>
                        	<option value="<?php echo $name ?>" <?php echo rgar($config["meta"],"billing_cycle") == $name ? "selected='selected'" : "" ?>><?php echo $desc ?></option>
                        	<?php endforeach; ?>
                        </select>
						<?php /*
                        <select id="gf_blue_pay_billing_cycle_number" name="gf_blue_pay_billing_cycle_number">
                            <?php
		for($i=1; $i<=100; $i++){
?>
                                <option value="<?php echo $i ?>" <?php echo rgar($config["meta"],"billing_cycle_number") == $i ? "selected='selected'" : "" ?>><?php echo $i ?></option>
                            <?php
		}
?>
                        </select>&nbsp;
                        <select id="gf_blue_pay_billing_cycle_type" name="gf_blue_pay_billing_cycle_type" onchange="SetPeriodNumber('#gf_blue_pay_billing_cycle_number', jQuery(this).val());">
                            <option value="D" <?php echo rgars($config,"meta/billing_cycle_type") == "D" ? "selected='selected'" : "" ?>><?php _e("day(s)", "gravity-forms-bluepay") ?></option>
                            <option value="M" <?php echo rgars($config,"meta/billing_cycle_type") == "M" || strlen(rgars($config,"meta/billing_cycle_type")) == 0 ? "selected='selected'" : "" ?>><?php _e("month(s)", "gravity-forms-bluepay") ?></option>
                        </select>
                        */ ?>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_blue_pay_recurring_times"><?php _e("Recurring Times", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_recurring_times") ?></label>
                        <select id="gf_blue_pay_recurring_times" name="gf_blue_pay_recurring_times">
                            <option><?php _e("Infinite", "gravity-forms-bluepay") ?></option>
                            <?php
		for($i=2; $i<=100; $i++){
			$selected = ($i == rgar($config["meta"],"recurring_times")) ? 'selected="selected"' : '';
?>
                                <option value="<?php echo $i ?>" <?php echo $selected; ?>><?php echo $i ?></option>
                                <?php
		}
?>
                        </select>&nbsp;&nbsp;

                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_blue_pay_setup_fee"><?php _e("Setup Fee", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_setup_fee_enable") ?></label>
                        <input type="checkbox" onchange="if(this.checked) {jQuery('#gf_paypalpro_setup_fee_amount').val('Select a field');}" name="gf_blue_pay_setup_fee" id="gf_blue_pay_setup_fee" value="1" onclick="ToggleSetupFee();" <?php echo rgars($config, "meta/setup_fee_enabled") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_blue_pay_setup_fee"><?php _e("Enable", "gravity-forms-bluepay"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <span id="blue_pay_setup_fee_container" <?php echo rgars($config, "meta/setup_fee_enabled") ? "" : "style='display:none;'" ?>>
                            <select id="gf_blue_pay_setup_fee_amount" name="gf_blue_pay_setup_fee_amount">
                                <?php echo self::get_product_options($form, rgar($config["meta"],"setup_fee_amount_field"),false) ?>
                            </select>
                        </span>
                    </div>

                    <div id="blue_pay_enable_trial_container" class="margin_vertical_10" <?php echo rgars($config, "meta/setup_fee_enabled") ? "style='display:none;'" : "" ?>>
                        <label class="left_header" for="gf_blue_pay_trial_period"><?php _e("Trial Period", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_trial_period_enable") ?></label>
                        <input type="checkbox" name="gf_blue_pay_trial_period" id="gf_blue_pay_trial_period" value="1" onclick="ToggleTrial();" <?php echo rgars($config,"meta/trial_period_enabled") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_blue_pay_trial_period"><?php _e("Enable", "gravity-forms-bluepay"); ?></label>
                    </div>

                    <div id="blue_pay_trial_period_container" <?php echo rgars($config,"meta/trial_period_enabled")  && !rgars($config, "meta/setup_fee_enabled") ? "" : "style='display:none;'" ?>>
                        <div class="margin_vertical_10">
                            <label class="left_header" for="gf_blue_pay_trial_duration"><?php _e("Trial Druation (days)", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_trial_duration") ?></label>
                            <input type="text" name="gf_blue_pay_trial_duration" id="gf_blue_pay_trial_duration" value="<?php echo rgar($config["meta"],"trial_duration") ?>" />
                        </div>
                        <!--<div class="margin_vertical_10">
                            <label class="left_header" for="gf_blue_pay_trial_period_number"><?php _e("Trial Recurring Times", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_trial_period") ?></label>
                            <select id="gf_blue_pay_trial_period_number" name="gf_blue_pay_trial_period_number">
                                <?php
		for($i=1; $i<=99; $i++){
?>
                                    <option value="<?php echo $i ?>" <?php echo rgars($config,"meta/trial_period_number") == $i ? "selected='selected'" : "" ?>><?php echo $i ?></option>
                                <?php
		}
?>
                            </select>
                        </div>-->

                    </div>

                </div>

                <div class="margin_vertical_10">
                    <label class="left_header"><?php _e("Billing Information", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_customer") ?></label>

                    <div id="blue_pay_customer_fields">
                        <?php
		if(!empty($form))
			echo self::get_customer_information($form, $config);
?>
                    </div>
                </div>

            	<?php /* No options for now
                <div class="margin_vertical_10">
                    <label class="left_header"><?php _e("Options", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_options") ?></label>

                    <ul style="overflow:hidden;">
                        <li id="blue_pay_enable_receipt">
                            <input type="checkbox" name="gf_blue_pay_enable_receipt" id="gf_blue_pay_enable_receipt" value="1" <?php echo rgar($config["meta"], 'enable_receipt') ? "checked='checked'"  : "" ?> />
                            <label class="inline" for="gf_blue_pay_enable_receipt"><?php _e("Send BluePay email receipt.", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_disable_user_notification") ?></label>
                        </li>

                        <?php
		$display_post_fields = !empty($form) ? GFCommon::has_post_field($form["fields"]) : false;
?>
                        <li id="blue_pay_post_update_action" <?php echo $display_post_fields && $config["meta"]["type"] == "subscription" ? "" : "style='display:none;'" ?>>
                            <input type="checkbox" name="gf_blue_pay_update_post" id="gf_blue_pay_update_post" value="1" <?php echo rgar($config["meta"],"update_post_action") ? "checked='checked'" : ""?> onclick="var action = this.checked ? 'draft' : ''; jQuery('#gf_blue_pay_update_action').val(action);" />
                            <label class="inline" for="gf_blue_pay_update_post"><?php _e("Update Post when subscription is cancelled.", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_update_post") ?></label>
                            <select id="gf_blue_pay_update_action" name="gf_blue_pay_update_action" onchange="var checked = jQuery(this).val() ? 'checked' : false; jQuery('#gf_blue_pay_update_post').attr('checked', checked);">
                                <option value=""></option>
                                <option value="draft" <?php echo rgar($config["meta"],"update_post_action") == "draft" ? "selected='selected'" : ""?>><?php _e("Mark Post as Draft", "gravity-forms-bluepay") ?></option>
                                <option value="delete" <?php echo rgar($config["meta"],"update_post_action") == "delete" ? "selected='selected'" : ""?>><?php _e("Delete Post", "gravity-forms-bluepay") ?></option>
                            </select>
                        </li>

                        <?php do_action("gform_blue_pay_action_fields", $config, $form) ?>
                    </ul>
                </div>
                <?php do_action("gform_blue_pay_add_option_group", $config, $form); ?>
				*/?>

                <div id="gf_blue_pay_conditional_section" valign="top" class="margin_vertical_10">
                    <label for="gf_blue_pay_conditional_optin" class="left_header"><?php _e("BluePay Condition", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_conditional") ?></label>

                    <div id="gf_blue_pay_conditional_option">
                        <table cellspacing="0" cellpadding="0">
                            <tr>
                                <td>
                                    <input type="checkbox" id="gf_blue_pay_conditional_enabled" name="gf_blue_pay_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_blue_pay_conditional_container').fadeIn('fast');} else{ jQuery('#gf_blue_pay_conditional_container').fadeOut('fast'); }" <?php echo rgar($config['meta'], 'blue_pay_conditional_enabled') ? "checked='checked'" : ""?>/>
                                    <label for="gf_blue_pay_conditional_enable"><?php _e("Enable", "gravity-forms-bluepay"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="gf_blue_pay_conditional_container" <?php echo !rgar($config['meta'], 'blue_pay_conditional_enabled') ? "style='display:none'" : ""?>>
                                        <div id="gf_blue_pay_conditional_fields" style="display:none">
                                            <?php _e("Send to BluePay if ", "gravity-forms-bluepay") ?>
                                            <select id="gf_blue_pay_conditional_field_id" name="gf_blue_pay_conditional_field_id" class="optin_select" onchange='jQuery("#gf_blue_pay_conditional_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'></select>
                                            <select id="gf_blue_pay_conditional_operator" name="gf_blue_pay_conditional_operator">
                                                <option value="is" <?php echo rgar($config['meta'], 'blue_pay_conditional_operator') == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravity-forms-bluepay") ?></option>
                                                <option value="isnot" <?php echo rgar($config['meta'], 'blue_pay_conditional_operator') == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravity-forms-bluepay") ?></option>
                                                <option value=">" <?php echo rgar($config['meta'], 'blue_pay_conditional_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravity-forms-bluepay") ?></option>
                                                <option value="<" <?php echo rgar($config['meta'], 'blue_pay_conditional_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravity-forms-bluepay") ?></option>
                                                <option value="contains" <?php echo rgar($config['meta'], 'blue_pay_conditional_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravity-forms-bluepay") ?></option>
                                                <option value="starts_with" <?php echo rgar($config['meta'], 'blue_pay_conditional_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravity-forms-bluepay") ?></option>
                                                <option value="ends_with" <?php echo rgar($config['meta'], 'blue_pay_conditional_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravity-forms-bluepay") ?></option>
                                            </select>
                                            <div id="gf_blue_pay_conditional_value_container" name="gf_blue_pay_conditional_value_container" style="display:inline;"></div>
                                        </div>
                                        <div id="gf_blue_pay_conditional_message" style="display:none">
                                            <?php _e("To create a registration condition, your form must have a field supported by conditional logic.", "gravityform"); ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                </div> <!-- / blue_pay conditional -->

                <div class="margin_vertical_10">
                        <label class="left_header" for="gf_blue_pay_api_settings"><?php _e("API Settings", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_api_settings_enable") ?></label>
                        <input type="checkbox" name="gf_blue_pay_api_settings" id="gf_blue_pay_api_settings" value="1" onclick="if(jQuery(this).is(':checked')) jQuery('#blue_pay_api_settings_container').show('slow'); else jQuery('#blue_pay_api_settings_container').hide('slow');" <?php echo rgars($config, "meta/api_settings_enabled") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_blue_pay_api_settings"><?php _e("Override Default Settings", "gravity-forms-bluepay"); ?></label>
                </div>

                <div id="blue_pay_api_settings_container" <?php echo rgars($config, "meta/api_settings_enabled") ? "" : "style='display:none;'" ?>>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_blue_pay_api_mode"><?php _e("Mode", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_api_mode") ?></label>
                        <input type="radio" name="gf_blue_pay_api_mode" id="gf_blue_pay_api_mode_live" value="LIVE" <?php echo rgar($config["meta"],"api_mode") != "TEST" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_blue_pay_api_mode_live"><?php _e("Live", "gravity-forms-bluepay"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="gf_blue_pay_api_mode" id="gf_blue_pay_api_mode_test" value="TEST" <?php echo rgar($config["meta"],"api_mode") == "TEST" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_blue_pay_api_mode_test"><?php _e("Test", "gravity-forms-bluepay"); ?></label>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_blue_pay_api_account_id"><?php _e("Account ID", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_api_account_id") ?></label>
                        <input class="size-1" id="gf_blue_pay_api_account_id" name="gf_blue_pay_api_account_id" value="<?php echo rgar($config["meta"],"api_account_id") ?>" />
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_blue_pay_api_secret_key"><?php _e("Secret Key", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_api_secret_key") ?></label>
                        <input class="size-1" id="gf_blue_pay_api_secret_key" name="gf_blue_pay_api_secret_key" value="<?php echo rgar($config["meta"],"api_secret_key") ?>" />
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_blue_pay_api_transaction_mode"><?php _e("Transaction Mode", "gravity-forms-bluepay"); ?> <?php gform_tooltip("blue_pay_api_transaction_mode") ?></label>
                        <input type="radio" name="gf_blue_pay_api_transaction_mode" id="gf_blue_pay_api_transaction_mode_sale" value="sale" <?php echo rgar($config["meta"],"api_transaction_mode") != "auth" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_blue_pay_api_transaction_mode_sale"><?php _e("Test", "gravity-forms-bluepay"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="gf_blue_pay_api_transaction_mode" id="gf_blue_pay_api_transaction_mode_auth" value="auth" <?php echo rgar($config["meta"],"api_transaction_mode") == "auth" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_blue_pay_api_transaction_mode_auth"><?php _e("Live", "gravity-forms-bluepay"); ?></label>
                    </div>

                </div>

                <div id="blue_pay_submit_container" class="margin_vertical_30">
                    <input type="submit" name="gf_blue_pay_submit" value="<?php echo empty($id) ? __("  Save  ", "gravity-forms-bluepay") : __("Update", "gravity-forms-bluepay"); ?>" class="button-primary"/>
                    <input type="button" value="<?php _e("Cancel", "gravity-forms-bluepay"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_blue_pay'" />
                </div>
            </div>
        </form>
        </div>

        <script type="text/javascript">
            <?php
		if(!empty($config["form_id"])){
?>
                // initiliaze form object
                form = <?php echo GFCommon::json_encode($form)?> ;

                // initializing registration condition drop downs
                jQuery(document).ready(function(){
                    var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["blue_pay_conditional_field_id"])?>";
                    var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["blue_pay_conditional_value"])?>";
                    SetBluePayCondition(selectedField, selectedValue);
                });

                <?php
		}
?>

            function SelectType(type){
                jQuery("#blue_pay_field_group").slideUp();

                jQuery("#blue_pay_field_group input[type=\"text\"], #blue_pay_field_group select").val("");
                jQuery("#gf_blue_pay_trial_period_type, #gf_blue_pay_billing_cycle_type").val("M");

                jQuery("#blue_pay_field_group input:checked").attr("checked", false);

                if(type){
                    jQuery("#blue_pay_form_container").slideDown();
                    jQuery("#gf_blue_pay_form").val("");
                }
                else{
                    jQuery("#blue_pay_form_container").slideUp();
                }
            }

            function SelectForm(type, formId, settingId){
                if(!formId){
                    jQuery("#blue_pay_field_group").slideUp();
                    return;
                }

                jQuery("#blue_pay_wait").show();
                jQuery("#blue_pay_field_group").slideUp();

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_blue_pay_form" );
                mysack.setVar( "gf_select_blue_pay_form", "<?php echo wp_create_nonce("gf_select_blue_pay_form") ?>" );
                mysack.setVar( "type", type);
                mysack.setVar( "form_id", formId);
                mysack.setVar( "setting_id", settingId);
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() {jQuery("#blue_pay_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravity-forms-bluepay") ?>' )};
                mysack.runAJAX();

                return true;
            }

            function EndSelectForm(form_meta, customer_fields, recurring_amount_options, product_field_options){
                //setting global form object
                form = form_meta;

                var type = jQuery("#gf_blue_pay_type").val();

                jQuery(".gf_blue_pay_invalid_form").hide();
                if( (type == "product" || type =="subscription") && GetFieldsByType(["product"]).length == 0){
                    jQuery("#gf_blue_pay_invalid_product_form").show();
                    jQuery("#blue_pay_wait").hide();
                    return;
                }


                jQuery(".blue_pay_field_container").hide();
                jQuery("#blue_pay_customer_fields").html(customer_fields);
                jQuery("#gf_blue_pay_recurring_amount").html(recurring_amount_options);

                jQuery("#gf_blue_pay_setup_fee_amount").html(product_field_options);

                var post_fields = GetFieldsByType(["post_title", "post_content", "post_excerpt", "post_category", "post_custom_field", "post_image", "post_tag"]);
                if(type == "subscription" && post_fields.length > 0){
                    jQuery("#blue_pay_post_update_action").show();
                }
                else{
                    jQuery("#gf_blue_pay_update_post").attr("checked", false);
                    jQuery("#blue_pay_post_update_action").hide();
                }

                //Calling callback functions
                jQuery(document).trigger('blue_payFormSelected', [form]);

                jQuery("#gf_blue_pay_conditional_enabled").attr('checked', false);
                SetBluePayCondition("","");

                jQuery("#blue_pay_field_container_" + type).show();
                jQuery("#blue_pay_field_group").slideDown();
                jQuery("#blue_pay_wait").hide();
            }

            function SetPeriodNumber(element, type){
                var prev = jQuery(element).val();

                var min = 1;
                var max = 0;
                switch(type){
                    case "D" :
                        min = 1;
                        max = 365;
                    break;
                    case "M" :
                        max = 12;
                    break;
                }
                var str="";
                for(var i=min; i<=max; i++){
                    var selected = prev == i ? "selected='selected'" : "";
                    str += "<option value='" + i + "' " + selected + ">" + i + "</option>";
                }
                jQuery(element).html(str);
            }

            function GetFieldsByType(types){
                var fields = new Array();
                for(var i=0; i<form["fields"].length; i++){
                    if(IndexOf(types, form["fields"][i]["type"]) >= 0)
                        fields.push(form["fields"][i]);
                }
                return fields;
            }

            function IndexOf(ary, item){
                for(var i=0; i<ary.length; i++)
                    if(ary[i] == item)
                        return i;

                return -1;
            }

            function SetBluePayCondition(selectedField, selectedValue){
                // load form fields
                jQuery("#gf_blue_pay_conditional_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#gf_blue_pay_conditional_field_id").val();
                var checked = jQuery("#gf_blue_pay_conditional_enabled").attr('checked');

                if(optinConditionField){
                    jQuery("#gf_blue_pay_conditional_message").hide();
                    jQuery("#gf_blue_pay_conditional_fields").show();
                    jQuery("#gf_blue_pay_conditional_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    jQuery("#gf_blue_pay_conditional_value").val(selectedValue);
                }
                else{
                    jQuery("#gf_blue_pay_conditional_message").show();
                    jQuery("#gf_blue_pay_conditional_fields").hide();
                }

                if(!checked) jQuery("#gf_blue_pay_conditional_container").hide();

            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field)
                    return "";

                var isAnySelected = false;

                if(field["type"] == "post_category" && field["displayAllCategories"]){
                    str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "gf_blue_pay_conditional_value", "name"=> "gf_blue_pay_conditional_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
                }
                else if(field.choices){
                    str += '<select id="gf_blue_pay_conditional_value" name="gf_blue_pay_conditional_value" class="optin_select">'

                    for(var i=0; i<field.choices.length; i++){
                        var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                        var isSelected = fieldValue == selectedValue;
                        var selected = isSelected ? "selected='selected'" : "";
                        if(isSelected)
                            isAnySelected = true;

                        str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                    }

                    if(!isAnySelected && selectedValue){
                        str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                    }
                    str += "</select>";
                }
                else
                {
                    selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
                    //create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
                    str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='gf_blue_pay_conditional_value' name='gf_blue_pay_conditional_value' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
                }

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;
                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    fieldLabel = typeof fieldLabel == 'undefined' ? '' : fieldLabel;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if (IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function IsConditionalLogicField(field){
                inputType = field.inputType ? field.inputType : field.type;
                var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
                                        "post_tags", "post_custom_field", "post_content", "post_excerpt"];

                var index = jQuery.inArray(inputType, supported_fields);

                return index >= 0;
            }

        </script>

        <?php

	}

	public static function select_blue_pay_form(){

		check_ajax_referer("gf_select_blue_pay_form", "gf_select_blue_pay_form");

		$type = $_POST["type"];
		$form_id =  intval($_POST["form_id"]);
		$setting_id =  intval($_POST["setting_id"]);

		//fields meta
		$form = RGFormsModel::get_form_meta($form_id);

		$customer_fields = self::get_customer_information($form);
		$recurring_amount_fields = self::get_product_options($form, "",true);
		$product_fields = self::get_product_options($form, "",false);

		die("EndSelectForm(" . GFCommon::json_encode($form) . ", '" . str_replace("'", "\'", $customer_fields) . "', '" . str_replace("'", "\'", $recurring_amount_fields) . "', '" . str_replace("'", "\'", $product_fields) . "');");
	}

	public static function add_permissions(){
		global $wp_roles;
		$wp_roles->add_cap("administrator", "gravityforms_blue_pay");
		$wp_roles->add_cap("administrator", "gravityforms_blue_pay_uninstall");
	}

	//Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
	public static function members_get_capabilities( $caps ) {
		return array_merge($caps, array("gravityforms_blue_pay", "gravityforms_blue_pay_uninstall"));
	}

	public static function has_blue_pay_condition($form, $config) {

		$config = $config["meta"];

		$operator = $config["blue_pay_conditional_operator"];
		$field = RGFormsModel::get_field($form, $config["blue_pay_conditional_field_id"]);

		if(empty($field) || !$config["blue_pay_conditional_enabled"])
			return true;

		// if conditional is enabled, but the field is hidden, ignore conditional
		$is_visible = !RGFormsModel::is_field_hidden($form, $field, array());

		$field_value = RGFormsModel::get_field_value($field, array());
		$is_value_match = RGFormsModel::is_value_match($field_value, $config["blue_pay_conditional_value"], $operator);
		$go_to_blue_pay = $is_value_match && $is_visible;

		return  $go_to_blue_pay;
	}

	public static function get_config($form){
		if(!class_exists("GFBluePayData"))
			require_once(self::get_base_path() . "/data.php");

		//Getting blue_pay settings associated with this transaction
		$configs = GFBluePayData::get_feed_by_form($form["id"]);
		if(!$configs)
			return false;

		foreach($configs as $config){
			if(self::has_blue_pay_condition($form, $config))
				return $config;
		}

		return false;
	}

	public static function get_creditcard_field($form){
		$fields = GFCommon::get_fields_by_type($form, array("creditcard"));
		return empty($fields) ? false : $fields[0];
	}

	public static function get_ach_field($form){
		$config = self::get_config($form);

		$fields = false;

		if ( $config && $config["meta"]["customer_fields"]["account_type"] && $config["meta"]["customer_fields"]["account_number"] && $config["meta"]["customer_fields"]["routing_number"] ) {
			$fields = array(
				'account_type' => false,
				'routing_number' => false,
				'account_number' => false
			);

			foreach ( $form[ 'fields' ] as $field ) {
				if ( empty( $fields[ 'account_type' ] ) && $config["meta"]["customer_fields"]["account_type"] == $field[ 'id' ] ) {
					$fields[ 'account_type' ] = $field;
				}
				elseif ( empty( $fields[ 'routing_number' ] ) && $config["meta"]["customer_fields"]["routing_number"] == $field[ 'id' ] ) {
					$fields[ 'routing_number' ] = $field;
				}
				elseif ( empty( $fields[ 'account_number' ] ) && $config["meta"]["customer_fields"]["account_number"] == $field[ 'id' ] ) {
					$fields[ 'account_number' ] = $field;
				}

				if ( !empty( $fields[ 'account_type' ] ) && !empty( $fields[ 'routing_number' ] ) && !empty( $fields[ 'account_number' ] ) ) {
					break;
				}
			}
		}

		return $fields;
	}

	private static function is_ready_for_capture($validation_result){

		//if form has already failed validation or this is not the last page, abort
		if($validation_result["is_valid"] == false || !self::is_last_page($validation_result["form"]))
			return false;

		//getting config that matches condition (if conditions are enabled)
		$config = self::get_config($validation_result["form"]);
		if(!$config)
			return false;

		//making sure credit card field is visible
		$creditcard_field = self::get_creditcard_field($validation_result["form"]);
		$ach_field = self::get_ach_field($validation_result["form"]);

		$valid = false;

		if($creditcard_field && !RGFormsModel::is_field_hidden($validation_result["form"], $creditcard_field, array()))
			$valid = true;

		if($ach_field && !RGFormsModel::is_field_hidden($validation_result["form"], $ach_field[ 'account_type' ], array())
			 && !RGFormsModel::is_field_hidden($validation_result["form"], $ach_field[ 'routing_number' ], array())
			 && !RGFormsModel::is_field_hidden($validation_result["form"], $ach_field[ 'account_number' ], array())) {
			$valid = true;
		}

		if ( $valid ) {
			return $config;
		}

		return false;

	}

	private static function is_last_page($form){
		$current_page = GFFormDisplay::get_source_page($form["id"]);
		$target_page = GFFormDisplay::get_target_page($form, $current_page, rgpost("gform_field_values"));
		return $target_page == 0;
	}

	private static function get_trial_info($config){

		$trial_duration = false;
		$trial_occurrences = 0;
		if($config["meta"]["trial_period_enabled"] == 1)
		{
			$trial_occurrences = $config["meta"]["trial_period_number"];
			$trial_duration = $config["meta"]["trial_duration"];
			if(empty($trial_duration))
				$trial_duration = 0;
		}
		$trial_enabled = $trial_duration !== false;

		if($trial_enabled && !empty($trial_duration))
			$trial_duration = GFCommon::to_number($trial_duration);

		return array("trial_enabled" => $trial_enabled, "trial_duration" => $trial_duration, "trial_occurrences" => $trial_occurrences);
	}

	private static function get_recurring_frequency( $frequency, $start ){
		$ret = '';
		// $start arrives as mdY, but we want Y-m-d for strtotime
		$start_time = strtotime(substr($start, 4, 4).'-'.substr($start, 0, 2).'-'.substr($start, 2, 2));
		$dow = strtoupper(date('D', $start_time));
		$day = date('j', $start_time);
		$mon = date('n', $start_time);

		switch( $frequency ){
			case 'weekly':
				$ret = "? * $dow";
				break;
			case 'biweekly':
				$ret = "? */2 $dow";
				break;
			case 'monthly':
				$ret = "$day * ?";
				break;
			case 'quarterly':
				$ret = "$day $mon/3 ?";
				break;
			case 'semiannually':
				$ret = "$day $mon/6 ?";
				break;
			case 'annual':
				$ret = "$day $mon ?";
				break;
			default:

		}
		return $ret;
	}

	private static function get_local_api_settings($config){
		if(rgar($config["meta"],"api_settings_enabled") == 1)
			$local_api_settings = array(
				"mode"          => $config["meta"]["api_mode"],
				"account_id"    => $config["meta"]["api_account_id"],
				"secret_key"      => $config["meta"]["api_secret_key"],
			);
		else
			$local_api_settings = array();

		return $local_api_settings;
	}

	private static function has_visible_products($form){

		foreach($form["fields"] as $field){
			if($field["type"] == "product" && !RGFormsModel::is_field_hidden($form, $field, ""))
				return true;
		}
		return false;
	}

	private static function get_form_data($form, $config){

		// get products
		$tmp_lead = RGFormsModel::create_lead($form);
		$products = GFCommon::get_product_fields($form, $tmp_lead);

		$form_data = array();

		// getting billing information1225
		$form_data["form_title"] = $form["title"];
		//$form_data["email"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["email"]));
		$form_data["address1"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["address1"]));
		$form_data["address2"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["address2"]));
		$form_data["city"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["city"]));
		$form_data["state"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["state"]));
		$form_data["zip"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["zip"]));
		$form_data["country"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["country"]));
		$form_data["phone"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["phone"]));
		$form_data["memo"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["memo"]));
		$form_data["custom_id1"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["custom_id1"]));
		$form_data["custom_id2"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["custom_id2"]));

		$card_field = self::get_creditcard_field($form);
		if( $card_field && rgpost("input_{$card_field["id"]}_1") && rgpost("input_{$card_field["id"]}_2") && rgpost("input_{$card_field["id"]}_3") && rgpost("input_{$card_field["id"]}_5") ){
			$form_data["card_number"] = rgpost("input_{$card_field["id"]}_1");
			$form_data["expiration_date"] = rgpost("input_{$card_field["id"]}_2");
			$form_data["security_code"] = rgpost("input_{$card_field["id"]}_3");
			$form_data["card_name"] = rgpost("input_{$card_field["id"]}_5");
			$names = explode(" ", $form_data["card_name"]);
			$form_data["first_name"] = rgar($names,0);
			$form_data["last_name"] = "";
			if(count($names) > 0){
				unset($names[0]);
				$form_data["last_name"] = implode(" ", $names);
			}

		}else{

			$form_data["first_name"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["firstname"]));
			$form_data["last_name"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["lastname"]));

			$form_data["account_type"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["account_type"]));
			$form_data["account_number"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["account_number"]));
			$form_data["routing_number"] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["routing_number"]));
		}
		if(!empty($config["meta"]["setup_fee_enabled"]))
			$order_info = self::get_order_info($products, rgar($config["meta"],"recurring_amount_field"), rgar($config["meta"],"setup_fee_amount_field"));
		else
			$order_info = self::get_order_info($products, rgar($config["meta"],"recurring_amount_field"), "");

		$form_data["line_items"] = $order_info["line_items"];
		$form_data["amount"] = $order_info["amount"];
		$form_data["fee_amount"] = $order_info["fee_amount"];

		// need an easy way to filter the the order info as it is not modifiable once it is added to the transaction object
		$form_data = apply_filters("gform_blue_pay_form_data_{$form['id']}", apply_filters('gform_blue_pay_form_data', $form_data, $form, $config), $form, $config);

		return $form_data;
	}

	private static function get_order_info($products, $recurring_field, $setup_fee_field){

		$amount = 0;
		$line_items = array();
		$item = 1;
		$fee_amount = 0;
		foreach($products["products"] as $field_id => $product)
		{


			$quantity = $product["quantity"] ? $product["quantity"] : 1;
			$product_price = GFCommon::to_number($product['price']);

			$options = array();
			if(is_array(rgar($product, "options"))){
				foreach($product["options"] as $option){
					$options[] = $option["option_label"];
					$product_price += $option["price"];
				}
			}

			if(!empty($setup_fee_field) && $setup_fee_field == $field_id)
				$fee_amount += $product_price * $quantity;
			else
			{
				if(is_numeric($recurring_field) && $recurring_field != $field_id)
					continue;

				$amount += $product_price * $quantity;

				$description = "";
				if(!empty($options))
					$description = __("options: ", "gravity-forms-bluepay") . " " . implode(", ", $options);

				if($product_price >= 0){
					$line_items[] = array("item_id" =>'Item ' . $item, "item_name"=>$product["name"], "item_description" =>$description, "item_quantity" =>$quantity, "item_unit_price"=>$product["price"], "item_taxable"=>"Y");
					$item++;
				}
			}
		}

		if(!empty($products["shipping"]["name"]) && !is_numeric($recurring_field)){
			$line_items[] = array("item_id" =>'Item ' . $item, "item_name"=>$products["shipping"]["name"], "item_description" =>"", "item_quantity" =>1, "item_unit_price"=>$products["shipping"]["price"], "item_taxable"=>"Y");
			$amount += $products["shipping"]["price"];
		}

		return array("amount" => $amount, "fee_amount" => $fee_amount, "line_items" => $line_items);
	}

	private static function truncate($text, $max_chars){
		if(strlen($text) <= $max_chars)
			return $text;

		return substr($text, 0, $max_chars);
	}

	public static function blue_pay_entry_info($form_id, $lead) {

	}

	public static function delete_blue_pay_meta() {
		// delete lead meta data
		global $wpdb;
		$table_name = RGFormsModel::get_lead_meta_table_name();
		$wpdb->query("DELETE FROM {$table_name} WHERE meta_key in ('subscription_regular_amount','subscription_trial_duration','subscription_payment_count','subscription_payment_date')");

	}

	public static function uninstall(){
		//loading data lib
		require_once(self::get_base_path() . "/data.php");

		if(!GFBluePay::has_access("gravityforms_blue_pay_uninstall"))
			die(__("You don't have adequate permission to uninstall the BluePay Add-On.", "gravity-forms-bluepay"));

		//droping all tables
		GFBluePayData::drop_tables();

		//removing options
		delete_option("gf_blue_pay_site_name");
		delete_option("gf_blue_pay_auth_token");
		delete_option("gf_blue_pay_version");
		delete_option("gf_blue_pay_settings");

		//delete lead meta data
		self::delete_blue_pay_meta();

		//Deactivating plugin
		$plugin = "gravity-forms-bluepay/blue_pay.php";
		deactivate_plugins($plugin);
		update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
	}

	private static function is_gravityforms_installed(){
		return class_exists("RGForms");
	}

	private static function is_gravityforms_supported(){
		if(class_exists("GFCommon")){
			$is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
			return $is_correct_version;
		}
		else{
			return false;
		}
	}

	protected static function has_access($required_permission){
		$has_members_plugin = function_exists('members_get_capabilities');
		$has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
		if($has_access)
			return $has_members_plugin ? $required_permission : "level_7";
		else
			return false;
	}

	private static function get_customer_information($form, $config=null){

		//getting list of all fields for the selected form
		$form_fields = self::get_form_fields($form);

		$str = "<table cellpadding='0' cellspacing='0'><tr><td class='blue_pay_col_heading'>" . __("BluePay Fields", "gravity-forms-bluepay") . "</td><td class='blue_pay_col_heading'>" . __("Form Fields", "gravity-forms-bluepay") . "</td></tr>";
		$customer_fields = self::get_customer_fields();
		foreach($customer_fields as $field){
			$selected_field = $config ? $config["meta"]["customer_fields"][$field["name"]] : "";
			$str .= "<tr><td class='blue_pay_field_cell'>" . $field["label"]  . "</td><td class='blue_pay_field_cell'>" . self::get_mapped_field_list($field["name"], $selected_field, $form_fields) . "</td></tr>";
		}
		$str .= "</table>";

		return $str;
	}

	private static function get_customer_fields(){
		return
		array(
			array(
				"name" => "firstname" ,
				"label" =>__("Firstname", "gravity-forms-bluepay"),
			),
			array(
				"name" => "lastname" ,
				"label" =>__("Lastname", "gravity-forms-bluepay"),
			),
			array(
				"name" => "address1" ,
				"label" =>__("Address", "gravity-forms-bluepay"),
			),
			array(
				"name" => "address2" ,
				"label" =>__("Address 2", "gravity-forms-bluepay")
			),
			array(
				"name" => "city" ,
				"label" =>__("City", "gravity-forms-bluepay"),
			),
			array(
				"name" => "state" ,
				"label" =>__("State", "gravity-forms-bluepay"),
			),
			array(
				"name" => "zip" ,
				"label" =>__("Zip", "gravity-forms-bluepay"),
			),
			array(
				"name" => "country" ,
				"label" =>__("Country", "gravity-forms-bluepay"),
			),
			array(
				"name" => "email" ,
				"label" =>__("Email", "gravity-forms-bluepay"),
			),
			array(
				"name" => "phone" ,
				"label" =>__("Phone", "gravity-forms-bluepay"),
			),
			array(
				"name" => "account_type" ,
				"label" =>__("Account type", "gravity-forms-bluepay"),
			),
			array(
				"name" => "routing_number" ,
				"label" =>__("Routing number", "gravity-forms-bluepay"),
			),
			array(
				"name" => "account_number" ,
				"label" =>__("Account number", "gravity-forms-bluepay"),
			),
			array(
				"name" => "memo" ,
				"label" =>__("Memo/Comments", "gravity-forms-bluepay"),
			),
			array(
				"name" => "custom_id1" ,
				"label" =>__("Custom ID 1", "gravity-forms-bluepay"),
			),
			array(
				"name" => "custom_id2" ,
				"label" =>__("Custom ID 2", "gravity-forms-bluepay"),
			),
		);
	}

	private static function get_mapped_field_list($variable_name, $selected_field, $fields){
		$field_name = "blue_pay_customer_field_" . $variable_name;
		$str = "<select name='$field_name' id='$field_name'><option value=''></option>";
		foreach($fields as $field){
			$field_id = $field[0];
			$field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

			$selected = $field_id == $selected_field ? "selected='selected'" : "";
			$str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
		}
		$str .= "</select>";
		return $str;
	}

	private static function get_product_options($form, $selected_field, $form_total){
		$str = "<option value=''>" . __("Select a field", "gravity-forms-bluepay") ."</option>";
		$fields = GFCommon::get_fields_by_type($form, array("product"));
		foreach($fields as $field){
			$field_id = $field["id"];
			$field_label = RGFormsModel::get_label($field);

			$selected = $field_id == $selected_field ? "selected='selected'" : "";
			$str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
		}

		if($form_total){
			$selected = $selected_field == 'all' ? "selected='selected'" : "";
			$str .= "<option value='all' " . $selected . ">" . __("Form Total", "gravityformspaypalpro") ."</option>";
		}

		return $str;
	}

	private static function get_form_fields($form){
		$fields = array();

		if(is_array($form["fields"])){
			foreach($form["fields"] as $field){
				if(is_array(rgar($field,"inputs"))){

					foreach($field["inputs"] as $input)
						$fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
				}
				else if(!rgar($field, 'displayOnly')){
						$fields[] =  array($field["id"], GFCommon::get_label($field));
					}
			}
		}
		return $fields;
	}

	private static function subscription_options(){
		return array(
			"weekly"        => __("Weekly (Every 7 Days)", "gravity-forms-bluepay"),
			"biweekly"      => __("Bi-Weekly (Every 14 days)", "gravity-forms-bluepay"),
			"monthly"       => __("Monthly (Every month)", "gravity-forms-bluepay"),
			"quarterly"     => __("Every 3 Months (Quarterly)", "gravity-forms-bluepay"),
			"semiannual"    => __("Every 6 Months (Semi-annually)", "gravity-forms-bluepay"),
			"annual"        => __("Every 12 Months (Yearly)", "gravity-forms-bluepay"),
		);
	}

	private static function is_blue_pay_page(){
		$current_page = trim(strtolower(RGForms::get("page")));
		return in_array($current_page, array("gf_blue_pay"));
	}

	//Returns the url of the plugin's root folder
	private static function get_base_url(){
		return plugins_url(null, __FILE__);
	}

	//Returns the physical path of the plugin's root folder
	private static function get_base_path(){
		$folder = basename(dirname(__FILE__));
		return WP_PLUGIN_DIR . "/" . $folder;
	}

	function set_logging_supported($plugins){
		$plugins[self::$slug] = "BluePay";
		return $plugins;
	}

	private static function log_error($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
		}
	}

	private static function log_debug($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
		}
	}
}


if(!function_exists("rgget")){
	function rgget($name, $array=null){
		if(!isset($array))
			$array = $_GET;

		if(isset($array[$name]))
			return $array[$name];

		return "";
	}
}

if(!function_exists("rgpost")){
	function rgpost($name, $do_stripslashes=true){
		if(isset($_POST[$name]))
			return $do_stripslashes ? stripslashes_deep($_POST[$name]) : $_POST[$name];

		return "";
	}
}

if(!function_exists("rgar")){
	function rgar($array, $name){
		if(isset($array[$name]))
			return $array[$name];

		return '';
	}
}

if(!function_exists("rgars")){
	function rgars($array, $name){
		$names = explode("/", $name);
		$val = $array;
		foreach($names as $current_name){
			$val = rgar($val, $current_name);
		}
		return $val;
	}
}

if(!function_exists("rgempty")){
	function rgempty($name, $array = null){
		if(!$array)
			$array = $_POST;

		$val = rgget($name, $array);
		return empty($val);
	}
}


if(!function_exists("rgblank")){
	function rgblank($text){
		return empty($text) && strval($text) != "0";
	}
}

if(!function_exists("convert_state")){
	function convert_state($key) {
		if(empty($key))
			return '';

		$a2s = array(
			'AL'=>'ALABAMA',
			'AK'=>'ALASKA',
			'AS'=>'AMERICAN SAMOA',
			'AZ'=>'ARIZONA',
			'AR'=>'ARKANSAS',
			'CA'=>'CALIFORNIA',
			'CO'=>'COLORADO',
			'CT'=>'CONNECTICUT',
			'DE'=>'DELAWARE',
			'DC'=>'DISTRICT OF COLUMBIA',
			'FM'=>'FEDERATED STATES OF MICRONESIA',
			'FL'=>'FLORIDA',
			'GA'=>'GEORGIA',
			'GU'=>'GUAM GU',
			'HI'=>'HAWAII',
			'ID'=>'IDAHO',
			'IL'=>'ILLINOIS',
			'IN'=>'INDIANA',
			'IA'=>'IOWA',
			'KS'=>'KANSAS',
			'KY'=>'KENTUCKY',
			'LA'=>'LOUISIANA',
			'ME'=>'MAINE',
			'MH'=>'MARSHALL ISLANDS',
			'MD'=>'MARYLAND',
			'MA'=>'MASSACHUSETTS',
			'MI'=>'MICHIGAN',
			'MN'=>'MINNESOTA',
			'MS'=>'MISSISSIPPI',
			'MO'=>'MISSOURI',
			'MT'=>'MONTANA',
			'NE'=>'NEBRASKA',
			'NV'=>'NEVADA',
			'NH'=>'NEW HAMPSHIRE',
			'NJ'=>'NEW JERSEY',
			'NM'=>'NEW MEXICO',
			'NY'=>'NEW YORK',
			'NC'=>'NORTH CAROLINA',
			'ND'=>'NORTH DAKOTA',
			'MP'=>'NORTHERN MARIANA ISLANDS',
			'OH'=>'OHIO',
			'OK'=>'OKLAHOMA',
			'OR'=>'OREGON',
			'PW'=>'PALAU',
			'PA'=>'PENNSYLVANIA',
			'PR'=>'PUERTO RICO',
			'RI'=>'RHODE ISLAND',
			'SC'=>'SOUTH CAROLINA',
			'SD'=>'SOUTH DAKOTA',
			'TN'=>'TENNESSEE',
			'TX'=>'TEXAS',
			'UT'=>'UTAH',
			'VT'=>'VERMONT',
			'VI'=>'VIRGIN ISLANDS',
			'VA'=>'VIRGINIA',
			'WA'=>'WASHINGTON',
			'WV'=>'WEST VIRGINIA',
			'WI'=>'WISCONSIN',
			'WY'=>'WYOMING',
			'AE'=>'ARMED FORCES AFRICA \ CANADA \ EUROPE \ MIDDLE EAST',
			'AA'=>'ARMED FORCES AMERICA (EXCEPT CANADA)',
			'AP'=>'ARMED FORCES PACIFIC'
		);
		if( strlen($key) != 2 ){
			return $key;
		} else {
			$array = (strlen($key) == 2 ? $a2s : array_flip($a2s));
			return $array[strtoupper($key)];
		}
	}
}
if(!function_exists("convert_country")){
	function convert_country($key) {
		$countries = array(
			'United States' => 'US',
			'Canada' =>'CA',
			'Great Britian' => 'GB',
			'United Kingdom' => 'UK'
		);
		if(array_key_exists($key, $countries)){
			return $countries[$key];
		} else {
			return '';
		}
	}
}

?>
