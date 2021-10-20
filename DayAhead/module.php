<?php

declare(strict_types=1);
	class DayAhead extends IPSModule
	{
		const URL = 'https://norway-power.ffail.win';

		public function Create()
		{
			//Never delete this line!
			parent::Create();

			$this->RegisterPropertyString('Area', 'NO1');
			$this->RegisterPropertyBoolean('SkipSSLCheck', false);
			
			$this->RegisterAttributeString('Day', '');

			$this->RegisterTimer('NorwayPowerRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 

			$this->RegisterMessage(0, IPS_KERNELMESSAGE);
		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
			//Never delete this line!
			parent::ApplyChanges();

			if (IPS_GetKernelRunlevel() == KR_READY) {
				$this->InitTimer();
			}

			$this->HandleData();

		}

		public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
			parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

			if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
				$this->InitTimer();
			}
		}

		public function RequestAction($Ident, $Value) {
			try {
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, (string)$Value), 0);
	
				switch (strtolower($Ident)) {
					case 'refresh':
						$this->Refresh();						
						break;
					default:
						throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
				}
			} catch(Exception $e) {
				$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
			}
		}

		private function Refresh() {
			$this->SetTimerInterval('NorwayPowerRefresh' . (string)$this->InstanceID, 3600*1000);

			HandleData();
		}
		
		private function HandleData() {
			$fetchData = false;

			$data = $this-ReadAttributeString('Day');
			if(strlen($data)>0) {
				$day = json_decode($data);
				
				if(!isset($data->date)) {
					$now = new DateTime('Now');
					$today = $now->format('Y-m-d');

					if($data->date!=$today) {
						$fetchData = true;						
					}
				} else {
					$fetchData = true;
				}
			} else{
				$fetchData = true;
			}

			if($fetchData){
				$jsonPrices = GetDayAheadPrices($this->ReadPropertyString('Area'));
				$receivedPrices = json_decode($jsonPrices);

				$prices = array();
				foreach($receivedPrices => $price) {
					$prices[] = (float)$price->NOK_per_kWh;
				}

				$data = array('date' => $today);
				$data['prices'] = $prices;

				$this->SendDebug(IPS_GetName($this->InstanceID), 'Saving prices...', 0);
				$this->WriteAttributeString('Day', json_encode($data));
			}

			$stats = $this->GetStats($prices);
			
			// Update variables


		}

		private function GetStats($Prices) {
			$this->SendDebug(IPS_GetName($this->InstanceID), 'Calculating statistics...', 0);
			return '';
			
		}

		private function InitTimer() {
			$this->SetTimerInterval('NorwayPowerRefresh' . (string)$this->InstanceID, (self::SecondsToNextHour()+2)*1000); 
		}

		private function SecondsToNextHour() {
			$date = new DateTime('Now');
			$secSinceHour = $date->getTimestamp() % 3600; 
			return (3600 - $secSinceHour);
		}

		private function GetDayAheadPrices(string $Area, $Date=null ) {
			$ch = curl_init();

			$skipSSLCheck = $this->ReadPropertyBoolean('SkipSSLCheck');
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$skipSSLCheck?0:2);//
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$skipSSLCheck);

			if($Date==null) {
				$date = new DateTime('Now');
			} else {
				$date = $Date;
			}

			$params = array('zone' => $this->ReadPropertyString('Area'));
			$params['date'] = $date->Format('Y-m-d');
		
			$url =  Self::URL . '?'  . http_build_query($params);
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true );
						
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Fetching prices. Url is "%s"', $url), 0);

			$result = curl_exec($ch);

			$response = array('httpcode' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
			
			if($result===false) {
				$response = array('success' => false);
				$response['errortext'] = curl_error($ch);

				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Failed to retrieve prices. The error was %s: %s', $response['httpcode'], $responce['errortext'] ), 0);

				return $response;
			} 
			
			$response = array('success' => true);
			$response['result'] = json_decode($result) ;

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Got prices: %s', $result), 0);
			
			return  $response;
		}
	}