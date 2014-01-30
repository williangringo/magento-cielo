<?php
class Cammino_Cielo_Model_Default extends Mage_Payment_Model_Method_Abstract {
	
	protected $_canAuthorize = true;
	protected $_canCapture = true;
	protected $_code = 'cielo_default';
	protected $_formBlockType = 'cielo/form';
	protected $_infoBlockType = 'cielo/info';

    public function assignData($data) {

		if (!($data instanceof Varien_Object)) {
			$data = new Varien_Object($data);
		}

		$info = $this->getInfoInstance();
		$info->setAdditionalData(serialize($data));
		
        return $this;
    }

    public function getOrderPlaceRedirectUrl() {
		return Mage::getUrl('cielo/default/pay');
	}

	public function generateXml($orderId) {
		
		$url_return_default = Mage::getUrl('cielo/default/receipt/id/'.$orderId);
		$order = Mage::getModel("sales/order");
		$order->loadByIncrementId($orderId);

		// get payment and add data
		$payment = $order->getPayment();
		$addata = unserialize($payment->getData("additional_data"));
		
		$customer = Mage::getModel("customer/customer");
		$customer->load($order->getCustomerId());
		$billingAddress = $order->getBillingAddress();

		// default for operation
		$cieloNumber = $this->getConfigdata("cielo_number");
		$cieloKey 	 = $this->getConfigdata("cielo_key");
		$cieloAuthTrans = $this->getConfigdata("cielo_auth_transition") ? $this->getConfigdata("cielo_auth_transition") : 3;
		$cieloRetUrl    = $this->getConfigdata("cielo_url_return") ? $this->getConfigdata("cielo_url_return") : $url_return_default;
		$cieloCapture   = $this->getConfigdata("cielo_capture") ? $this->getConfigdata("cielo_capture"):'false';
		$cieloDesc 	    = $this->getConfigdata("cielo_description") ? $this->getConfigdata("cielo_description") : '';
		$cieloToken     = $this->getConfigdata("cielo_token") ? $this->getConfigdata("cielo_token") : 'false';
		$cieloPlotsType	= $this->getConfigdata("cielo_plots_type") ? $this->getConfigdata("cielo_plots_type") : 'A';
		
		// payment
		$payMethod   = $addata->_data['cielo_type']; // 1- Credit Card / A- Debit Card / 3 - Credit card plots
		$card 		 = $addata->_data['cielo_card']; // visa, master, elo
		$plots		 = $addata->_data['cielo_plots']; // 1x, 3x, 6x, 12x, 18x, 36x, 56x.

		if (strval($payMethod) == "A") {
			$cieloAuthTrans = 1;
		}

		if (strval($payMethod) == "3") {
			$payMethod = (strval($cieloPlotsType) == "L") ? "2" : "3";
		}

		// order
		$orderData = str_replace(' ', 'T', $order->_data['created_at']);
		$orderTotal = number_format($order->getTotalDue(), 2, "", "");
		$orderCode = $orderId;
		$orderIp   = $_SERVER["REMOTE_ADDR"];
		
		$xml = '';

		$xml  = '<?xml version="1.0" encoding="ISO-8859-1"?>';
		$xml .= '<requisicao-transacao id="'.$orderId.'" versao="1.2.1">';
    	$xml .= '<dados-ec><numero>'.$cieloNumber.'</numero><chave>'.$cieloKey.'</chave></dados-ec>';
    	$xml .= '<dados-pedido> 
    				<numero>'.$orderId.'</numero>
    				<valor>'.$orderTotal.'</valor>
    				<moeda>986</moeda> 
    				<data-hora>'.$orderData.'</data-hora> 
    				<descricao>[origem:'.$orderIp.']</descricao> 
    				<idioma>PT</idioma> 
    				<soft-descriptor></soft-descriptor>
				</dados-pedido>';
		$xml .= '<forma-pagamento>
    				<bandeira>'.$card.'</bandeira> 
    				<produto>'.$payMethod.'</produto>
    				<parcelas>'.$plots.'</parcelas> 
				 </forma-pagamento>';
		$xml .= '<url-retorno>'.$cieloRetUrl.'</url-retorno> 
    			<autorizar>'.$cieloAuthTrans.'</autorizar>
    			<capturar>'.$cieloCapture.'</capturar> 
    			<campo-livre>'.$cieloDesc.'</campo-livre> 
    			<gerar-token>'.$cieloToken.'</gerar-token>';
		$xml .= '</requisicao-transacao>';		

		return $xml;
	}
	
	public function generateXmlReceipt($orderId)
	{
		$cieloNumber = $this->getConfigData('cielo_number');
		$cieloKey = $this->getConfigData('cielo_key');

		$xml = '<?xml version="1.0" encoding="UTF-8"?>
			<requisicao-consulta-chsec id="'.$orderId.'" versao="1.2.1"> <numero-pedido>'.$orderId.'</numero-pedido>
				<dados-ec>
					<numero>'.$cieloNumber.'</numero> <chave>'.$cieloKey.'</chave>
				</dados-ec>
			</requisicao-consulta-chsec>';

		return $xml;
	}

	public function sendXml($orderId, $type = 'pay')
	{
		if ($type == 'pay') { $string = $this->generateXml($orderId); }
		if ($type == 'receipt') { $string = $this->generateXmlReceipt($orderId); }

		if($this->getConfigdata("environment") == 'test'){
    		//Ambiente de testes
    		$url = 'https://qasecommerce.cielo.com.br/servicos/ecommwsec.do';
		}else{
    		//Ambiente de produção
    		$url = 'https://ecommerce.cbmp.com.br/servicos/ecommwsec.do';
		}

	    $ch = curl_init();
	    flush();
	    
	    curl_setopt($ch, CURLOPT_POST, 1);
	    curl_setopt($ch, CURLOPT_POSTFIELDS,  'mensagem=' . $string);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($ch, CURLOPT_URL, $url);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	    curl_setopt($ch, CURLOPT_FAILONERROR, true);
	    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
	    
	    $string = curl_exec($ch);
	    curl_close($ch);
	    
	    $xml = simplexml_load_string($string);

	    return $xml;
	}
}