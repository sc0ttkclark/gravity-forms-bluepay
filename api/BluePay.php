<?php
/***
 * class BluePayment
 * -Added additional fields that were not addressed by API Phone, Email, CustomID 1, Custom ID 2 (Bobby Bush - InDesign Firm, Inc.)
 * -Added function for the processing of ACH Transactions  (Bobby Bush - InDesign Firm, Inc.)
 * 
 *  Module Extended by
 *  The InDesign Firm, Inc.
 *  E-mail: support@indesignfirm.com
 *  Phone: 803-233-2713
 *  Address:  www.indesignfirm.com
 *
 * Written By:
 * Peter Finley 
 * peter.finley@gmail.com
 * 630.730.1178
 * (based on code by Chris Jansen)
 *
 * This class provides the ability to perform credit
 * card transactions through BluePay's v2.0 interface.
 * This is done by performing a POST (using PHP's 
 * CURL wrappers), then recieving and parsing the 
 * response.
 *
 * A few notes:
 *
 * - set tab spacing to 3, for optimal viewing
 *
 * - PAYMENT_TYPE of ACH is not dealt with at all ( NOW IT IS)  :)
 *
 * - Rebilling could be further developed (i.e. 
 * automatically format parameters better, such 
 * as to be able to use UNIX timestamp for the 
 * first date parameter, etc.)
 *
 * - Level 2 qualification is in place, but I'm not 
 * really sure how it is used, so did not do any 
 * more than allow for the parameters to be set.
 *
 * - this class has not been fully tested
 *
 * - there is little to no parameter error 
 * checking (i.e. sending a NAME1 of over 16 
 * characters is allowed, but will yeild an 'E' 
 * (error) STATUS response)
 *
 * - this class is written in PHP 5 (and is _not_ 
 * compatable with any previous versions)
 */
class BluePayment {


	/* merchant supplied parameters */
 protected $accountId; // ACCOUNT_ID
 protected $userId; // USER_ID (optional)
 protected $tps; // TAMPER_PROOF_SEAL
 protected $transType; // TRANS_TYPE (AUTH, SALE, REFUND, or CAPTURE)
 protected $payType; // PAYMENT_TYPE (CREDIT or ACH)
 protected $mode; // MODE (TEST or LIVE)
 protected $masterId; // MASTER_ID (optional)
 protected $secretKey; // used to generate the TPS


 /* customer supplied fields, (not required if
 	MASTER_ID is set) */
 protected $account; // PAYMENT_ACCOUNT (i.e. credit card number)
 protected $cvv2; // CARD_CVVS
 protected $expire; // CARD_EXPIRE 
 protected $ssn; // SSN (Only required for ACH)
 protected $birthdate; // BIRTHDATE (only required for ACH)
 protected $custId; // CUST_ID (only required for ACH)
 protected $custIdState; // CUST_ID_STATE (only required for ACH)
 protected $amount; // AMOUNT
 protected $name1; // NAME1
 protected $name2; // NAME2
 protected $addr1; // ADDR1
 protected $addr2; // ADDR2 (optional)
 protected $city; // CITY
 protected $state; // STATE
 protected $zip; // ZIP
 protected $country; // COUNTRY
 protected $memo; // MEMO (optinal)
 protected $phone; // PHONE (optinal)
 protected $email; // EMAIL (optinal)
 protected $customid1; // CUSTOM_ID1 (optinal)
 protected $customid2; // CUSTOM_ID2 (optinal)

 /* feilds for level 2 qualification */
 protected $orderId; // ORDER_ID
 protected $invoiceId; // INVOICE_ID
 protected $tip; // AMOUNT_TIP
 protected $tax; // AMOUNT_TAX


 /* rebilling (only with trans type of SALE or AUTH) */
 protected $doRebill; // DO_REBILL
 protected $rebDate; // REB_FIRST_DATE
 protected $rebExpr; // REB_EXPR
 protected $rebCycles; // REB_CYCLES
 protected $rebAmount; // REB_AMOUNT


 /* additional fraud scrubbing for an AUTH */
 protected $doAutocap; // DO_AUTOCAP
 protected $avsAllowed; // AVS_ALLOWED
 protected $cvv2Allowed; // CVV2_ALLOWED


 /* bluepay response output */
 protected $response;

 /* parsed response values */
 protected $transId;
 protected $status;
 protected $avsResp;
 protected $cvv2Resp;
 protected $authCode;
 protected $message;
 protected $rebid;



 /* constants */
 const MODE = 'TEST'; // either TEST or LIVE
 const POST_URL = 'https://secure.bluepay.com/interfaces/bp20post'; // the url to post to
 const ACCOUNT_ID = 'INSERT GATEWAY ACCOUNT ID HERE'; // the default account id
 const SECRET_KEY = 'INSERT SECRET KEY HERE'; // the default secret key

 /* STATUS response constants */
 const STATUS_DECLINE = '0'; // DECLINE
 const STATUS_APPROVED = '1'; // APPROVED
 const STATUS_ERROR = 'E'; // ERROR




 /***
 * __construct()
 *
 * Constructor method, sets the account, secret key, 
 * and the mode properties. These will default to 
 * the constant values if not specified.
 */
 public function __construct($account = self::ACCOUNT_ID, 
 	$key = self::SECRET_KEY, $mode = self::MODE) {

 	$this->accountId = $account;
 	$this->secretKey = $key;
 	$this->mode = $mode;
 }




 /***
 * sale()
 *
 * Will perform a SALE transaction with the amount
 * specified.
 */
 public function sale($amount) {

 	$this->transType = "SALE";
 	$this->amount = self::formatAmount($amount);
 }




 /***
 * rebSale()
 *
 * Will perform a sale based on a previous transaction.
 * If the amount is not specified, then it will use
 * the amount of the previous transaction.
 */
 public function rebSale($transId, $amount = null) {

 	$this->masterId = $transId;
 	$this->sale($amount);
 }




 /***
 * auth()
 *
 * Will perform an AUTH transaction with the amount
 * specified.
 */
 public function auth($amount) {

 	$this->transType = "AUTH";
 	$this->amount = self::formatAmount($amount);
 }




 /***
 * autocapAuth()
 *
 * Will perform an auto-capturing AUTH using the
 * provided AVS and CVV2 proofing.
 */
 public function autocapAuth($amount, $avsAllow = null, $cvv2Allow = null) {

 	$this->auth($amount);
 	$this->setAutocap();
 	$this->addAvsProofing($avsAllow);
 	$this->addCvv2Proofing($avsAllow);
 }




 /***
 * addLevel2Qual()
 *
 * Adds additional level 2 qualification parameters.
 */
 public function addLevel2Qual($orderId = null, $invoiceId = null, 
 	$tip = null, $tax = null) {

 	$this->orderId = $orderId;
 	$this->invoiceId = $invoiceId;
 	$this->tip = $tip;
 	$this->tax = $tax;
 }




 /***
 * refund()
 *
 * Will do a refund of a previous transaction.
 */
 public function refund($transId) {

 	$this->transType = "REFUND";
 	$this->masterId = $transId;
 }




 /***
 * capture()
 *
 * Will capture a pending AUTH transaction.
 */
 public function capture($transId) {

 	$this->transType = "CAPTURE";
 	$this->masterId = $transId;
 }




 /***
 * rebAdd()
 *
 * Will add a rebilling cycle.
 */
 public function rebAdd($amount, $date, $expr, $cycles) {

 	$this->doRebill = '1';
 	$this->rebAmount = self::formatAmount($amount);
 	$this->rebDate = $date;
 	$this->rebExpr = $expr;
 	$this->rebCycles = $cycles;
 }




 /***
 * addAvsProofing()
 *
 * Will set which AVS responses are allowed (only
 * applicable when doing an AUTH)
 */
 public function addAvsProofing($allow) {

 	$this->avsAllowed = $allow;
 }




 /***
 * addCvv2Proofing()
 *
 * Will set which CVV2 responses are allowed (only
 * applicable when doing an AUTH)
 */
 public function addCvv2Proofing($allow) {

 	$this->cvv2Allowed = $allow;
 }




 /***
 * setAutocap()
 *
 * Will turn auto-capturing on (only applicable
 * when doing an AUTH)
 */
 public function setAutocap() {

 	$this->doAutocap = '1';
 }

 /***
 * setCustACHInfo()
 *
 * Sets the customer specified info.
 */
 public function setCustACHInfo($routenum, $accntnum, $accttype, $name1, $name2, 
 	$addr1, $city, $state, $zip, $country, $phone, $email, $customid1 = null, $customid2 = null,
 	$addr2 = null, $memo = null) {

 	$this->account = $accttype.":".$routenum.":".$accntnum;
 	$this->payType = 'ACH';
 	$this->name1 = $name1;
 	$this->name2 = $name2;
 	$this->addr1 = $addr1;
 	$this->addr2 = $addr2;
 	$this->city = $city;
 	$this->state = $state;
 	$this->zip = $zip;
 	$this->country = "USA";
 	$this->phone = $phone;
 	$this->email = $email;
 	$this->customid1 = $customid1;
 	$this->customid2 = $customid2;
 	$this->memo = $memo;
 }


 /***
 * setCustInfo()
 *
 * Sets the customer specified info.
 */
 public function setCustInfo($account, $cvv2, $expire, $name1, $name2, 
 	$addr1, $city, $state, $zip, $country, $phone, $email, $customid1 = null, $customid2 = null,
 	$addr2 = null, $memo = null) {

 	$this->account = $account;
 	$this->cvv2 = $cvv2;
 	$this->expire = $expire;
 	$this->name1 = $name1;
 	$this->name2 = $name2;
 	$this->addr1 = $addr1;
 	$this->addr2 = $addr2;
 	$this->city = $city;
 	$this->state = $state;
 	$this->zip = $zip;
 	$this->country = $country;
 	$this->phone = $phone;
 	$this->email = $email;
 	$this->customid1 = null;
 	$this->customid2 = null;
 	$this->memo = $memo;
 }




 /***
 * formatAmount()
 *
 * Will format an amount value to be in the
 * expected format for the POST.
 */
 public static function formatAmount($amount) {

 	return sprintf("%01.2f", (float)$amount);
 }




 /***
 * setOrderId()
 *
 * Sets the ORDER_ID parameter.
 */
 public function setOrderId($orderId) {

 	$this->orderId = $orderId;
 }




 /***
 * calcTPS()
 *
 * Calculates & returns the tamper proof seal md5.
 */
 protected final function calcTPS() {

 	$hashstr = $this->secretKey . $this->accountId . $this->transType . 
 	$this->amount . $this->masterId . $this->name1 . $this->account;

 	return bin2hex( md5($hashstr, true) );
 }

 /***
 * processACH()
 *
 * Will first generate the tamper proof seal, then 
 * populate the POST query, then send it, and store 
 * the response, and finally parse the response.
 */
 public function processACH() {

 	/* calculate the tamper proof seal */
 	$tps = $this->calcTPS();

 	/* fill in the fields */
 	$fields = array (
 		'ACCOUNT_ID' => $this->accountId,
 		'USER_ID' => $this->userId,
 		'TAMPER_PROOF_SEAL' => $tps,
 		'TRANS_TYPE' => $this->transType,
 		'PAYMENT_TYPE' => $this->payType,
 		'MODE' => $this->mode,
 		'MASTER_ID' => $this->masterId,

 		'PAYMENT_ACCOUNT' => $this->account,
 		'SSN' => $this->ssn,
 		'BIRTHDATE' => $this->birthdate,
 		'CUST_ID' => $this->custId,
 		'CUST_ID_STATE' => $this->custIdState,
 		'AMOUNT' => $this->amount,
 		'NAME1' => $this->name1,
 		'NAME2' => $this->name2,
 		'ADDR1' => $this->addr1,
 		'ADDR2' => $this->addr2,
 		'CITY' => $this->city,
 		'STATE' => $this->state,
 		'ZIP' => $this->zip,
 		'PHONE' => $this->phone,
 		'EMAIL' => $this->email,
 		'COUNTRY' => $this->country,
 		'MEMO' => $this->memo,
 		'CUSTOM_ID' => $this->customid1,
 		'CUSTOM_ID2' => $this->customid2,

 		'ORDER_ID' => $this->orderId,
 		'INVOICE_ID' => $this->invoiceId,
 		'AMOUNT_TIP' => $this->tip,
 		'AMOUNT_TAX' => $this->tax,

 		'DO_REBILL' => $this->doRebill,
 		'REB_FIRST_DATE' => $this->rebDate,
 		'REB_EXPR' => $this->rebExpr,
 		'REB_CYCLES' => $this->rebCycles,
 		'REB_AMOUNT' => $this->rebAmount,

 		'CUSTOMER_IP' => $_SERVER['REMOTE_ADDR']
 		);

/* perform the transaction */
$ch = curl_init();

	 curl_setopt($ch, CURLOPT_URL, self::POST_URL); // Set the URL
	 curl_setopt($ch, CURLOPT_USERAGENT, "BluepayPHP SDK/2.0"); // Cosmetic
	 curl_setopt($ch, CURLOPT_POST, 1); // Perform a POST
	 // curl_setopt($ch, CURLOPT_CAINFO, "c:\\windows\\ca-bundle.crt"); // Name of the file to verify the server's cert against
	 curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // Turns off verification of the SSL certificate.
	 curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // If not set, curl prints output to the browser
	 curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));

	 $this->response = curl_exec($ch);

	 curl_close($ch); 

	 /* parse the response */
	 $this->parseResponse();
	}


 /***
 * process()
 *
 * Will first generate the tamper proof seal, then 
 * populate the POST query, then send it, and store 
 * the response, and finally parse the response.
 */
 public function process() {

 	/* calculate the tamper proof seal */
 	$tps = $this->calcTPS();

 	/* fill in the fields */
 	$fields = array (
 		'ACCOUNT_ID' => $this->accountId,
 		'USER_ID' => $this->userId,
 		'TAMPER_PROOF_SEAL' => $tps,
 		'TRANS_TYPE' => $this->transType,
 		'PAYMENT_TYPE' => $this->payType,
 		'MODE' => $this->mode,
 		'MASTER_ID' => $this->masterId,

 		'PAYMENT_ACCOUNT' => $this->account,
 		'CARD_CVV2' => $this->cvv2,
 		'CARD_EXPIRE' => $this->expire,
 		'SSN' => $this->ssn,
 		'BIRTHDATE' => $this->birthdate,
 		'CUST_ID' => $this->custId,
 		'CUST_ID_STATE' => $this->custIdState,
 		'AMOUNT' => $this->amount,
 		'NAME1' => $this->name1,
 		'NAME2' => $this->name2,
 		'ADDR1' => $this->addr1,
 		'ADDR2' => $this->addr2,
 		'CITY' => $this->city,
 		'STATE' => $this->state,
 		'ZIP' => $this->zip,
 		'PHONE' => $this->phone,
 		'EMAIL' => $this->email,
 		'COUNTRY' => $this->country,
 		'MEMO' => $this->memo,
 		'CUSTOM_ID' => $this->customid1,
 		'CUSTOM_ID2' => $this->customid2,

 		'ORDER_ID' => $this->orderId,
 		'INVOICE_ID' => $this->invoiceId,
 		'AMOUNT_TIP' => $this->tip,
 		'AMOUNT_TAX' => $this->tax,

 		'DO_REBILL' => $this->doRebill,
 		'REB_FIRST_DATE' => $this->rebDate,
 		'REB_EXPR' => $this->rebExpr,
 		'REB_CYCLES' => $this->rebCycles,
 		'REB_AMOUNT' => $this->rebAmount,

 		'DO_AUTOCAP' => $this->doAutocap,
 		'AVS_ALLOWED' => $this->avsAllowed,
 		'CVV2_ALLOWED' => $this->cvv2Allowed,

 		'CUSTOMER_IP' => $_SERVER['REMOTE_ADDR']
 		);


/* perform the transaction */
$ch = curl_init();

	 curl_setopt($ch, CURLOPT_URL, self::POST_URL); // Set the URL
	 curl_setopt($ch, CURLOPT_USERAGENT, "BluepayPHP SDK/2.0"); // Cosmetic
	 curl_setopt($ch, CURLOPT_POST, 1); // Perform a POST
	 // curl_setopt($ch, CURLOPT_CAINFO, "c:\\windows\\ca-bundle.crt"); // Name of the file to verify the server's cert against
	 curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // Turns off verification of the SSL certificate.
	 curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // If not set, curl prints output to the browser
	 curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));

	 $this->response = curl_exec($ch);

	 curl_close($ch); 

	 /* parse the response */
	 $this->parseResponse();
	}




 /***
 * parseResponse()
 *
 * This method will parse the response parameter values
 * into the respective properties.
 */
 protected function parseResponse() {

 	parse_str($this->response);


 	/* STATUS */
 	$this->status = $STATUS;

 	/* MESSAGE */
 	$this->message = $MESSAGE;

 	if( 'E' == $STATUS )
 		return;

 	/* TRANS_ID */
 	$this->transId = $TRANS_ID;

 	/* AVS */
 	$this->avsResp = $AVS;

 	/* CVV2 */
 	$this->cvv2Resp = $CVV2;

 	/* AUTH_CODE */
 	$this->authCode = $AUTH_CODE;

 	/* REBID */
 	$this->rebid = $REBID;
 }




 /***
 * get[property]()
 *
 * Getter methods, return the respective property
 * values.
 */
 public function getResponse() { return $this->response; }
 public function getTransId() { return $this->transId; }
 public function getStatus() { return $this->status; }
 public function getAvsResp() { return $this->avsResp; }
 public function getCvv2Resp() { return $this->cvv2Resp; }
 public function getAuthCode() { return $this->authCode; }
 public function getMessage() { return $this->message; }
 public function getRebid() { return $this->rebid; }

}

class BluePayException extends Exception{
}