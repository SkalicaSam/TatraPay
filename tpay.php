<?php
class ControllerExtensionPaymentTpay extends Controller {
	public function index() {

		function WithoutDiacritic($x){
			$keysToReplace = array(
				"ä"=>"a", "ë"=>"e","ô"=>"o","ű"=>"u","ů"=>"u", "ö"=>"o",
				"Ä"=>"A", "Ë"=>"E","Ô"=>"O", "Ö"=>"O","Ű"=>"U","Ů"=>"U",
			);
			foreach ($keysToReplace as $key => $value) {
				$x = str_replace($key, $value, $x);
			}
			// Because I am too lazy to write all words whose contains: "("'","´","ˇ","´", "^")" charracters. 
			$x = iconv('UTF-8', 'US-ASCII//TRANSLIT', $x);
			$diacricisWords=array("'","´","ˇ","´", "^");
			foreach ($diacricisWords as $key){
				$x = str_replace($key, "", $x);
			}
			return $x;
		}
		
		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
		$inputParrams = array();
		$inputParrams["MID"] = $this->config->get('payment_tpay_MID');
		$inputParrams["ATM"] = number_format($order_info["total"], 2, '.', '');
		$currencyToCodeArray = array("EUR"=>978, "CZK"=>203, "USD"=>840, "GBP"=>826, "HUF"=>348, "PLN"=>985, "CHF"=>756, "DKK"=>208);
		$currInWord = $order_info["currency_code"];
		$inputParrams["CURR"] = $currencyToCodeArray[$currInWord];
		$inputParrams["VS"] = $order_info["order_id"];
		$inputParrams["REM"] = "online_platby@obchodnik.sk";
		$inputParrams["RURL"] = "http://localhost/opencart-3.0.3.3/upload/index.php?route=extension/payment/tpay/confirm1/";
		$inputParrams["IPC"] = $order_info["ip"];
		$nameWithDiacritics= $order_info["payment_firstname"]." ".$order_info["payment_lastname"];
		$inputParrams["NAME"] = WithoutDiacritic($nameWithDiacritics);
		$inputParrams["TIMESTAMP"] = gmdate("dmYHis");
		$stringForHmac = "";
		foreach ($inputParrams as $key => $value) {
			$stringForHmac = $stringForHmac . $value;
		}
		$key = $this->config->get('payment_tpay_SECURITY_KEY');
		$keyBytes = pack("H*" , $key);
		$signature = hash_hmac("sha256", $stringForHmac, $keyBytes);
		$inputParrams["HMAC"] = $signature;

		//echo "<pre>";
		//print_r($inputParrams);

		return $this->load->view('extension/payment/tpayCopyFree', $inputParrams);	
	}

	public function confirm1(){
		// $urlForReturnWithParams = "http://localhost/opencart-3.0.3.3/upload/index.php?route=extension/payment/tpay/confirm1/AMT=20.78&CURR=978&VS=371&RES=OK&AC=832&TID=45678&TIMESTAMP=01112014100000&HMAC=9fbf5a7d1a914d7806a545565b971fa480feb48e402f7a8df80dcea0fdea6049&ECDSA_KEY=1&ECDSA=304502201fb6e376a6b7bb8fe34d931e5e409721c80fb481710dac947cf913a6a3f98f5e022100f1f3066ce4a87cd139742edcd15bdb0c100ccbd7b524e6a1a866d81c273472f7";

		$urlString = $_SERVER['QUERY_STRING'];
		$positionForUrlData = strpos($urlString,"confirm1/");
		$urlDataString = substr($urlString, $positionForUrlData + 9);
		
		$paramsReturned = array();
		while (strpos($urlDataString, '=') !== FALSE ) {
			$positionEqual = strpos($urlDataString, "=");
			$name = substr($urlDataString, 0, $positionEqual );
			$urlDataString = substr($urlDataString, $positionEqual + 1);
			if (strpos($urlDataString, "&") !== FALSE ) {
				$positionAnd =  strpos($urlDataString, "&");
				$value = substr($urlDataString, 0, $positionAnd);
				$urlDataString = substr($urlDataString, $positionAnd + 1); 
			}
			else{
				$value = $urlDataString;
			}
			$paramsReturned[$name]=$value;
		}

		$HMAC_STRING = "";
		foreach ($paramsReturned as $i => $value ) {
			if ($i == "HMAC" ) {
				break;
			}
			$HMAC_STRING = $HMAC_STRING . $value ;
		}
		//$HMAC_STRING = $paramsReturned["AMT"] . $paramsReturned["CURR"] . $paramsReturned["VS"] .  $paramsReturned["RES"] . $paramsReturned["AC"] . $paramsReturned["TID"] . $paramsReturned["TIMESTAMP"];

		$SECURITY_KEY = $this->config->get('payment_tpay_SECURITY_KEY');//128 keys given from bank.
		$HMAC_CHECK = hash_hmac('sha256', $HMAC_STRING, $SECURITY_KEY);

		$data = array();
		if ($HMAC_CHECK === $paramsReturned["HMAC"]){
			$data["HMACcheck"] = "HMACcheck is OK";
		}else{
			$data["HMACcheck"] = "Fail on HMACcheck";
		}
		
		$ECDSA_STRING = "";
		foreach ($paramsReturned as $i => $value ) {
			if ($i == "ECDSA_KEY" ) {
				break;
			}
			$ECDSA_STRING = $ECDSA_STRING . $value ;
		}

		$file = fopen("https://moja.tatrabanka.sk/e-commerce/ecdsa_keys.txt", "r");
		$fileContent = fread($file,filesize("ecdsa_keys.txt"));
		fclose($file);

		$publicKeyMap = array();
		while (strpos($fileContent,"KEY_ID:") !== FALSE ) {
				$positionKeyId = strpos($fileContent,"KEY_ID:");
				$key_id = substr($fileContent, $positionKeyId + 8,1);
				$positionStatus = strpos($fileContent,"STATUS:");
				$status = substr($fileContent, $positionStatus + 8,5);
				$positionPublicKey = strpos($fileContent,"-----BEGIN PUBLIC KEY");
				$lenghtOfPublicKey = strlen("-----BEGIN PUBLIC KEY-----") + 126 + strlen("-----END PUBLIC KEY-----") + 5 ;
				$publicKey = substr($fileContent, $positionPublicKey, $lenghtOfPublicKey);
				$numberToSlice = $positionPublicKey + $lenghtOfPublicKey;
				$fileContent = substr($fileContent, $numberToSlice);

				$publicKeyMap[$key_id] = array("status" =>$status, "publicKey" =>$publicKey);
		}
	    
		$ECDSA_key_returned =  $paramsReturned["ECDSA_KEY"];
		$selectedFromArrayPublicKeyMap = $publicKeyMap[$ECDSA_key_returned];
		$ecdsaPublicKey = $selectedFromArrayPublicKeyMap["publicKey"];
		
		$verified = openssl_verify($ECDSA_STRING, $paramsReturned["ECDSA"], $ecdsaPublicKey);
		if ($verified === 1) {
			$data["EDSAverify"] = "EDSA verify is OK";
		}else{
			$data["EDSAverify"] = "EDSA verify FAIL";
		}

		if ($paramsReturned["RES"] === "OK") {
			$data["paymentMessage"] = "Payment with VS " . $paramsReturned["VS"] . " was successfuly payd.";
		}
		else { 
			$data["paymentMessage"] = "Payment with VS " . $paramsReturned["VS"] . " wasn't realized !"; 
		}
 
		$this->document->setTitle($this->config->get('config_meta_title'));
		$this->document->setDescription($this->config->get('config_meta_description'));
		$this->document->setKeywords($this->config->get('config_meta_keyword'));
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');
		

		$this->response->setOutput($this->load->view('product/tpayConfirm1', $data));
	}
}
