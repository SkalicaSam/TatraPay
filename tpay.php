<?php
class ControllerExtensionPaymentTpay extends Controller {
	function withoutDiacritic($x){
			$keysToReplace = array(
				"ä"=>"a", "ë"=>"e","ô"=>"o","ű"=>"u","ů"=>"u", "ö"=>"o",
				"Ä"=>"A", "Ë"=>"E","Ô"=>"O", "Ö"=>"O","Ű"=>"U","Ů"=>"U",
			);
			foreach ($keysToReplace as $key => $value) {
				$x = str_replace($key, $value, $x);
			}
			$x = iconv('UTF-8', 'US-ASCII//TRANSLIT', $x);
			$diacricisWords=array("'","´","ˇ","´", "^");
			foreach ($diacricisWords as $key){
				$x = str_replace($key, "", $x);
			}
			return $x;
		}

	public function index() {
		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
		$inputParrams = array();
		$inputParrams["MID"] = $this->config->get('payment_tpay_MID');
		$inputParrams["AMT"] = number_format($order_info["total"], 2, '.', '');
		$currencyToCodeArray = array("EUR"=>978, "CZK"=>203, "USD"=>840, "GBP"=>826, "HUF"=>348, "PLN"=>985, "CHF"=>756, "DKK"=>208);
		$currInWord = $order_info["currency_code"];
		$inputParrams["CURR"] = $currencyToCodeArray[$currInWord];
		$inputParrams["VS"] = $order_info["order_id"];
		//$inputParrams["RURL"] = "http://localhost/opencart-3.0.3.3/upload/index.php?route=extension/payment/tpay/confirm1/";
		$inputParrams["RURL"] = HTTPS_SERVER . "index.php?route=extension/payment/tpay/confirm1/";
		$inputParrams["IPC"] = $order_info["ip"];
		$nameWithDiacritics= $order_info["payment_firstname"]." ".$order_info["payment_lastname"];
		$inputParrams["NAME"] = $this->withoutDiacritic($nameWithDiacritics);
		$inputParrams["REM"] = "online_platby@obchodnik.sk";
	    // $inputParrams["TDS_EMAIL"] = emailova adresa drzitela platby
		$inputParrams["TIMESTAMP"] = gmdate("dmYHis");
		$stringForHmac = "";
		foreach ($inputParrams as $key => $value) {
			$stringForHmac = $stringForHmac . $value;
		}
		$key = $this->config->get('payment_tpay_SECURITY_KEY');
		$keyBytes = pack("H*" , $key);
		$signature = hash_hmac("sha256", $stringForHmac, $keyBytes);
		$inputParrams["HMAC"] = $signature;

		echo "<pre>";
		print_r($inputParrams);

		return $this->load->view('extension/payment/tpayCopyFree', $inputParrams);	
	}

	public function confirm1(){
		// $urlForReturnWithParams = "http://localhost/opencart-3.0.3.3/upload/index.php?route=extension/payment/tpay/confirm1/AMT=20.78&CURR=978&VS=371&RES=OK&AC=832&TID=45678&TIMESTAMP=01112014100000&HMAC=9fbf5a7d1a914d7806a545565b971fa480feb48e402f7a8df80dcea0fdea6049&ECDSA_KEY=1&ECDSA=304502201fb6e376a6b7bb8fe34d931e5e409721c80fb481710dac947cf913a6a3f98f5e022100f1f3066ce4a87cd139742edcd15bdb0c100ccbd7b524e6a1a866d81c273472f7";

		 echo "<pre>";
		$urlString = $_SERVER['QUERY_STRING'];
		$positionForUrlData = strpos($urlString,"confirm1/");
		$urlDataString = substr($urlString, $positionForUrlData + 9);
		$urlDataString2 = $urlDataString;

		$paramsReturned =  $_GET;
		$routeInParamsReturned = $paramsReturned["route"];
		$positionLastSlash = stripos($routeInParamsReturned, "/");
		$startSlice = strlen($paramsReturned["route"]) - $positionLastSlash;
		$newStringFromRoute = substr($paramsReturned["route"], $startSlice);

		$positionEqual1 = strpos($newStringFromRoute, "=");
		$name = substr($newStringFromRoute, 0, $positionEqual1 );
		$value = substr($newStringFromRoute, $positionEqual1 + 1 );
		$paramsReturned[$name]=$value;

		print_r($paramsReturned);

		$HMAC_STRING = $paramsReturned["AMT"] . $paramsReturned["CURR"] . $paramsReturned["VS"] .  $paramsReturned["RES"] . $paramsReturned["AC"] . $paramsReturned["TID"] . $paramsReturned["TIMESTAMP"];

		$SECURITY_KEY = $this->config->get('payment_tpay_SECURITY_KEY');//128 keys given from bank.
		$HMAC_CHECK = hash_hmac('sha256', $HMAC_STRING, $SECURITY_KEY);


		$data = array();
		if ($HMAC_CHECK === $paramsReturned["HMAC"]){
			$data["HMACcheck"] = "HMACcheck is OK";
		}else{
			$data["HMACcheck"] = "Fail on HMACcheck";
		}
		
		$ECDSA_STRING = $paramsReturned["AMT"] . $paramsReturned["CURR"] . $paramsReturned["VS"] .  $paramsReturned["RES"] . $paramsReturned["TID"] . $paramsReturned["TIMESTAMP"] . $paramsReturned["HMAC"] ;

		$file = fopen("https://moja.tatrabanka.sk/e-commerce/ecdsa_keys.txt", "r");
		$fileContent = fread($file,filesize("ecdsa_keys.txt"));
		fclose($file);

		preg_match_all('/(?<keyid>KEY_ID)|(?<status>STATUS)|(?<begin>-----BEGIN PUBLIC)|(END PUBLIC)/', $fileContent, $matches,  PREG_OFFSET_CAPTURE);

		$ReturnedEcdsaKey = intval($paramsReturned["ECDSA_KEY"]);
		// $ReturnedEcdsaKey = 1; //= 1, 2, 3, 

		foreach ($matches[0] as $key => $value){
			if ($value [0] === ('KEY_ID') ){
				$positionOfNuber = $value[1] + strlen("KEY_ID: "); // 0 + strlen(key id)
				$currenKeyId = substr($fileContent, $positionOfNuber, $matches[0][($key + 1 )][1] - $value[1] );
				$currenKeyId = intval($currenKeyId);
				// print_r ($currenKeyId); //= 1, 2, 3,  
				if($currenKeyId === $ReturnedEcdsaKey){
					$statusArrayFind = $matches[0][$key + 1 ];
					$BeginPublicArrayFind = $matches[0][$key + 2];
					$EndPublicArrayFind = $matches[0][$key + 3];
					$statusFinded = substr($fileContent, intval($statusArrayFind[1]) + strlen("STATUS: "), (intval($BeginPublicArrayFind[1])) - (intval($statusArrayFind[1]) + strlen("STATUS: "))) ;
					$publicKeyFindedFromWebFile =  substr($fileContent, intval($BeginPublicArrayFind[1]),(intval($EndPublicArrayFind[1])) + (strlen("END PUBLIC KEY-----")) - (intval($BeginPublicArrayFind[1])));
				}
			}
		}
		
		
		$verified = openssl_verify($ECDSA_STRING, $paramsReturned["ECDSA"], $publicKeyFindedFromWebFile);
		if ($verified === 1) {
			$data["EDSAverify"] = "EDSA verify is OK";
		}else{
			$data["EDSAverify"] = "EDSA verify FAIL";
		}

		if ($verified === 1 and $paramsReturned["RES"] === "OK") {
			$data["paymentMessage"] = "Payment with VS " . $paramsReturned["VS"] . " was successfuly payd.";
			$order_id = $paramsReturned["VS"];
			$order_status_id = 18;  // 18 =  Payment successful, 17 = Payment error
		}
		else { 
			$data["paymentMessage"] = "Payment with VS " . $paramsReturned["VS"] . " wasn't realized !"; 
			$order_id = $paramsReturned["VS"];
			$order_status_id = 17;  // 18 =  Payment successful, 17 = Payment error
		}
		$this->load->model('checkout/order');
		$this->model_checkout_order->addOrderHistory($order_id, $order_status_id);


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
